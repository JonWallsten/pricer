# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- **Product pricing interpretation system**: new layer that identifies the main product on a page, classifies price roles, scores product association, detects campaigns, and selects the primary price
- **Main product context**: `buildMainProductContext()` aggregates product identity (name, SKU, GTIN, brand) from JSON-LD, meta tags, microdata, DOM signals, and data attributes with confidence scoring
- **Price role classification**: `classifyPriceRole()` identifies prices as current, regular, campaign, previous_lowest (Omnibus 30-day), unit, from, or member using field names, CSS patterns, and Swedish keywords
- **Product association scoring**: `scoreProductAssociation()` scores 0–100 how strongly each price candidate belongs to the main product, penalizing recommendation/related-product containers
- **Campaign detection**: `detectCampaign()` identifies discount campaigns and computes savings using the EU Omnibus Directive 30-day lowest price as reference when available
- **EU Omnibus 30-day lowest price support**: detects `previousLowestPrice`, `comparisonPrice`, `omnibusPrice`, `jämförpris`, `lägsta-pris` and similar fields from Swedish e-commerce sites; stores as `previous_lowest_price` column; displays struck-through on product detail
- **Price interpretation pipeline**: auto-mode now collects ALL candidates from all sources, then runs the interpretation layer (context → association → role → campaign → selection) before choosing the primary price
- **Campaign fields in database**: migration 011 adds `regular_price`, `previous_lowest_price`, `is_campaign`, `campaign_type`, `campaign_label`, `campaign_json` to both `product_urls` and `products` tables
- **Page inspector enhancements**: product context card, price role badges, association score bars, campaign detection card with struck-through prices and savings display
- **Product detail campaign display**: hero card shows struck-through reference price (Omnibus 30-day lowest or regular) above campaign price; per-site rows show the same treatment with campaign badges
- Anti-noise filtering for recommendation/related/upsell containers in WebComponents extraction
- 26 new i18n keys (en + sv) for price roles, campaign info, product context, and association labels

- **Multi-strategy price extraction**: new pipeline that tries CSS selector → JSON-LD → script patterns → meta tags → microdata → DOM heuristic, configurable per URL as 'auto' or 'selector' mode
- **Script pattern extractors**: built-in support for `WebComponents.push`, `__NEXT_DATA__`, `__NUXT__`/`__NUXT_DATA__`, and `__INITIAL_STATE__`/`__APP_DATA__` embedded data
- **DOM heuristic fallback**: scoring-based price discovery from DOM elements with price-related classes and data attributes
- **Price candidate discovery**: `discoverPriceCandidates()` aggregates price sources from all extraction methods, deduplicates by value, and sorts by confidence
- **Find by current price**: helper that searches all price sources for a known visible price (within 0.5% tolerance) to identify the extraction path
- **Extraction strategy selector**: per-URL dropdown on the product form to choose between 'Auto' and 'CSS Selector' extraction modes
- **Discovered price sources panel**: page inspector side panel now shows all discovered price candidates with source type, confidence badge, and extraction path
- **Find by price tool**: debug mode in page inspector includes a search input to find which source matches a known price
- Structured extraction results with `confidence`, `debug_source`, `debug_path`, and `warnings` fields
- `PriceCandidate` model with sourceType, patternType, confidence, path, and reasons
- Migration 010: `extraction_strategy` column on `product_urls` and `products` tables
- 22 new i18n keys (en + sv) for extraction strategy, discovered sources, and confidence labels
- **Page Inspector**: dialog-based tool for debugging price extraction — opens server-fetched HTML in a sandboxed iframe with JS-rendering detection, page quality warnings, and selector match diagnostics
- **Selector Picker**: interactive click-to-pick mode generates multiple ranked CSS selector candidates with stability labels (recommended / fallback / fragile)
- Debug mode on product detail page lets you inspect how the scraper sees each tracked URL and test selectors against actual server-fetched HTML
- New `POST /products/page-source` API endpoint for fetching and sanitising page HTML with selector analysis
- New backend functions: `detectJsRendering()`, `detectPageQualityIssues()`, `preparePageForPreview()`, `analyzeSelectorInDoc()`
- 16 new i18n keys (en + sv) for the page inspector UI
- API integration tests for the page-source endpoint
- **Multi-URL support**: Products can now track multiple retailer URLs. The lowest price across all sites is displayed as the product's current price
- Cross-store product discovery with SerpApi-backed candidate search, cached candidate fetching, deterministic confidence scoring, and a new "Also sold at" section on the product detail page
- New product match persistence/cache tables (migration 009) for search responses, fetched candidate pages, and scored match candidates
- Manual "Find matching products" / "Refresh matches" action on product detail pages
- New `product_urls` database table (migration 008) for storing per-URL price data
- "Sites" section on product detail page showing per-site prices, availability, and domain
- Per-site refresh button and "Check all" button for checking all URLs at once
- "Add URL" / "Remove URL" buttons on product form for managing multiple URLs per product
- Per-URL preview on the product form when adding new products
- "N sites" badge on dashboard cards when a product tracks multiple URLs
- New i18n keys for multi-URL UI (en + sv)

### Changed

- Redesigned product detail page with premium card layout, refined typography, surface layering, and polished component styling
- Color palette changed from azure/blue to violet/orange for a modern look that works better in dark mode
- Neutral surface tokens: overrode Material 3 surface variables with warm neutral values (#F7F5F2 page bg, #FFFFFF cards, #F3F4F6 nested) to remove purple tinting
- Header redesigned: neutral background with subtle border, centered inner container aligned to content width (840px)
- Toolbar buttons and avatar use neutral surface tokens instead of primary-colored mixing
- Product detail sections use border-based framing instead of shadow-heavy cards for lighter appearance
- Toolbar title is now a home link for easy navigation back from admin and detail pages
- Theme toggle button now has a visible background instead of blending into the toolbar
- Fetching banner on product form now uses Material theme tokens instead of hardcoded colors
- Added shared app-level styling tokens/utilities for page widths, badge sizing, and common surface semantics to reduce duplicated SCSS across pages
- Product detail page now surfaces cross-store match confidence, reasons, and discovery freshness directly in the UI

### Fixed

- Blocked localhost, private-network, and reserved-address product URLs in preview/create/update flows to reduce SSRF risk
- Disabled redirect following in the scraper fetch path so public URLs cannot bounce into internal hosts
- Enabled FTPS certificate verification by default in deployment; insecure certificate bypass now requires explicit `--insecure-ftps`
- Switched admin authorization from email matching to `ADMIN_GOOGLE_ID` and now require `email_verified` on Google login

## [1.0.0] — 2026-04-03

### Added

- User approval system: new users must be approved by admin before accessing the app
- Admin panel page for managing users (approve/reject)
- Pending approval page shown to unapproved users after login
- Admin icon in toolbar for quick access to user management
- `ADMIN_EMAIL` config: admin user is auto-approved on login
- Admin API routes: `GET /admin/users`, `PUT /admin/users/:id/approve`, `PUT /admin/users/:id/reject`
- `requireApproved()` middleware: checks DB approval status on every protected request
- Database migration 007: `is_approved` column on users table
- Admin guard for frontend route protection
- Auth guard now redirects unapproved users to `/pending`
- New i18n keys for approval feature (English + Swedish)
- Price history with Chart.js line chart on product detail page
- Period selector for price history (week, month, 3 months, year, all)
- Database migration 005: `price_history` table with unique per-day constraint
- Auto-fetch price preview when entering a URL on the add product form
- Preview card showing price, image, and availability before saving
- Auto-fill product name from page title during URL preview
- CSS selector auto-formatting on blur (bare attributes wrapped in brackets)
- Discount chips (5%, 10%, 25%, 50%) for quick alert creation on new products
- Auto-create alert with selected discount when adding a product
- Auto-run price check on product creation (no manual check needed)
- Product availability tracking (in stock, out of stock, pre-order, unknown)
- Availability badges on dashboard cards and product detail page
- Back-in-stock notification toggle on alerts
- Database migration 006: `availability` column on products, `notify_back_in_stock` on alerts
- `extractAvailability()` in price scraper (JSON-LD → meta tags → microdata)
- `extractPreview()` for single-fetch preview extraction (price + image + availability + title)
- `extractPageTitle()` helper for page title extraction (og:title → `<title>`)
- `POST /products/preview` API endpoint for URL preview without product creation
- `GET /products/:id/history` API endpoint with period filtering
- `sendBackInStockNotification()` email template in mailer
- Back-in-stock detection in cron job (out_of_stock → in_stock transition)
- Price history recording in cron job (one entry per day via INSERT IGNORE)
- New i18n keys for all features (English + Swedish)
- Three-way theme toggle (system / dark / light) in the toolbar header
- Product image extraction from structured data (JSON-LD, og:image, microdata)
- Product images displayed in dashboard cards and product detail page
- Database migration 004: `image_url` column on products table
- Google OAuth login with JWT session (auth.service, auth.guard)
- Product CRUD: add, edit, delete tracked products with URLs
- Price extraction engine: JSON-LD → meta tags → microdata → CSS selector fallback
- Alert system: set target prices, get notified when price drops below target
- Hourly cron job for automated price checking (api/cron/check-prices.php)
- Email notifications via SMTP (with PHP mail() fallback)
- Manual "Check Now" button for instant price extraction
- Dashboard page with product list, price status, and alert counts
- Product detail page with alert management (add, toggle, delete)
- Product form page with URL and optional CSS selector input
- i18n service with English and Swedish translations
- Angular Material 3 UI components throughout
- Database migrations: users, products, alerts tables
- TimeAgo pipe for relative time display

### Fixed

- Dark mode: page background now uses Material 3 surface tokens instead of browser default
- Dark mode: status badges, error messages, and alert chips adapt colors properly
- Toolbar now always visible (including login page) for consistent theme toggle access
