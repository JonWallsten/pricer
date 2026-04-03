import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**  Project root directory (two levels up from scripts/lib/) */
export const PROJECT_ROOT = resolve(__dirname, '..', '..');

/** Default path to .credentials.env */
export const DEFAULT_CREDENTIALS_PATH = resolve(PROJECT_ROOT, '.credentials.env');

/** Default path to .credentials.local.env */
export const DEFAULT_LOCAL_CREDENTIALS_PATH = resolve(PROJECT_ROOT, '.credentials.local.env');

/** Default path to .ftp.env */
export const DEFAULT_FTP_PATH = resolve(PROJECT_ROOT, '.ftp.env');

/**
 * Parse a KEY=VALUE env-file string into a plain object.
 * Handles comments (#), blank lines, and values containing '='.
 * Strips optional surrounding quotes from values.
 */
export function parseCredentials(content) {
    const result = {};
    for (const raw of content.split('\n')) {
        const line = raw.trim();
        if (!line || line.startsWith('#')) continue;
        const eqIndex = line.indexOf('=');
        if (eqIndex === -1) continue;
        const key = line.slice(0, eqIndex).trim();
        let value = line.slice(eqIndex + 1).trim();
        // Strip matched surrounding quotes
        if (
            value.length >= 2 &&
            ((value.startsWith('"') && value.endsWith('"')) ||
                (value.startsWith("'") && value.endsWith("'")))
        ) {
            value = value.slice(1, -1);
        }
        if (key) result[key] = value;
    }
    return result;
}

/**
 * Load credentials from a KEY=VALUE file on disk.
 * @param {string} [filePath] Defaults to PROJECT_ROOT/.credentials.env
 */
export function loadCredentials(filePath = DEFAULT_CREDENTIALS_PATH) {
    const content = readFileSync(filePath, 'utf-8');
    return parseCredentials(content);
}

/**
 * Load app credentials with optional local overlay.
 * Loads .credentials.env, then overlays .credentials.local.env if it exists.
 * Pass `overlay: false` to skip the local overlay (e.g. for production deploy).
 */
export function loadAppCredentials({ overlay = true } = {}) {
    const creds = loadCredentials(DEFAULT_CREDENTIALS_PATH);
    if (overlay) {
        try {
            const localContent = readFileSync(DEFAULT_LOCAL_CREDENTIALS_PATH, 'utf-8');
            const localCreds = parseCredentials(localContent);
            Object.assign(creds, localCreds);
        } catch {
            // .credentials.local.env doesn't exist — that's fine
        }
    }
    return creds;
}

/**
 * Load FTP credentials from .ftp.env.
 */
export function loadFtpCredentials(filePath = DEFAULT_FTP_PATH) {
    const content = readFileSync(filePath, 'utf-8');
    return parseCredentials(content);
}

/**
 * Validate that every required key exists and is non-empty.
 * Throws with a descriptive message listing all missing keys.
 */
export function validateCredentials(creds, requiredKeys) {
    const missing = requiredKeys.filter((k) => !creds[k]);
    if (missing.length > 0) {
        throw new Error(
            `Missing required credentials: ${missing.join(', ')}\n` +
                'Fill them in .credentials.env (copy .credentials.env.example as a template).',
        );
    }
}
