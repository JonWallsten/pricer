#!/usr/bin/env node

import { readdirSync, readFileSync } from "node:fs";
import { join, basename } from "node:path";
import mysql from "mysql2/promise";
import {
  loadAppCredentials,
  validateCredentials,
  PROJECT_ROOT,
} from "./lib/credentials.mjs";

const MIGRATIONS_DIR = join(PROJECT_ROOT, "scripts", "migrations");
const REQUIRED_CREDS = ["DB_HOST", "DB_NAME", "DB_USER", "DB_PASS"];

// ── Public helpers (exported for testing) ─────────────────

/**
 * Return sorted list of migration SQL filenames from the migrations dir.
 */
export function getMigrationFiles(dir = MIGRATIONS_DIR) {
  return readdirSync(dir)
    .filter((f) => f.endsWith(".sql"))
    .sort();
}

/**
 * Parse a migration filename like '003_create_flour_blends.sql'
 * and return { sequence: 3, name: 'create_flour_blends' }.
 */
export function parseMigrationFilename(filename) {
  const match = filename.match(/^(\d+)[_-](.+)\.sql$/);
  if (!match) return null;
  return { sequence: parseInt(match[1], 10), name: match[2] };
}

// ── Database helpers ──────────────────────────────────────

async function getConnection(creds) {
  return mysql.createConnection({
    host: creds.DB_HOST,
    user: creds.DB_USER,
    password: creds.DB_PASS,
    database: creds.DB_NAME,
    multipleStatements: true,
    charset: "utf8mb4",
  });
}

async function ensureMigrationsTable(conn) {
  await conn.query(`
    CREATE TABLE IF NOT EXISTS _migrations (
      filename VARCHAR(255) NOT NULL PRIMARY KEY,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `);
}

async function getAppliedMigrations(conn) {
  const [rows] = await conn.query(
    "SELECT filename, applied_at FROM _migrations ORDER BY filename",
  );
  return rows;
}

async function isApplied(conn, filename) {
  const [rows] = await conn.query(
    "SELECT COUNT(*) AS cnt FROM _migrations WHERE filename = ?",
    [filename],
  );
  return rows[0].cnt > 0;
}

async function applyMigration(conn, filename) {
  const sql = readFileSync(join(MIGRATIONS_DIR, filename), "utf-8");
  await conn.query(sql);
  await conn.query("INSERT INTO _migrations (filename) VALUES (?)", [filename]);
}

// ── Commands ──────────────────────────────────────────────

async function showStatus(conn) {
  const applied = await getAppliedMigrations(conn);
  const files = getMigrationFiles();

  console.log("Applied migrations:");
  if (applied.length === 0) {
    console.log("  (none)");
  } else {
    for (const row of applied) {
      console.log(`  ✓ ${row.filename}  (${row.applied_at})`);
    }
  }

  const pending = files.filter((f) => !applied.some((a) => a.filename === f));
  console.log("\nPending:");
  if (pending.length === 0) {
    console.log("  (none)");
  } else {
    for (const f of pending) {
      console.log(`  ○ ${f}`);
    }
  }
}

async function runMigrations(conn) {
  const files = getMigrationFiles();
  let appliedCount = 0;
  let skippedCount = 0;

  for (const filename of files) {
    if (await isApplied(conn, filename)) {
      skippedCount++;
      continue;
    }

    console.log(`Applying: ${filename}`);
    await applyMigration(conn, filename);
    appliedCount++;
  }

  console.log(
    `\nDone. Applied: ${appliedCount}, Skipped (already applied): ${skippedCount}`,
  );
}

// ── Main ──────────────────────────────────────────────────

async function main() {
  const remote = process.argv.includes("--remote");
  const creds = loadAppCredentials({ overlay: !remote });
  validateCredentials(creds, REQUIRED_CREDS);

  const conn = await getConnection(creds);

  try {
    await ensureMigrationsTable(conn);

    if (process.argv.includes("--status")) {
      await showStatus(conn);
    } else {
      await runMigrations(conn);
    }
  } finally {
    await conn.end();
  }
}

// Only run when executed directly, not when imported for testing
if (
  process.argv[1] &&
  import.meta.url.endsWith(process.argv[1].replace(/\\/g, "/"))
) {
  main().catch((err) => {
    console.error("Error:", err.message);
    process.exit(1);
  });
}
