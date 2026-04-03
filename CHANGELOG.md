# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

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
