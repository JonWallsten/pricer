/**
 * API integration tests.
 *
 * Requires:
 *   - PHP dev server:  npm run start:api   (localhost:8080)
 *   - Local DB accessible via .credentials.env / .credentials.local.env
 *
 * Run with:  npm run test:api
 */

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { createHmac } from 'node:crypto';
import mysql from 'mysql2/promise';
import { loadAppCredentials } from '../lib/credentials.mjs';

// ── Config ────────────────────────────────────────────────

const BASE = process.env.API_BASE_URL ?? 'http://localhost:8080';

// ── JWT helper (mirrors PHP auth.php logic) ───────────────

function b64url(buf) {
    return buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function makeJwt(userId, email, secret) {
    const header = b64url(Buffer.from(JSON.stringify({ alg: 'HS256', typ: 'JWT' })));
    const now = Math.floor(Date.now() / 1000);
    const payload = b64url(
        Buffer.from(JSON.stringify({ user_id: userId, email, iat: now, exp: now + 3600 })),
    );
    const sig = b64url(createHmac('sha256', secret).update(`${header}.${payload}`).digest());
    return `${header}.${payload}.${sig}`;
}

// ── HTTP helpers ──────────────────────────────────────────

async function api(method, path, { body, token } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Cookie'] = `auth_token=${token}`;
    const res = await fetch(`${BASE}${path}`, {
        method,
        headers,
        redirect: 'manual',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    let data = null;
    try {
        data = JSON.parse(text);
    } catch {
        // If we can't parse JSON, store the raw text for assertions
        data = { _raw: text };
    }
    return { status: res.status, data, text };
}

// ── Test state ────────────────────────────────────────────

let db;
let creds;
let testUserId;
let jwt;

const TEST_GOOGLE_ID = 'test-integration-user-99999';
const TEST_EMAIL = 'test-integration@pricer.test';
const TEST_NAME = 'Integration Test User';

// ── Setup / Teardown ──────────────────────────────────────

beforeAll(async () => {
    creds = loadAppCredentials();

    db = await mysql.createConnection({
        host: creds.DB_HOST,
        user: creds.DB_USER,
        password: creds.DB_PASS,
        database: creds.DB_NAME,
        charset: 'utf8mb4',
    });

    // Clean up any leftover test user from a previous run
    await db.query('DELETE FROM users WHERE google_id = ?', [TEST_GOOGLE_ID]);

    // Insert a fresh, approved test user
    const [result] = await db.query(
        'INSERT INTO users (google_id, email, name, is_approved) VALUES (?, ?, ?, 1)',
        [TEST_GOOGLE_ID, TEST_EMAIL, TEST_NAME],
    );
    testUserId = result.insertId;

    jwt = makeJwt(testUserId, TEST_EMAIL, creds.JWT_SECRET);
});

afterAll(async () => {
    if (db) {
        await db.query('DELETE FROM users WHERE id = ?', [testUserId]);
        await db.end();
    }
});

// ── PHP syntax check (no parse errors on any endpoint) ────

describe('PHP parse errors', () => {
    it('GET /auth/config returns valid JSON', async () => {
        const { status, data } = await api('GET', '/auth/config');
        expect(data._raw).toBeUndefined(); // parsed as JSON, not raw HTML
        expect(status).toBe(200);
    });

    it('GET /auth/me returns valid JSON', async () => {
        const { status, data } = await api('GET', '/auth/me', { token: jwt });
        expect(data._raw).toBeUndefined();
        expect(status).toBe(200);
    });

    it('GET /products returns valid JSON', async () => {
        const { status, data } = await api('GET', '/products', { token: jwt });
        expect(data._raw).toBeUndefined();
        expect(status).toBe(200);
    });

    it('POST /products/preview returns valid JSON', async () => {
        const { status, data } = await api('POST', '/products/preview', {
            token: jwt,
            body: { url: 'https://example.com' },
        });
        expect(data._raw).toBeUndefined();
        expect([200, 400]).toContain(status);
    });
});

// ── Public routes ─────────────────────────────────────────

describe('GET /auth/config', () => {
    it('returns 200 with google_client_id', async () => {
        const { status, data } = await api('GET', '/auth/config');
        expect(status).toBe(200);
        expect(typeof data.google_client_id).toBe('string');
        expect(data.google_client_id.length).toBeGreaterThan(0);
    });
});

describe('POST /auth/google with invalid token', () => {
    it('returns 401', async () => {
        const { status } = await api('POST', '/auth/google', {
            body: { token: 'not-a-real-google-token' },
        });
        expect(status).toBe(401);
    });

    it('returns 400 when token is missing', async () => {
        const { status } = await api('POST', '/auth/google', { body: {} });
        expect(status).toBe(400);
    });
});

// ── Auth guard ─────────────────────────────────────────────

describe('Auth required — no token', () => {
    it('GET /products → 401', async () => {
        const { status } = await api('GET', '/products');
        expect(status).toBe(401);
    });
});

// ── GET /auth/me ───────────────────────────────────────────

describe('GET /auth/me', () => {
    it('returns the authenticated user with approval fields', async () => {
        const { status, data } = await api('GET', '/auth/me', { token: jwt });
        expect(status).toBe(200);
        expect(data.user.id).toBe(testUserId);
        expect(data.user.email).toBe(TEST_EMAIL);
        expect(typeof data.user.is_approved).toBe('boolean');
        expect(typeof data.user.is_admin).toBe('boolean');
    });

    it('returns 401 with no token', async () => {
        const { status } = await api('GET', '/auth/me');
        expect(status).toBe(401);
    });
});

// ── Products CRUD ──────────────────────────────────────────

describe('Products', () => {
    let productId;

    it('POST /products/preview rejects localhost URLs', async () => {
        const { status, data } = await api('POST', '/products/preview', {
            token: jwt,
            body: { url: 'http://127.0.0.1/internal' },
        });
        expect(status).toBe(400);
        expect(data.error).toMatch(/public host/i);
    });

    it('POST /products creates a product', async () => {
        const { status, data } = await api('POST', '/products', {
            token: jwt,
            body: { name: 'Test Product', url: 'https://example.com/product' },
        });
        expect(status).toBe(201);
        expect(data.product.name).toBe('Test Product');
        expect(data.product.url).toBe('https://example.com/product');
        productId = data.product.id;
    });

    it('GET /products lists products', async () => {
        const { status, data } = await api('GET', '/products', { token: jwt });
        expect(status).toBe(200);
        expect(Array.isArray(data.products)).toBe(true);
        expect(data.products.some((p) => p.id === productId)).toBe(true);
    });

    it('GET /products/:id returns the product', async () => {
        const { status, data } = await api('GET', `/products/${productId}`, {
            token: jwt,
        });
        expect(status).toBe(200);
        expect(data.product.id).toBe(productId);
        expect(data.product.name).toBe('Test Product');
    });

    it('PUT /products/:id updates the product', async () => {
        const { status, data } = await api('PUT', `/products/${productId}`, {
            token: jwt,
            body: { name: 'Updated Product' },
        });
        expect(status).toBe(200);
        expect(data.product.name).toBe('Updated Product');
    });

    it('PUT /products/:id rejects private-network URLs', async () => {
        const { status, data } = await api('PUT', `/products/${productId}`, {
            token: jwt,
            body: { url: 'http://10.0.0.5/private' },
        });
        expect(status).toBe(400);
        expect(data.error).toMatch(/public host/i);
    });

    it('POST /products/:id/check returns extraction result', async () => {
        const { status, data } = await api('POST', `/products/${productId}/check`, {
            token: jwt,
        });
        expect(data._raw).toBeUndefined(); // must be valid JSON, not a parse error
        expect(status).toBe(200);
        expect(data.extraction).toBeDefined();
    });

    it('GET /products/:id/history returns price history', async () => {
        const { status, data } = await api('GET', `/products/${productId}/history?period=month`, {
            token: jwt,
        });
        expect(data._raw).toBeUndefined();
        expect(status).toBe(200);
        expect(Array.isArray(data.history)).toBe(true);
    });

    it('GET /products/:id/matches returns persisted match candidates JSON', async () => {
        const { status, data } = await api('GET', `/products/${productId}/matches`, {
            token: jwt,
        });
        expect(data._raw).toBeUndefined();
        expect(status).toBe(200);
        expect(Array.isArray(data.matches)).toBe(true);
    });

    it('POST /products/:id/discover-matches returns JSON and fails gracefully when unavailable', async () => {
        const { status, data } = await api('POST', `/products/${productId}/discover-matches`, {
            token: jwt,
            body: { force: false },
        });
        expect(data._raw).toBeUndefined();
        expect([200, 503]).toContain(status);
        if (status === 200) {
            expect(Array.isArray(data.matches)).toBe(true);
            expect(Array.isArray(data.queries)).toBe(true);
            expect(typeof data.searches_run).toBe('number');
        } else {
            expect(typeof data.error).toBe('string');
        }
    });

    it('DELETE /products/:id removes the product', async () => {
        const { status, data } = await api('DELETE', `/products/${productId}`, {
            token: jwt,
        });
        expect(status).toBe(200);
        expect(data.success).toBe(true);
    });

    it('GET /products/:id returns 404 after delete', async () => {
        const { status } = await api('GET', `/products/${productId}`, {
            token: jwt,
        });
        expect(status).toBe(404);
    });
});

// ── Alerts CRUD ────────────────────────────────────────────

describe('Alerts', () => {
    let productId;
    let alertId;

    beforeAll(async () => {
        const { data } = await api('POST', '/products', {
            token: jwt,
            body: { name: 'Alert Test Product', url: 'https://example.com/alert' },
        });
        productId = data.product.id;
    });

    afterAll(async () => {
        await api('DELETE', `/products/${productId}`, { token: jwt });
    });

    it('POST /products/:id/alerts creates an alert', async () => {
        const { status, data } = await api('POST', `/products/${productId}/alerts`, {
            token: jwt,
            body: { target_price: 99.99, notify_back_in_stock: true, renotify_drop_amount: 50 },
        });
        expect(status).toBe(201);
        expect(data.alert.target_price).toBe(99.99);
        expect(data.alert.notify_back_in_stock).toBe(true);
        expect(data.alert.renotify_drop_amount).toBe(50);
        alertId = data.alert.id;
    });

    it('PUT /alerts/:id updates the alert', async () => {
        const { status, data } = await api('PUT', `/alerts/${alertId}`, {
            token: jwt,
            body: { target_price: 79.99, is_active: false, renotify_drop_amount: 25 },
        });
        expect(status).toBe(200);
        expect(data.alert.target_price).toBe(79.99);
        expect(data.alert.renotify_drop_amount).toBe(25);
    });

    it('DELETE /alerts/:id removes the alert', async () => {
        const { status, data } = await api('DELETE', `/alerts/${alertId}`, {
            token: jwt,
        });
        expect(status).toBe(200);
        expect(data.success).toBe(true);
    });
});

// ── Page source / inspector ────────────────────────────────

describe('POST /products/page-source', () => {
    // Note: tests that fetch external URLs may get 502 if the server
    // cannot reach the internet.  We accept both 200 (success) and 502
    // (fetch failed) for those cases and only assert the full response
    // shape when the fetch succeeds.

    it('returns sanitised HTML for a valid URL (or 502 when offline)', async () => {
        const { status, data } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'https://www.example.com' },
        });
        expect([200, 502]).toContain(status);
        if (status === 200) {
            expect(data.html).toBeDefined();
            expect(data.base_url).toBeDefined();
            expect(typeof data.js_rendering_likely).toBe('boolean');
            expect(Array.isArray(data.page_quality_warnings)).toBe(true);
            // Sanitised HTML must not contain <script> tags
            expect(data.html).not.toMatch(/<script[\s>]/i);
        }
    });

    it('returns selector analysis when css_selector is provided (or 502 when offline)', async () => {
        const { status, data } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'https://www.example.com', css_selector: 'h1' },
        });
        expect([200, 502]).toContain(status);
        if (status === 200) {
            expect(typeof data.selector_valid).toBe('boolean');
            expect(typeof data.selector_match_count).toBe('number');
        }
    });

    it('reports invalid selector gracefully (or 502 when offline)', async () => {
        const { status, data } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'https://www.example.com', css_selector: '[[[invalid' },
        });
        expect([200, 502]).toContain(status);
        if (status === 200) {
            expect(data.selector_valid).toBe(false);
            expect(data.selector_error).toBeDefined();
        }
    });

    it('returns price_candidates and price_matches arrays (or 502 when offline)', async () => {
        const { status, data } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'https://www.example.com' },
        });
        expect([200, 502]).toContain(status);
        if (status === 200) {
            expect(Array.isArray(data.price_candidates)).toBe(true);
            expect(Array.isArray(data.price_matches)).toBe(true);
        }
    });

    it('returns price_matches when find_price is provided (or 502 when offline)', async () => {
        const { status, data } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'https://www.example.com', find_price: 99.99 },
        });
        expect([200, 502]).toContain(status);
        if (status === 200) {
            expect(Array.isArray(data.price_matches)).toBe(true);
        }
    });

    it('rejects missing URL with 400', async () => {
        const { status } = await api('POST', '/products/page-source', {
            token: jwt,
            body: {},
        });
        expect(status).toBe(400);
    });

    it('rejects disallowed URL with 400', async () => {
        const { status } = await api('POST', '/products/page-source', {
            token: jwt,
            body: { url: 'ftp://badscheme.example.com' },
        });
        expect(status).toBe(400);
    });

    it('requires authentication', async () => {
        const { status } = await api('POST', '/products/page-source', {
            body: { url: 'https://www.example.com' },
        });
        expect(status).toBe(401);
    });
});

// ── Approval guard ─────────────────────────────────────────

describe('Unapproved user is blocked', () => {
    let unapprovedUserId;
    let unapprovedJwt;

    beforeAll(async () => {
        const [result] = await db.query(
            'INSERT INTO users (google_id, email, name, is_approved) VALUES (?, ?, ?, 0)',
            ['test-unapproved-99999', 'unapproved@pricer.test', 'Unapproved'],
        );
        unapprovedUserId = result.insertId;
        unapprovedJwt = makeJwt(unapprovedUserId, 'unapproved@pricer.test', creds.JWT_SECRET);
    });

    afterAll(async () => {
        await db.query('DELETE FROM users WHERE id = ?', [unapprovedUserId]);
    });

    it('GET /products → 403 for unapproved user', async () => {
        const { status, data } = await api('GET', '/products', {
            token: unapprovedJwt,
        });
        expect(status).toBe(403);
        expect(data.error).toMatch(/pending/i);
    });

    it('GET /auth/me still works for unapproved user', async () => {
        const { status, data } = await api('GET', '/auth/me', {
            token: unapprovedJwt,
        });
        expect(status).toBe(200);
        expect(data.user.is_approved).toBe(false);
    });
});

// ── 404 for unknown routes ─────────────────────────────────

describe('Unknown routes', () => {
    it('GET /nonexistent → 404 with valid JSON', async () => {
        const { status, data } = await api('GET', '/nonexistent', { token: jwt });
        expect(data._raw).toBeUndefined();
        expect(status).toBe(404);
    });
});
