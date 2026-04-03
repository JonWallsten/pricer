#!/usr/bin/env node

import { execSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { Client } from 'basic-ftp';
import { loadFtpCredentials, validateCredentials, PROJECT_ROOT } from './lib/credentials.mjs';

// ── Flag parsing ──────────────────────────────────────────

export function parseDeployFlags(argv) {
    const flags = {
        dryRun: false,
        apiOnly: false,
        frontendOnly: false,
        credentialsOnly: false,
    };
    for (const arg of argv) {
        switch (arg) {
            case '--dry-run':
                flags.dryRun = true;
                break;
            case '--api-only':
                flags.apiOnly = true;
                break;
            case '--frontend-only':
                flags.frontendOnly = true;
                break;
            case '--credentials-only':
                flags.credentialsOnly = true;
                break;
            default:
                throw new Error(`Unknown flag: ${arg}`);
        }
    }
    return flags;
}

// ── Recursive upload with exclusion ───────────────────────

async function uploadDir(client, localDir, remoteDir, excludes = []) {
    await client.ensureDir(remoteDir);
    await client.cd('/');
    const entries = readdirSync(localDir, { withFileTypes: true });

    for (const entry of entries) {
        if (excludes.includes(entry.name)) continue;

        const localPath = join(localDir, entry.name);
        const remotePath = `${remoteDir}/${entry.name}`;

        if (entry.isDirectory()) {
            await uploadDir(client, localPath, remotePath, excludes);
        } else {
            const rel = relative(PROJECT_ROOT, localPath);
            console.log(`  ↑ ${rel}`);
            await client.uploadFrom(localPath, remotePath);
        }
    }
}

/** Delete remote entries that don't exist locally (recursive). */
/** Server-managed files/dirs that should never be deleted. */
const SKIP_REMOTE = new Set(['.ftpquota', '.htpasswd']);

async function deleteExtra(client, localDir, remoteDir, excludes = []) {
    let remoteList;
    try {
        remoteList = await client.list(remoteDir);
    } catch {
        return; // directory doesn't exist remotely
    }

    const localNames = new Set(readdirSync(localDir, { withFileTypes: true }).map((e) => e.name));

    for (const entry of remoteList) {
        if (excludes.includes(entry.name) || SKIP_REMOTE.has(entry.name)) continue;
        if (localNames.has(entry.name)) {
            // If it's a directory in both, recurse
            if (entry.isDirectory) {
                const localPath = join(localDir, entry.name);
                const remotePath = `${remoteDir}/${entry.name}`;
                await deleteExtra(client, localPath, remotePath, excludes);
            }
            continue;
        }

        const remotePath = `${remoteDir}/${entry.name}`;
        console.log(`  ✕ ${remotePath}`);
        try {
            if (entry.isDirectory) {
                await client.removeDir(remotePath);
            } else {
                await client.remove(remotePath);
            }
        } catch (err) {
            console.warn(`  ⚠ Could not delete ${remotePath}: ${err.message}`);
        }
    }
}

// ── Deploy functions ──────────────────────────────────────

async function deployFrontend(client, ftpPath, dryRun) {
    console.log('📦  Uploading frontend...');
    const localDir = join(PROJECT_ROOT, 'dist', 'pricer', 'browser');
    if (dryRun) {
        listLocalFiles(localDir);
        return;
    }
    await uploadDir(client, localDir, ftpPath);
    await deleteExtra(client, localDir, ftpPath, ['api', '.credentials.env']);
}

async function deployApi(client, ftpPath, dryRun) {
    console.log('📦  Uploading API...');
    const localDir = join(PROJECT_ROOT, 'api');
    const remoteDir = `${ftpPath}/api`;
    if (dryRun) {
        listLocalFiles(localDir, ['uploads']);
        return;
    }
    await uploadDir(client, localDir, remoteDir, ['uploads']);
    await deleteExtra(client, localDir, remoteDir, ['uploads']);
}

function listLocalFiles(dir, excludes = [], prefix = '') {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (excludes.includes(entry.name)) continue;
        const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
        if (entry.isDirectory()) {
            listLocalFiles(join(dir, entry.name), excludes, rel);
        } else {
            console.log(`  (dry-run) ${rel}`);
        }
    }
}

// ── Main ──────────────────────────────────────────────────

async function deployCredentials(client, ftpPath) {
    console.log('🔑  Uploading .credentials.env...');
    const localPath = join(PROJECT_ROOT, '.credentials.env');
    const remotePath = `${ftpPath}/.credentials.env`;
    const rel = relative(PROJECT_ROOT, localPath);
    console.log(`  ↑ ${rel}`);
    await client.uploadFrom(localPath, remotePath);
}

async function main() {
    const flags = parseDeployFlags(process.argv.slice(2));
    const creds = loadFtpCredentials();
    validateCredentials(creds, ['FTP_HOST', 'FTP_USER', 'FTP_PASS', 'FTP_PATH']);

    if (flags.dryRun) {
        console.log('🔍  Dry run — no files will be transferred');
    }

    // Build frontend (unless --api-only or --credentials-only)
    if (!flags.apiOnly && !flags.credentialsOnly) {
        console.log('🔨  Building Angular app...');
        execSync('npm run build', { cwd: PROJECT_ROOT, stdio: 'inherit' });
    }

    const client = new Client();
    // Uncomment for FTP debugging: client.ftp.verbose = true;

    try {
        console.log(`🚀  Deploying to ${creds.FTP_HOST}...`);

        if (!flags.dryRun) {
            await client.access({
                host: creds.FTP_HOST,
                user: creds.FTP_USER,
                password: creds.FTP_PASS,
                secure: true,
                // Shared hosts often have self-signed or mismatched certs
                secureOptions: { rejectUnauthorized: false },
            });
        }

        if (flags.credentialsOnly) {
            await deployCredentials(client, creds.FTP_PATH);
        } else if (flags.frontendOnly) {
            await deployFrontend(client, creds.FTP_PATH, flags.dryRun);
        } else if (flags.apiOnly) {
            await deployApi(client, creds.FTP_PATH, flags.dryRun);
        } else {
            await deployFrontend(client, creds.FTP_PATH, flags.dryRun);
            await deployApi(client, creds.FTP_PATH, flags.dryRun);
        }

        console.log('✅  Deploy complete!');
    } finally {
        client.close();
    }

    // Post-deploy smoke test (skip for dry-run and credentials-only)
    if (!flags.dryRun && !flags.credentialsOnly) {
        await smokeTest();
    }
}

const PROD_API = 'https://jonwallsten.com/pricer/api';

async function smokeTest() {
    console.log('🧪  Running post-deploy smoke test...');
    const checks = [
        { name: 'GET /auth/config → 200', path: '/auth/config', method: 'GET', expect: 200 },
        { name: 'POST /products (no auth) → 401', path: '/products', method: 'POST', expect: 401 },
        { name: 'POST /products/1/check (no auth) → 401', path: '/products/1/check', method: 'POST', expect: 401 },
    ];

    let passed = 0;
    for (const check of checks) {
        try {
            const res = await fetch(`${PROD_API}${check.path}`, {
                method: check.method,
                headers: { 'Content-Type': 'application/json' },
            });
            if (res.status === check.expect) {
                console.log(`  ✓ ${check.name}`);
                passed++;
            } else {
                console.error(`  ✗ ${check.name} — got ${res.status} (expected ${check.expect})`);
            }
        } catch (err) {
            console.error(`  ✗ ${check.name} — ${err.message}`);
        }
    }

    if (passed === checks.length) {
        console.log('🧪  All smoke tests passed!');
    } else {
        console.error(`🧪  ${checks.length - passed}/${checks.length} smoke tests FAILED!`);
        process.exit(1);
    }
}

// Only run when executed directly, not when imported for testing
if (process.argv[1] && import.meta.url.endsWith(process.argv[1].replace(/\\/g, '/'))) {
    main().catch((err) => {
        console.error('❌ ', err.message);
        process.exit(1);
    });
}
