# Busherian Biasharas (`busherianbiasharas.com`)

Verified Alumni Business Directory of **The Alliances** (**Alliance High School [AHS]** and **Alliance Girls High School [AGHS]**, both colloquially known as **Bush**).

## Key Features

- **2-Level Business Taxonomy (15 Industry Sectors × 100+ Specialties)**: Cascading Level 1 Industry → Level 2 Specialty filters, Kenyan/Swahili synonym search (*wakili*, *daktari*, *fundi*, *mjengo*, *chemist*, *nyama choma*, *sanaa*), and self-service `+ Add New` specialty submission with live substring, normalized Levenshtein, and `similar_text()` fuzzy deduplication.
- **One-Click Website URL Auto-Fill on Registration (`WebsiteEnricher.php`)**: When registering a new business (`submit.php` or Admin), paste the business's website URL to automatically extract Business Name, Tagline, Description, Phone, WhatsApp, Email, Social Media profiles, Physical Address/Coordinates, Operating Hours, and suggested Level 1 & Level 2 categories via Schema.org `JSON-LD`, OpenGraph, and DOM heuristics — guarded by strict IANA IPv4/IPv6 transition SSRF protection (unwrapping IPv4-mapped/translated/compatible IPv6, NAT64, 6to4, Teredo, CGNAT, benchmarking, ULA, link-local, loopback, and non-canonical numeric literals + bracketed `CURLOPT_RESOLVE` IP pinning).
- **One-Time Alumni Vetting with Immediate Data Disposal & Zero-PII Email Alerts (`VettingPurger.php`, `Mailer.php`)**:
  - Registration Step 1 collects **School** (`AHS` or `AGHS`), **House** (compulsory, using the confirmed AHS & AGHS house lists), **Year Left** (compulsory), and **School Number** (optional — strictly termed *School Number*, never *Admission Number*).
  - **Strict Privacy Guarantee**: `House`, `Year Left`, and `School Number` are stored exclusively in an isolated `business_vetting_queue` table, **never appear anywhere in the public directory or outbound email alerts / `email_outbox`**, and are **permanently hard-deleted (`DELETE FROM business_vetting_queue`)** the instant the listing is approved or rejected.
  - **Transaction-Decoupled Outbox & Retry Worker (`Mailer.php`, `bin/process-email-outbox.php`)**: Outbound emails enqueued inside DB transactions defer network SMTP delivery until after commit (preventing DB lock stalls and rollback phantom emails), with bounded CLI/admin retry and honest UI feedback if immediate OTP delivery fails.
- **Verified Owner Accounts & Server-Side Revocable Sessions (`OwnerAuth.php`)**:
  - Password-first owner sign-in with 6-digit email verification codes, single-use recipient-bound claim invitations, strict corporate-domain non-linking (preventing shared-domain ownership takeover), `session_regenerate_id(true)` across login/claim transitions, and server-side revocable `owner_sessions` (`Sign Out All Devices` + automatic session revocation on password reset/change).
- **Interactive Split-Screen Map, Maps Share-Link Pin Unroller, Plus Code Resolver & `Africa/Nairobi` HoursEngine**:
  - Self-hosted CSS, Vanilla JS, and Leaflet (`public/assets/css/app.css`, `public/assets/vendor/leaflet.*`, `public/assets/js/*` — zero external script/font/CSS CDN requests on initial page load), with interactive map tiles loaded from OpenStreetMap (`tile.openstreetmap.org`) and on-demand routing/geocoding via OSRM and Nominatim.
  - Real-time Haversine + bounding-box distance (`km away`), automatic `latitude`/`longitude` inference from Google Maps (including `maps.app.goo.gl` / `goo.gl/maps` short links, `!3d!4d` place pins, and `ftid` embed pins), Apple Maps, OpenStreetMap, Waze, DMS coordinates, and Open Location Codes (Plus Codes) — activating the **🧭 Directions** button even when `latitude`/`longitude` are left blank — plus deterministic `Africa/Nairobi` (EAT) operating status (`OPEN_NOW`, `CLOSING_SOON`, `CLOSED`).
- **Minimalist Directory Cards → Rich Business Detail Modal & B2B Shortlist Builder**:
  - Cards stay clean and scannable; clicking opens a full modal with Website, Call, Click-to-WhatsApp, Email, 6 social platforms, 7-day EAT hours, and 1-tap launch into Google Maps or Waze.
- **7-Layer Public Form Anti-Spam Gate (`AntiSpamGate.php`) & Kenya DPA 2019 (`L2`) Compliance**:
  - Disguised `website` honeypot, `HMAC-SHA256` `< 4s` time-trap + `used_nonces` replay lock, JS human-interaction token, URL/shortener/ALL-CAPS/CamelCase-bot heuristic filter, strict session-bound `HMAC-SHA256` CSRF tokens (zero stateless fallback), per-IP + global rate limiting with keyed `HMAC-SHA256` IP pseudonymization, `Reply-To` sanitization, Section 29 Privacy Notice (`/privacy.php`), mandatory `18+` age attestation, owner-authenticated / email-confirmed reversible Section 40 listing pause plus Admin Takedown Review Queue (`/takedown.php`), and monitored daily DPA retention cron (`bin/purge-dpa-retention.php`).

## Local Development & Testing

All Python test and Playwright E2E commands run inside the `ds` conda environment:

```bash
conda activate ds

# Run PHP + Python unit tests (SQLite locally; both SQLite & MySQL 8.0 run in CI)
pytest -v

# Start local PHP server
php -S 127.0.0.1:8080 -t public

# Run Playwright Chromium E2E test suite against running server
BASE_URL=http://127.0.0.1:8080 python e2e/run.py
```

## Angryhosting (cPanel + PHP 8.x + MySQL) Deployment

1. Import `src/schema.sql` into the Angryhosting MySQL database.
2. Copy `.env.example` to `.env` above `public_html` (or inside the app root protected by `.htaccess`), set `APP_ENV=production`, `DB_DRIVER=mysql`, `DB_DRIVER_FALLBACK=none` (production fails closed with HTTP `503` on database maintenance and never falls back to SQLite or seeds sample businesses), configure your MySQL credentials and 64-character random hex secrets (`APP_HMAC_SECRET`, `IP_HASH_SECRET`, `CSRF_SECRET`), and set `SIMULATE_EMAILS=no`.
3. Point the domain document root to `public/`. Deployments run via verified Explicit FTPS (`ssl.create_default_context()` with `CERT_REQUIRED` + hostname verification in `.github/scripts/ftps_deploy.py`).
4. Configure two cPanel Cron Jobs:
   - Daily DPA 30-day storage-limitation & heartbeat sweep: `php /path/to/app/bin/purge-dpa-retention.php`
   - Every 5–10 minutes outbound email retry worker: `php /path/to/app/bin/process-email-outbox.php`
