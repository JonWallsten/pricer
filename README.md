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

|      | Feature                     | Details                                                                                                                                                               |
| ---- | --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 🔍   | **Smart price extraction**  | Multi-strategy pipeline: CSS selector → JSON-LD → script patterns → meta tags → microdata → DOM heuristic — with per-URL strategy selection (auto / selector)         |
| 🔔   | **Price drop alerts**       | Set target prices per product and receive email notifications when the price drops below your target                                                                  |
| 📉   | **Price history**           | Chart.js line chart with period selector (week, month, 3 months, year, all) — one data point per day                                                                  |
| 🌐   | **Multi-site tracking**     | Track the same product across multiple retailer URLs — lowest price is shown, with per-site details                                                                   |
| 🧭   | **Cross-store discovery**   | Find likely matching product pages at other stores with SerpApi discovery, structured extraction, and explainable confidence scores                                   |
| 🔬   | **Page Inspector**          | Debug why a selector fails — view server-fetched HTML in a sandboxed iframe with JS-rendering detection, page quality warnings, and click-to-pick selector generation |
| ⚡   | **Auto-fetch on URL paste** | Price, image, name, and availability are detected instantly when entering a URL — no save needed first                                                                |
| 🏷️   | **Discount chips**          | Quick alert creation with 5%, 10%, 25%, 50% discount chips when adding a product                                                                                      |
| 📦   | **Availability tracking**   | Detects in stock / out of stock / pre-order status and notifies when items come back in stock                                                                         |
| 🖼️   | **Product images**          | Auto-extracted from structured data (JSON-LD, og:image, microdata) and displayed on dashboard and detail pages                                                        |
| ⏱️   | **Hourly price checks**     | Cron job checks all tracked products every hour and sends alerts when targets are hit                                                                                 |
| 🔐   | **Google login**            | OAuth via Google Identity Services with JWT session in HttpOnly cookies                                                                                               |
| 👤   | **User approval system**    | New users must be approved by admin before accessing the app — the admin account is identified by `ADMIN_GOOGLE_ID`                                                   |
| 🛡️   | **Admin panel**             | In-app user management page to approve or reject users                                                                                                                |
| 🇸🇪🇬🇧 | **English & Swedish**       | Full i18n with auto-detection from browser language                                                                                                                   |
| 🌓   | **Three-way theme toggle**  | System / dark / light theme, persisted to localStorage                                                                                                                |
| 📱   | **Mobile-first design**     | Angular Material 3 components, responsive layout                                                                                                                      |

---

## 🔍 How price extraction works

The scraper uses a configurable multi-strategy pipeline. Each URL can be set to **Auto** (tries all methods in order) or **CSS Selector** (uses only a specified selector). In auto mode, the pipeline tries these methods in order, stopping at the first successful match:

### 1. CSS selector (if configured)

If the user specified a CSS selector, try it first. The selector is converted to XPath and matched against the server-fetched HTML.

### 2. JSON-LD (`application/ld+json`)

Parses `<script type="application/ld+json">` blocks for `schema.org/Product` or `schema.org/Offer` with a `price` or `lowPrice` field. Also extracts availability and product image.

### 3. Script patterns

Extracts prices from embedded JavaScript data structures:

- **WebComponents.push** — common in Nordic e-commerce (e.g. Elgiganten)
- **\_\_NEXT_DATA\_\_** — Next.js server-side props
- **\_\_NUXT\_\_** / **\_\_NUXT_DATA\_\_** — Nuxt.js hydration data
- **\_\_INITIAL_STATE\_\_** / **\_\_APP_DATA\_\_** — generic SSR state

### 4. Meta tags

Looks for OpenGraph product tags:

```
<meta property="product:price:amount" content="299.00">
<meta property="product:price:currency" content="SEK">
<meta property="og:availability" content="instock">
```

### 5. Microdata

Scans for `itemprop="price"` on elements with a `content` attribute or text content, within a `schema.org/Product` or `/Offer` scope.

### 6. DOM heuristic fallback

Scoring-based price discovery from DOM elements with price-related CSS classes and data attributes. Penalises elements in list/shipping contexts and favours elements with sale or data-price attributes.

### Price candidate discovery

The **Page Inspector** debug panel shows all discovered price candidates from all sources, with confidence levels (high / medium / low) and extraction paths. A "Find by current price" tool lets you enter the visible price to locate its source.

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
│   ├── products.php       — Product CRUD + manual price check + history + match discovery
│   ├── alerts.php         — Alert CRUD
│   └── admin.php          — User list, approve, reject
├── lib/
│   ├── price-scraper.php  — Price extraction (JSON-LD, meta, microdata, CSS)
│   ├── product-match-discovery.php — Cross-store discovery, extraction, scoring, caching
│   └── mailer.php         — Email notifications (SMTP + mail() fallback)
└── cron/
    └── check-prices.php   — Hourly price check runner (CLI only)

scripts/
├── migrations/            — SQL migration files (001–009)
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

| File                     | Contents                                                                         | Committed? | Deployed to server? |
| ------------------------ | -------------------------------------------------------------------------------- | :--------: | :-----------------: |
| `.credentials.env`       | DB host/name/user/pass, Google OAuth client ID, JWT secret, SMTP, admin identity |     No     |         Yes         |
| `.credentials.local.env` | Local overrides (e.g. different DB host for dev)                                 |     No     |         No          |
| `.ftp.env`               | FTP host/user/pass/path for FTP deployment                                       |     No     |         No          |

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

### 4. Admin identity

Set `ADMIN_GOOGLE_ID` in `.credentials.env` to your Google account's `sub` claim. That same value is stored as `users.google_id` after login. The configured Google account is auto-approved on login and gets access to the admin panel.

Ways to find it:

- Log in once, then read your row from the `users` table and copy `google_id`.
- Or decode a Google ID token and copy the `sub` claim.

`ADMIN_EMAIL` can still be kept as a contact/reference value, but admin access now keys off `ADMIN_GOOGLE_ID`, not the email string.

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

## 🧭 Cross-store discovery

Pricer can discover likely matching product pages at other stores for a tracked product.

The first version is intentionally simple:

- SerpApi is used only to discover candidate URLs
- Product matching confidence is computed locally with deterministic heuristics
- Search results and candidate fetches are cached aggressively to keep usage low
- Each stored match includes a confidence score and human-readable reasons

### How it works

1. Normalize the tracked product title and structured fields
2. Build one or two compact search queries
3. Search with SerpApi
4. Filter out noisy URLs and same-domain results
5. Fetch a small set of candidate pages
6. Extract JSON-LD/meta/DOM product signals
7. Score the candidate using identifiers, title similarity, variant attributes, and price proximity
8. Persist the best matches and show them on the product detail page

### Configuration

Add these optional values to `.credentials.env` if you want match discovery enabled:

```bash
SERPAPI_API_KEY=your_serpapi_key
SERPAPI_SEARCH_COUNTRY=se
SERPAPI_SEARCH_LOCALE=sv-SE
```

If `SERPAPI_API_KEY` is missing, the rest of the app still works normally, but match discovery requests will fail gracefully.

### Cost controls

- Search responses are cached for 7 days
- Candidate fetch/extraction results are cached for 3 days
- Failed candidate fetches are cached for 1 day
- Each discovery run uses at most 2 queries, considers at most 10 results, and fetches at most 5 candidates

The feature is designed for low-volume personal use, so deterministic heuristics and caching are favored over expensive or opaque approaches.

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
