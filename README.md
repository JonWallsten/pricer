<div align="center">

# �️ Pricer

### _Track prices. Get notified._

<br>

🔗 &nbsp; **Add URL** &nbsp;&nbsp;→&nbsp;&nbsp; 💰 &nbsp; **Detect price** &nbsp;&nbsp;→&nbsp;&nbsp; 🔔 &nbsp; **Set alert** &nbsp;&nbsp;→&nbsp;&nbsp; ⏳ &nbsp; **Wait** &nbsp;&nbsp;→&nbsp;&nbsp; 📧 &nbsp; **Get notified**

<br>

A mobile-first price tracker where you add product URLs, set target prices, and get email notifications when prices drop or items come back in stock.

**[🔗 Live → jonwallsten.com/pricer](https://jonwallsten.com/pricer/)**

Built with **Angular 21+** · Standalone components · Signals · PHP API · MySQL

</div>

---

## ✨ Features

|      | Feature                     | Details                                                                                                        |
| ---- | --------------------------- | -------------------------------------------------------------------------------------------------------------- |
| 🔍   | **Smart price extraction**  | Extracts prices from JSON-LD → meta tags → microdata → CSS selector fallback — works on most e-commerce sites  |
| 🔔   | **Price drop alerts**       | Set target prices per product and receive email notifications when the price drops below your target           |
| 📉   | **Price history**           | Chart.js line chart with period selector (week, month, 3 months, year, all) — one data point per day           |
| ⚡   | **Auto-fetch on URL paste** | Price, image, name, and availability are detected instantly when entering a URL — no save needed first         |
| 🏷️   | **Discount chips**          | Quick alert creation with 5%, 10%, 25%, 50% discount chips when adding a product                               |
| 📦   | **Availability tracking**   | Detects in stock / out of stock / pre-order status and notifies when items come back in stock                  |
| 🖼️   | **Product images**          | Auto-extracted from structured data (JSON-LD, og:image, microdata) and displayed on dashboard and detail pages |
| ⏱️   | **Hourly price checks**     | Cron job checks all tracked products every hour and sends alerts when targets are hit                          |
| 🔐   | **Google login**            | OAuth via Google Identity Services with JWT session in HttpOnly cookies                                        |
| 👤   | **User approval system**    | New users must be approved by admin before accessing the app — admin is auto-approved via `ADMIN_EMAIL` config |
| 🛡️   | **Admin panel**             | In-app user management page to approve or reject users                                                         |
| 🇸🇪🇬🇧 | **English & Swedish**       | Full i18n with auto-detection from browser language                                                            |
| 🌓   | **Three-way theme toggle**  | System / dark / light theme, persisted to localStorage                                                         |
| 📱   | **Mobile-first design**     | Angular Material 3 components, responsive layout                                                               |

---

## 🔍 How price extraction works

The scraper tries four methods in order, stopping at the first successful match:

### 1. JSON-LD (`application/ld+json`)

Parses `<script type="application/ld+json">` blocks for `schema.org/Product` or `schema.org/Offer` with a `price` or `lowPrice` field. Also extracts availability and product image.

### 2. Meta tags

Looks for OpenGraph product tags:

```
<meta property="product:price:amount" content="299.00">
<meta property="product:price:currency" content="SEK">
<meta property="og:availability" content="instock">
```

### 3. Microdata

Scans for `itemprop="price"` on elements with a `content` attribute or text content, within a `schema.org/Product` or `/Offer` scope.

### 4. CSS selector fallback

If automatic methods fail, the user can provide a custom CSS selector pointing to the price element. The selector is auto-formatted on input (e.g. bare `data-price="value"` becomes `[data-price="value"]`).

### Availability detection

Availability is extracted from structured data using the same cascade (JSON-LD → meta → microdata). Values are normalised to: `in_stock`, `out_of_stock`, `preorder`, or `unknown`.

---

## 🛠️ Tech stack

|                |                                                                |
| -------------- | -------------------------------------------------------------- |
| **Framework**  | Angular 21 (standalone components, signals, computed)          |
| **UI**         | Angular Material 3, SCSS, mobile-first                         |
| **State**      | Angular signals — no RxJS, no NgRx                             |
| **Charts**     | Chart.js (price history line charts)                           |
| **Backend**    | PHP 8.5, vanilla router, PDO + prepared statements             |
| **Database**   | MySQL with migration system                                    |
| **Auth**       | Google Identity Services (GSI) + JWT (HMAC-SHA256, HttpOnly)   |
| **Email**      | SMTP (if configured) with PHP `mail()` fallback                |
| **i18n**       | Custom signal-based service, no `@angular/localize`            |
| **Routing**    | Angular Router, lazy-loaded routes                             |
| **Linting**    | ESLint (typescript-eslint strict + angular-eslint)             |
| **Formatting** | Prettier, EditorConfig                                         |
| **Testing**    | Vitest (Angular unit tests + Node.js script tests + API tests) |

---

## 📁 Architecture

```
src/app/
├── auth.service.ts        — Google OAuth, session management
├── api.service.ts         — HTTP wrapper for product/alert/admin CRUD
├── i18n.service.ts        — English/Swedish translations
├── auth.guard.ts          — Route guard (redirects unapproved → /pending)
├── admin.guard.ts         — Admin-only route guard
├── models.ts              — TypeScript interfaces (User, Product, Alert, etc.)
├── pipes/
│   └── time-ago.pipe.ts   — Relative time display
├── pages/
│   ├── login/             — Google Sign-In page
│   ├── pending/           — Pending approval page
│   ├── dashboard/         — Product list with price & alert status
│   ├── product-form/      — Add/edit product (name, URL, CSS selector)
│   ├── product-detail/    — Product info, price history, alert management
│   └── admin/             — User approval management

api/
├── index.php              — Entry point, CORS, routing
├── config.php             — Env loading, DB & SMTP & admin constants
├── db.php                 — PDO singleton
├── auth.php               — JWT creation/verification, cookies
├── middleware.php          — requireAuth(), requireApproved()
├── routes/
│   ├── auth.php           — Google OAuth, /auth/me, logout
│   ├── products.php       — Product CRUD + manual price check + history
│   ├── alerts.php         — Alert CRUD
│   └── admin.php          — User list, approve, reject
├── lib/
│   ├── price-scraper.php  — Price extraction (JSON-LD, meta, microdata, CSS)
│   └── mailer.php         — Email notifications (SMTP + mail() fallback)
└── cron/
    └── check-prices.php   — Hourly price check runner (CLI only)

scripts/
├── migrations/            — SQL migration files (001–007)
├── db-migrate.mjs         — Migration runner
└── deploy.mjs             — FTP deployment
```

---

## 🚀 Getting started

```bash
# Install dependencies
npm install

# Start Angular dev server (http://localhost:4200)
npm start

# Start PHP API dev server (http://localhost:8080, proxied via Angular)
npm run start:api

# Production build (outputs to dist/pricer/)
npm run build
```

Both servers are needed for the full stack locally. The Angular dev server proxies `/api/*` → `localhost:8080` via `proxy.conf.json`.

---

## 🔧 Backend setup

### 1. Credential files

Three separate credential files keep secrets organised:

| File                     | Contents                                                                      | Committed? | Deployed to server? |
| ------------------------ | ----------------------------------------------------------------------------- | :--------: | :-----------------: |
| `.credentials.env`       | DB host/name/user/pass, Google OAuth client ID, JWT secret, SMTP, ADMIN_EMAIL |     No     |         Yes         |
| `.credentials.local.env` | Local overrides (e.g. different DB host for dev)                              |     No     |         No          |
| `.ftp.env`               | FTP host/user/pass/path for FTP deployment                                    |     No     |         No          |

Copy the examples and fill in your values:

```bash
cp .credentials.env.example .credentials.env
cp .credentials.local.env.example .credentials.local.env  # optional local overrides
cp .ftp.env.example .ftp.env
```

Node.js scripts load `.credentials.env` then **overlay** `.credentials.local.env` on top if it exists. PHP `api/config.php` does the same.

### 2. Database migrations

```bash
# Apply all pending migrations to local DB (uses .credentials.local.env overlay)
npm run db:migrate

# Show applied / pending status
npm run db:status

# Apply against remote DB only (skips local overlay)
npm run db:migrate -- --remote
```

### 3. Google OAuth

1. Create a project at [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Add an OAuth 2.0 client ID (Web application)
3. Add **Authorized JavaScript origins**: `http://localhost:4200`, `https://yourdomain.com`
4. Copy the client ID into `.credentials.env`

### 4. Admin email

Set `ADMIN_EMAIL` in `.credentials.env` to your Google account email. This user is auto-approved on first login and gets access to the admin panel. All other users must be approved manually.

---

## 📦 Deployment

All deployment targets use [basic-ftp](https://www.npmjs.com/package/basic-ftp) over FTPS. Credentials are read from `.ftp.env`.

FTPS certificate verification is enabled by default. If your host uses a self-signed or mismatched certificate, you must opt in explicitly with `--insecure-ftps` for that deploy instead of silently disabling verification for every upload.

```bash
# Full deploy: build Angular + upload frontend + upload API
npm run deploy

# Frontend only (skips build of API files)
npm run deploy:frontend

# API only (no Angular build)
npm run deploy:api

# Upload .credentials.env to server (one-time or when secrets change)
npm run deploy:credentials
```

`deploy:credentials` is intentionally separate — it uploads the app secrets once, and the regular `deploy` never touches them on the server.

### Deploy flags

```bash
node scripts/deploy.mjs --dry-run           # list files without transferring
node scripts/deploy.mjs --api-only          # API files only
node scripts/deploy.mjs --frontend-only     # built frontend only
node scripts/deploy.mjs --credentials-only  # .credentials.env only
node scripts/deploy.mjs --insecure-ftps     # allow invalid FTPS certs for this run only
```

## 🔒 Security notes

- Product URLs must use `http` or `https` and resolve to a public host. Localhost, private-network, and reserved IP targets are rejected to reduce SSRF risk.
- Redirect following is disabled during price scraping so a public product URL cannot bounce into an internal address.
- FTPS certificate verification stays on by default during deploys; only use `--insecure-ftps` when you have verified the server and cannot fix its certificate yet.

---

## 🧪 Testing

```bash
# Angular unit tests (Vitest via @angular/build)
npm test

# Node.js script unit tests (credential parsing, deploy flags, migration file discovery)
npm run test:scripts

# API integration tests (requires npm run start:api to be running)
npm run test:api

# Run against the live server instead of localhost
API_BASE_URL=https://yourdomain.com/pricer/api npm run test:api
```

The API tests create a temporary test user in the DB, exercise all CRUD endpoints, and clean up after themselves.

---

## 📄 License

MIT
