# Busherian Biasharas (`busherianbiasharas.com`)

Verified Alumni Business Directory of **The Alliances** (**Alliance High School [AHS]** and **Alliance Girls High School [AGHS]**, both colloquially known as **Bush**).

## Key Features

- **2-Level Business Taxonomy (14 Industry Sectors × 100+ Specialties)**: Cascading Level 1 Industry → Level 2 Specialty filters, Kenyan/Swahili synonym search (*wakili*, *daktari*, *fundi*, *mjengo*, *chemist*, *nyama choma*), and self-service `+ Add New` specialty submission with live Jaro-Winkler fuzzy deduplication.
- **One-Click Website URL Auto-Fill on Registration (`WebsiteEnricher.php`)**: When registering a new business (`submit.php` or Admin), paste the business's website URL to automatically extract Business Name, Tagline, Description, Phone, WhatsApp, Email, Social Media profiles, Physical Address/Coordinates, Operating Hours, and suggested Level 1 & Level 2 categories via Schema.org `JSON-LD`, OpenGraph, and DOM heuristics — guarded by strict SSRF protection (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` + `CURLOPT_RESOLVE` IP pinning).
- **One-Time Alumni Vetting with Immediate Data Disposal (`VettingPurger.php`)**:
  - Registration Step 1 collects **School** (`AHS` or `AGHS`), **House** (compulsory, using the confirmed AHS & AGHS house lists), **Year Left** (compulsory), and **School Number** (optional — strictly termed *School Number*, never *Admission Number*).
  - **Strict Privacy Guarantee**: `House`, `Year Left`, and `School Number` are stored exclusively in an isolated `business_vetting_queue` table, **never appear anywhere in the public directory**, and are **permanently hard-deleted (`DELETE FROM business_vetting_queue`)** the instant the listing is approved or rejected.
- **Interactive Split-Screen Map, Maps Share-Link Pin Unroller, Plus Code Resolver & `Africa/Nairobi` HoursEngine**:
  - Real-time Haversine + bounding-box distance (`km away`), automatic `latitude`/`longitude` inference from Google Maps (including `maps.app.goo.gl` / `goo.gl/maps` short links, `!3d!4d` place pins, and `ftid` embed pins), Apple Maps, OpenStreetMap, Waze, DMS coordinates, and Open Location Codes (Plus Codes) — activating the **🧭 Directions** button even when `latitude`/`longitude` are left blank — plus deterministic `Africa/Nairobi` (EAT) operating status (`OPEN_NOW`, `CLOSING_SOON`, `CLOSED`).
- **Minimalist Directory Cards → Rich Business Detail Modal & B2B Shortlist Builder**:
  - Cards stay clean and scannable; clicking opens a full modal with Website, Call, Click-to-WhatsApp, Email, 6 social platforms, 7-day EAT hours, and 1-tap launch into Google Maps or Waze.
- **7-Layer Public Form Anti-Spam Gate (`AntiSpamGate.php`) & Kenya DPA 2019 (`L2`) Compliance**:
  - Disguised `website` honeypot, `HMAC-SHA256` `< 4s` time-trap + `used_nonces` replay lock, JS human-interaction token, URL/shortener/ALL-CAPS/CamelCase-bot heuristic filter, atomic per-IP + global rate limiting with keyed `HMAC-SHA256` IP pseudonymization, `Reply-To` sanitization, 100% self-hosted assets (zero external CDN IP leaks on load), Section 29 Privacy Notice (`/privacy.php`), mandatory `18+` age attestation, and Section 40 instant quarantine (`/takedown.php`).

## Local Development & Testing

```bash
# Run PHP + Python unit tests
pytest -v

# Start local PHP server
php -S 127.0.0.1:8080 -t public

# Run E2E test suite against running server
BASE_URL=http://127.0.0.1:8080 python e2e/run.py
```

## Angryhosting (cPanel + PHP 8.x + MySQL) Deployment

1. Import `src/schema.sql` into the Angryhosting MySQL database.
2. Copy `.env.example` to `.env` above `public_html` (or inside the app root protected by `.htaccess`), set `DB_DRIVER=mysql`, configure your MySQL credentials and 64-character random hex secrets (`APP_HMAC_SECRET`, `IP_HASH_SECRET`, `CSRF_SECRET`), and set `SIMULATE_EMAILS=no`.
3. Point the domain document root to `public/`.
