# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

## [0.7.0] - 2026-11-10

### Added
- **Sunday Operating Hours (`HoursEngine.php`, `bootstrap.php`, `form-enhancements.js`, `/submit.php`, `/owner/edit-business.php`, `/admin/add-business.php`, `/admin/edit-business.php`)**: Added full Sunday operating hours (`hours_sun_open`, `hours_sun_close`, and a `Closed` toggle alongside Saturday's `Closed` toggle) across all 4 business submission and editing forms (`closes #46`, `closes #88`, `closes #89`).
- **Optional 3-to-5 Business Photo Gallery & Modal Carousel (`PhotoUploader.php`, `public/uploads/.htaccess`, `directory.js`, `app.css`)**: Added optional business photo uploads (up to 5 photos, 3–5 recommended) with client-side `<canvas>` compression (`<= 1200px`), server-side binary MIME + PHP `GD` re-encoding that strips EXIF GPS/camera metadata per Kenya DPA 2019, card thumbnail strips, and an interactive Photo Gallery Carousel inside the Business Detail Modal (`closes #90`, `closes #91`, `closes #92`, `closes #93`, `tag: v0.7.0`).

## [0.6.1] - 2026-11-10

### Fixed

- `public/owner/login.php`, `public/owner/claim.php`, `public/admin/index.php`, `src/Services/OwnerAuth.php`, `src/Config/Database.php`, `src/schema.sql`: removed the dead unconfigured Google SSO link on `/owner/login.php` that triggered `Google OAuth credentials are not yet set on this environment`, added `app_settings` storage so `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` can be configured directly from `/admin/index.php`, and enabled instant 1-click owner session activation on `/owner/claim.php` when arriving via a signed DM Claim Link (closes #44)

tag: `v0.6.1`

## [0.6.0] - 2026-11-10

### Added

- `src/Services/OwnerAuth.php`, `public/owner/login.php`, `public/owner/google-callback.php`, `public/owner/verify-link.php`, `public/owner/claim.php`, `public/owner/index.php`, `public/owner/edit-business.php`, `public/owner/logout.php`, `src/schema.sql`, `src/Config/Database.php`: added Business Owner Portal with Google OAuth 2.0 SSO (`Continue with Google`) and passwordless Email Magic Links, 30-day persistent signed session cookies (`bbiz_owner_session`), automatic post-registration linking of pre-populated businesses via corporate domain or public email match, 1-click signed DM Claim Links (`/owner/claim.php`) in the Admin console, auto-linking of approved submissions upon vetting purge, and instant self-service listing updates with automatic Admin alerts when a business name or category changes (closes #42)

tag: `v0.6.0`

## [0.5.2] - 2026-11-10

### Added

- `src/Services/LocationEngine.php`, `public/api/geocode.php`, `public/assets/js/directory.js`: added Kenyan street/estate geocoding (`/api/geocode.php` backed by Kenya-bounded OpenStreetMap Nominatim + `LocationEngine::KENYA_PLACE_INDEX` including `Marist Lane, Karen`), `watchPosition` Wi-Fi accuracy refinement, coarse laptop ISP GeoIP detection (`accuracy > 400m` e.g. Pumwani), an inline street/area starting-location search box (`Set Start`), and a draggable blue start pin on the Leaflet map (closes #39)

### Changed

- `src/Services/LocationEngine.php`: replaced compass-bearing directions (`Head northwest`, `Head south`, etc.) with consolidated Kenyan left/right road transitions (`Start on ... and keep straight`, `Turn left onto ...`, `Turn right onto ...`, `At the roundabout, turn left/right onto ...`, `Continue straight onto ...`), merging consecutive segments on the same road and absorbing micro-roundabout crossings (`< 120m`) (closes #40)

tag: `v0.5.2`

## [0.5.1] - 2026-11-10

### Fixed

- `public/assets/js/directory.js`: removed the silent `nairobi_cbd` (Museum Hill / CBD) fallback in `resolveOriginCoordinates()` so route directions and `Near Me` strictly capture the user's live browser location via `navigator.geolocation` (`captureBrowserLocation()`), retrying with standard/network accuracy if high-accuracy GPS times out (closes #37)
- `public/assets/js/directory.js`: isolated the selected business on the Leaflet map whenever a user clicks `Directions` or selects a business so all other business pins are blanked out, showing all pins only on the generic directory view (closes #37)

tag: `v0.5.1`

## [0.5.0] - 2026-11-10

### Added

- `src/Services/CategoryMatcher.php`, `public/submit.php`, `public/admin/add-business.php`, `public/admin/edit-business.php`: mapped regulated Level 2 specialties (`REGULATED_SPECIALTIES`) to their Kenyan statutory regulators (e.g. LSK, ICPAK, ICPSK, IHRM, IRA, CIArb, KIPI, KMPDC, PPB, KMLTTB, PCK, KCPC, CBK, CA, BORAQS, EBK, NCA, EARB, ISK/VRB, EPRA, CMA, SASRA, RBA, PCPB, KVB, KRA, MoE, TVETA, WRA, TRA, PSRA) so the pre-filled **Professional Regulator / Statutory Body** field is revealed and required only when a regulated specialty is chosen, hidden and cleared for unregulated businesses, and the license number input is omitted (closes #33)
- `public/favicon.svg`, `public/favicon.ico`, `public/favicon-32x32.png`, `public/favicon-192x192.png`, `public/apple-touch-icon.png`, `public/assets/img/bb-icon-512.png`, `public/assets/img/og-preview.png`, `public/site.webmanifest`, `src/bootstrap.php`, `public/assets/css/app.css`: added borderless green-to-maroon blended `BB` crest (`#114B3E` smoothly blending into `#7B1113` with no solid boundary), full favicon suite across all public and admin pages, Schema.org Organization logo JSON-LD, and 1200×630 + 512×512 OpenGraph/Twitter preview cards for WhatsApp and search engine previews (closes #34)

### Changed

- `src/Services/LocationEngine.php`, `public/assets/js/directory.js`: simplified route directions from granular turn-by-turn micro-maneuvers to a looser, high-level **General Route Overview** highlighting the compass heading, main arterial road corridors, and destination neighborhood/landmark cues alongside 1-click Google Maps and Waze launchers (closes #35)

tag: `v0.5.0`

## [0.4.0] - 2026-11-10

### Added

- `public/assets/js/consent-metrics.js`, `public/api/track-metric.php`, `public/admin/metrics.php`, `public/privacy.php`: added Kenya DPA 2019 compliant persistent `Accept` / `Reject All` cookie consent banner (persisted in `localStorage` and 365-day `SameSite=Lax` cookie), strict pre-consent/rejection blocking on `/api/track-metric.php` and `/api/track-click.php`, and the Admin Usage Metrics & Outbound Leads console (closes #32)
- `src/schema.sql`, `src/Config/Database.php`, `src/Services/VettingPurger.php`, `public/admin/index.php`, `public/admin/edit-business.php`, `public/api/businesses.php`: separated immutable unique `business_id` (`BBIZ-XXXXXXXX`) from dynamic sequential `business_number` (`1..N`), automatically incrementing on add and decrementing/renumbering with zero gaps on delete (closes #31)
- `.github/workflows/deploy-ftp.yml`: automated Explicit FTPS continuous deployment to Angryhosting mapping `src/`, `bin/`, `storage/`, and `.env` outside the webroot (`/busherianbiasharas.com/www/`) and `public/*` into `/busherianbiasharas.com/www/html/` (closes #29)

### Fixed

- `src/Config/Database.php`, `src/Services/AdminAuth.php`, `public/api/businesses.php`, `public/admin/index.php`: auto-execute `src/schema.sql` on first MySQL connection, use distinct named PDO placeholders for native MySQL (`ATTR_EMULATE_PREPARES => false`), and support both `public/` and `html/` webroot layouts (closes #29)

tag: `v0.4.0`

## [0.3.0] - 2026-11-10

### Added

- `public/admin/index.php`, `public/admin/add-business.php`, `public/admin/edit-business.php`, `src/Services/VettingPurger.php`, `public/api/autofill-url.php`: full Admin Business Management Console enabling administrators to directly add known Busherian businesses from a Website URL (auto-extracting metadata and publishing immediately without the public verification queue), as well as search, edit all fields, unpublish/take down, or permanently delete any business record (closes #20, closes #28)
- `public/assets/vendor/leaflet/`, `public/assets/js/directory.js`, `public/index.php`: replaced the synthetic SVG grid with a real interactive Leaflet + OpenStreetMap map displaying streets, features, neighborhoods, and place names alongside custom Busherian pins (closes #23)
- `public/index.php`, `public/assets/css/app.css`, `public/assets/js/directory.js`: added an explicit `✕` clear-search button inside the right edge of the directory search input (closes #24)
- `src/Services/CategoryMatcher.php`: added SasaSignal (`https://sasasignal.com/`) under `Technology, Software & Digital -> Bulk SMS, Voice, USSD & Communications APIs` (closes #25)
- `src/Services/LocationEngine.php`, `public/api/route-directions.php`, `public/assets/js/directory.js`, `public/index.php`: added one-click live distance calculation, road route polyline rendering on the Leaflet map, turn-by-turn street directions, origin switcher, and 1-click Google Maps / Waze navigation launchers for the business in focus (closes #26)

### Changed

- `public/index.php`, `public/assets/js/directory.js`, `public/submit.php`, `public/privacy.php`, `public/admin/index.php`, `public/admin/edit-business.php`, `src/Services/Mailer.php`: simplified school filter pills and badges to `AHS` and `AGHS`, removing ` — Bush` (closes #22)
- `src/Services/CategoryMatcher.php`, `public/assets/js/directory.js`: set exact school affiliations (`AGHS` for Bustani Bistro, Harriet's Botanicals, and Mambo Pambo; `AHS` for the remaining 9 businesses) and removed the redundant `✓ Verified Busherian (...)` pill from card headers so school membership surfaces directly via the `AHS` / `AGHS` filter pills (closes #27)

### Removed

- `src/Services/CategoryMatcher.php`: removed Mjenzi from the directory after post-verification review confirmed the submitted URL belonged to a different company (closes #20)

tag: `v0.3.0`

## [0.2.1] - 2026-11-10

### Changed

- `public/assets/js/directory.js`, `public/index.php`: removed internal vetting/purge notices and admin links from the public-facing directory modal and footer so visitors browsing the directory only see public business information (closes #18)

tag: `v0.2.1`

## [0.2.0] - 2026-11-10

### Changed

- `src/Services/CategoryMatcher.php`, `e2e/test_smoke.py`: removed all fictional demo businesses and seeded the initial directory with the 12 real Busherian businesses — The Green Table Pizza, The Last Village Lodge, Tumaini Gardens Resort Isinya, Naishola Gardens, Bustani Bistro, Branfoot Enterprises Limited, Akili AI, Prescott Engineering Solutions, Mjenzi, Harriet's Botanicals, Mambo Pambo, and Lasambara Wines (closes #16)

tag: `v0.2.0`

## [0.1.1] - 2026-11-10

### Added

- `src/Services/AdminAuth.php`, `public/admin/login.php`: constant-time `hash_equals()` admin session authentication guard with session regeneration, `HttpOnly; SameSite=Strict` cookies, and brute-force rate limiting on `/admin/index.php` (closes #8, closes #10)
- `src/Services/VettingPurger.php`, `bin/purge-dpa-retention.php`: automated 30-day storage-limitation purge (`enforceRetentionCeilings()`) for abandoned unreviewed rows in `business_vetting_queue` and 30-day redaction of `email_outbox` bodies (closes #13)
- `src/Services/WebsiteEnricher.php`: `INDRA`-style per-field evidentiary provenance tracking (`field_provenance`) on Website URL Auto-Fill responses, plus root `.htaccess` and `public/.htaccess` rules for Angryhosting cPanel deployment (closes #14)

### Fixed

- `public/takedown.php`: enforced all 7 layers of `AntiSpamGate::verifySubmission()` on Section 40 takedown requests to prevent automated mass-quarantine DoS (closes #11)
- `public/api/autofill-url.php`, `public/api/track-click.php`, `public/submit.php`: added atomic per-IP rate limiting via `AntiSpamGate::checkAndRecordRateLimit()` and scrubbed raw exception output (closes #12)

tag: `v0.1.1`

## [0.1.0] - 2026-11-09

### Added

- Initial project scaffold: repo, branch protection, CI (`unit` and `e2e` gates on every PR), versioning, and issue-first workflow (closes #1)
- 2-Level Business Taxonomy (14 Industry Sectors x 100+ pre-populated Specialties), Kenyan/Swahili synonym search expansion, and self-service `+ Add New` custom category engine with Jaro-Winkler fuzzy deduplication (closes #2)
- One-Time Alumni Vetting with explicit Section 29 privacy banner, confirmed AHS (13 houses) and AGHS (9 houses) dropdowns, "School Number" terminology, isolated `business_vetting_queue` table, and immediate cryptographic disposal (`DELETE FROM business_vetting_queue`) upon review with zero House/Year exposure in the public directory (closes #3)
- One-Click Website URL Auto-Fill Engine (`WebsiteEnricher.php` + `/api/autofill-url.php`) extracting Schema.org JSON-LD, OpenGraph, contacts, coordinates, hours, and suggested categories during business registration, hardened with strict L1 SSRF protection (closes #4)
- Responsive hybrid split-screen directory UI with sticky Leaflet/OpenStreetMap map, Haversine + bounding-box distance calculator, Open Location Code (Plus Code) & Google Maps URL resolver, deterministic `Africa/Nairobi` (EAT) `HoursEngine`, rich Business Detail Modal, and B2B Procurement Shortlist exporter (closes #5)
- Full 7-layer Public Form Anti-Spam Gate (`AntiSpamGate.php`), durable `email_outbox`, and signed HMAC-SHA256 one-click Admin Triage notifications to `admin@busherianbiasharas.com` (closes #6)
- Kenya Data Protection Act 2019 (`L2`) compliance: 100% self-hosted vendor assets under `public/assets/vendor/` (zero external CDN requests on load), Section 29 Privacy Notice (`privacy.php`), mandatory 18+ age attestation, and Section 40 instant quarantine (`takedown.php`) (closes #7)

tag: `v0.1.0`
