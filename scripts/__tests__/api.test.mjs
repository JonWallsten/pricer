/**
 * API integration tests.
 *
 * Requires:
 *   - PHP dev server:  npm run start:api   (localhost:8080)
 *   - Local DB accessible via .credentials.env / .credentials.local.env
 *
 * Run with:  npm run test:api
 */

import { describe, it, expect, beforeAll, afterAll, beforeEach } from "vitest";
import { createHmac } from "node:crypto";
import mysql from "mysql2/promise";
import { loadAppCredentials, PROJECT_ROOT } from "../lib/credentials.mjs";

// ── Config ────────────────────────────────────────────────

const BASE = process.env.API_BASE_URL ?? "http://localhost:8080";

// ── JWT helper (mirrors PHP auth.php logic) ───────────────

function b64url(buf) {
  return buf
    .toString("base64")
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}

function makeJwt(userId, email, secret) {
  const header = b64url(
    Buffer.from(JSON.stringify({ alg: "HS256", typ: "JWT" })),
  );
  const now = Math.floor(Date.now() / 1000);
  const payload = b64url(
    Buffer.from(
      JSON.stringify({ user_id: userId, email, iat: now, exp: now + 3600 }),
    ),
  );
  const sig = b64url(
    createHmac("sha256", secret).update(`${header}.${payload}`).digest(),
  );
  return `${header}.${payload}.${sig}`;
}

// ── HTTP helpers ──────────────────────────────────────────

async function api(method, path, { body, token } = {}) {
  const headers = { "Content-Type": "application/json" };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* empty body */
  }
  return { status: res.status, data };
}

// ── Test state ────────────────────────────────────────────

let db;
let creds;
let testUserId;
let jwt;

const TEST_GOOGLE_ID = "test-integration-user-99999";
const TEST_EMAIL = "test-integration@pricer.test";
const TEST_NAME = "Integration Test User";

// ── Setup / Teardown ──────────────────────────────────────

beforeAll(async () => {
  creds = loadAppCredentials();

  db = await mysql.createConnection({
    host: creds.DB_HOST,
    user: creds.DB_USER,
    password: creds.DB_PASS,
    database: creds.DB_NAME,
    charset: "utf8mb4",
  });

  // Clean up any leftover test user from a previous run
  await db.query("DELETE FROM users WHERE google_id = ?", [TEST_GOOGLE_ID]);

  // Insert a fresh test user
  const [result] = await db.query(
    "INSERT INTO users (google_id, email, name) VALUES (?, ?, ?)",
    [TEST_GOOGLE_ID, TEST_EMAIL, TEST_NAME],
  );
  testUserId = result.insertId;

  jwt = makeJwt(testUserId, TEST_EMAIL, creds.JWT_SECRET);
});

afterAll(async () => {
  if (db) {
    // Cascade: baking_sessions and recipes reference user_id
    await db.query("DELETE FROM users WHERE id = ?", [testUserId]);
    await db.end();
  }
});

// ── Public routes ─────────────────────────────────────────

describe("GET /auth/config", () => {
  it("returns 200 with google_client_id", async () => {
    const { status, data } = await api("GET", "/auth/config");
    expect(status).toBe(200);
    expect(typeof data.google_client_id).toBe("string");
    expect(data.google_client_id.length).toBeGreaterThan(0);
  });
});

describe("POST /auth/google with invalid token", () => {
  it("returns 401", async () => {
    const { status } = await api("POST", "/auth/google", {
      body: { token: "not-a-real-google-token" },
    });
    expect(status).toBe(401);
  });

  it("returns 400 when token is missing", async () => {
    const { status } = await api("POST", "/auth/google", { body: {} });
    expect(status).toBe(400);
  });
});

// ── Auth guard ─────────────────────────────────────────────

describe("Auth required — no token", () => {
  it("GET /example → 401", async () => {
    const { status } = await api("GET", "/recipes");
    expect(status).toBe(401);
  });
});

// ── GET /auth/me ───────────────────────────────────────────

describe("GET /auth/me", () => {
  it("returns the authenticated user", async () => {
    const { status, data } = await api("GET", "/auth/me", { token: jwt });
    expect(status).toBe(200);
    expect(data.user.id).toBe(testUserId);
    expect(data.user.email).toBe(TEST_EMAIL);
  });

  it("returns 401 with no token", async () => {
    const { status } = await api("GET", "/auth/me");
    expect(status).toBe(401);
  });
});
