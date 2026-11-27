# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

## [0.42.0] - 2026-11-27

### Added
- **Owner Google Single Sign-On (SSO) Activation (#466)**:
  - Configured verified Google OAuth 2.0 Web Client credentials (`GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`) across environment configuration and database settings.
  - Activated the **"🌐 Continue with Google"** button on `/owner/login.php` enabling one-click passwordless sign-in for verified alumni business owners.
  - Retains 30-day authenticated session persistence, automatic business linking by owner email, and one-time 18+ adult attestation compliance with Kenya DPA 2019.
  - Added automated FTPS synchronization in deployment workflows (`deploy-ftp.yml` and `deploy-preview.yml`) so Google SSO credentials persist seamlessly across production and preview server environments.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.42.0`.

## [0.41.1] - 2026-11-27

### Fixed
- **Resolve AngryHosting SMTP Relay Failure & Port 465 Timeout (#460, #461)**:
  - Mapped default and `mail.busherianbiasharas.com` SMTP host to `mx1.angryhosting.com` (the verified dedicated TLS mail relay on AngryHosting with port 465 SSL open, valid certificate, and authenticated user credentials).
  - Added automatic fallback to `mx1.angryhosting.com:465` if the primary vanity SMTP host times out or fails connection.
  - Added support for `SMTP_TLS_PEER_NAME` / `SMTP_PEER_NAME` and automatic SNI certificate alignment.
  - Added `Mailer::getAdminEmail($pdo)` defaulting to `gitonga@gmail.com` when configured or when `ADMIN_EMAIL` in `.env` is unmonitored.
- **Manual Outbox Retry Backoff Window Bypass & Accurate Triage Reporting (#460, #462)**:
  - Added `$forceImmediate` flag to `Mailer::processRetryQueue()` and `dispatchOutboxRow()` so manual admin console retries (`admin_action === 'retry_outbox'`) and CLI retries (`bin/process-email-outbox.php --force`) immediately attempt waiting rows without being blocked by exponential backoff (`next_retry_at`).
  - Separated `expired` authentication challenges from `failed` delivery errors in `processRetryQueue()` result and admin console flash messages.
- **Proactive SMTP Relay Health Check & Real-Time Alert Sentinel (#460)**:
  - Added `Mailer::checkSmtpHealth()` performing proactive SMTP TLS handshake verification on the relay.
  - Added `bin/test-email-health.php` CLI sentinel for cPanel cron integration, alerting on STDERR/exit code 1 whenever SMTP connectivity or outbox delivery fails.
  - Integrated SMTP health monitoring into remote WebCron (`public/api/cron.php`) and `.github/workflows/scheduled-cron.yml` (every 15 minutes), failing workflow steps immediately so GitHub dispatches real-time push/email alerts to the repository owner upon any failure.
  - Added real-time SMTP diagnostic panel and live test email dispatcher in Admin Console (`/admin/index.php`).
- **Preview Subdomain .env Resolution & Admin Credentials Inheritance (#464)**:
  - Added `dirname($appRoot) . '/www/.env'` and `dirname($appRoot) . '/public_html/.env'` candidate lookups to `PreprodSandbox::$prodEnvCandidates` and `Database::loadEnv()`.
  - Added automated FTPS `.env` provisioning in `.github/workflows/deploy-preview.yml`, syncing `/busherianbiasharas.com/preview/.env` directly from `/busherianbiasharas.com/www/.env` with strict sandbox isolation overrides (`APP_ENV=preview`, `DB_DRIVER=sqlite`, `SIMULATE_EMAILS=yes`, `APP_URL=https://preview.busherianbiasharas.com`).
  - Restores 1-click signed admin handoff from live production (`/admin/index.php`) and direct token/password login on `preview.busherianbiasharas.com`.

## [0.41.0] - 2026-11-26

### Added & Enhanced
- **Non-Profit, Charity & Social Impact 16th Industry Sector (#459)**:
  - Added dedicated 16th Industry Sector **Non-Profit, Charity & Social Impact** (`non-profit-charity-social-impact`) with `heart-handshake` icon to `CategoryMatcher::DEFAULT_TAXONOMY`.
  - Expanded taxonomy with 8 pre-populated specialties:
    - *Charitable Trust & Philanthropic Foundation*
    - *Non-Profit & Community-Based Organization (CBO/NGO)*
    - *Youth Mentorship, Bursary & Scholarship Fund*
    - *Humanitarian Relief & Community Outreach*
    - *Environmental, Climate & Wildlife Conservation*
    - *Civic Education, Human Rights & Public Advocacy*
    - *Social Enterprise & Community Development*
    - *Faith-Based & Pastoral Community Mission*
  - Configured Kenyan statutory regulator presets in `CategoryMatcher::REGULATED_SPECIALTIES` for NGOs/CBOs (*NGOs Co-ordination Board / PBORA*, *Department of Social Development*, *BRS Company Limited by Guarantee*) and Charitable Trusts (*Registrar of Documents - Trustees Perpetual Succession Act*).
  - Enriched `CategoryMatcher::SYNONYM_LEXICON` with search terms across English and Swahili: `charity`, `charitable`, `foundation`, `ngo`, `cbo`, `nonprofit`, `non-profit`, `mchango`, `harambee`, `msaada`, `bursary`, `bursaries`, `scholarship`, `scholarships`, `philanthropy`, and `humanitarian`.
  - Added `social_impact_foundation` ecosystem cluster to `NeedGraph::ECOSYSTEM_CLUSTERS` and mapped curated upstream/downstream adjacencies (Trust Deed legal structuring via Advocates, KRA Sec 13(2) tax exemption audits via CPAs, impact pitch decks via PR agencies, and digital donor giving portals via Web Design).
  - Bumped `Database::SCHEMA_INIT_VERSION` to `2026-11-v41-charity-nonprofit-sector` to ensure idempotent automated taxonomy upgrades on all SQLite/MySQL environments.
  - Added unit test assertions (Section 68 in `tests/run_unit_tests.php`) validating 16-sector count, slug/icon metadata, statutory regulator mappings, synonym expansion, and NeedGraph adjacencies.
  - Aligned service worker cache version to `bbiz-pwa-v0.41.0`.

## [0.40.22] - 2026-11-24

### Added & Enhanced
- **Automated GitHub Actions WebCron Scheduler & Protected API Endpoint (#458)**:
  - Added authenticated, non-blocking WebCron endpoint `public/api/cron.php` accepting `POST`/`GET` requests authorized with `CRON_SECRET` or `APP_HMAC_SECRET` (`Bearer` token, `X-Cron-Key`, or `?token=`).
  - Implemented non-blocking OS concurrency lock via `bbiz_acquire_cli_worker_lock('scheduled_cron_worker')` to prevent overlapping runs.
  - Automatically executes the Kenya DPA 2019 30-day retention storage-limitation sweep (`enforceRetentionCeilings`), updates `cron_heartbeats` (`recordCronHeartbeat`), processes bounded email outbox retry batches (`processRetryQueue`), and drains durable media deletions (`drainDurableMediaDeletions`).
  - Added scheduled GitHub Actions workflow `.github/workflows/scheduled-cron.yml` running every 15 minutes (`*/15 * * * *`) and on manual `workflow_dispatch`, completely eliminating dependency on spartan shared hosting cPanel cron tools.
  - Added unit test assertions (Section 67 in `tests/run_unit_tests.php` and `tests/test_unit.py`) validating token authentication, concurrency protection, and cron health status.
  - Aligned service worker cache version to `bbiz-pwa-v0.40.22`.

## [0.40.21] - 2026-11-24

### Fixed
- **Admin Console Pagination Next Navigation & Span Tag Closure**:
  - Corrected unclosed `<span ...>&larr; Prev</a>` HTML syntax error to `</span>` on line 791 of `public/admin/index.php`.
  - Resolved browser rendering bug where unclosed disabled span trapped subsequent pagination buttons (`1`, `2`, and `Next &rarr;`) inside `pointer-events: none` and `opacity: 0.45`, restoring clickability on `Next &rarr;` and subsequent page numbers.
  - Added regression test asserting valid markup in `tests/run_unit_tests.php`.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.21`.

## [0.40.20] - 2026-11-23

### Fixed & Enhanced
- **Granular Founder & Co-Founder Attribution Opt-In (#457)**:
  - Allowed founders and co-founders to select which details to display publicly upon opt-in: Full Name is compulsory, while School affiliation (AHS / AGHS) and Completion Year are optional.
  - Added checkboxes `display_founder_school` and `display_founder_year` with real-time `#simple-founder-preview` in `public/submit.php`.
  - Added optional school dropdown (`Do not display school (optional)`) and optional completion year in Section 2, `public/owner/edit-business.php`, and `public/admin/edit-business.php`.
  - Updated `VettingPurger::updateBusinessByOwner` and `VettingPurger::updateBusiness` to enforce compulsory founder name when opting in and permit explicitly setting or clearing school/year to `null`.
  - Added `formatFounderAttributionText` in `public/assets/js/directory.js` and updated `public/index.php` to seamlessly render Name only (`Founded by [Name]`), Name + School (`(AHS)`), Name + Year (`('98)`), or Name + School + Year (`(AHS '98)`).
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.20`.

## [0.40.19] - 2026-11-23

### Security & Hardening
- **Kenya DPA 2019 & Adversarial Security Residual Remediations (#451, #452, #453, #454, #455)**:
  - **Mask Contacts & Fuzz Coordinates in SSR Listing Payload (#452, Residual H-01)**: Serialized contacts with `PhoneNormalizer::maskPhoneNumber` and bounded coordinates to 3 decimal places (~110m) for bulk listings in `public/index.php`, keeping parity with `/api/businesses.php` bulk directory responses.
  - **Strict Affirmative Opt-in for Founder Attribution (#453, Audit H-04)**: Enforced in `public/submit.php` that founder attribution is enabled ONLY when `show_founder_publicly` is affirmatively checked, preventing attribution bypass when advanced details are toggled.
  - **Token-Bucket Rate Limiter & Name Screening on Recommendations (#454, Audit H-05)**: Applied `AntiSpamGate::checkAndRecordRateLimit` (15 req/60s, 45/120s) returning HTTP 429 and screened recommender names for spam URLs in `public/api/recommend.php`.
  - **Prioritize Stable Google Sub & Record Age Attestation (#455, Audit M-08)**: Updated `public/owner/google-callback.php` to check `google_sub` prior to email fallback, added `age_attested_at` schema column to `business_owners`, and recorded timestamp upon adult confirmation.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.19`.

## [0.40.18] - 2026-11-23

### Fixed & Enhanced
- **Cascading Country to Subdivisions (States/Counties/Provinces/Districts) Filter on Mobile Drawer (#401)**:
  - Fixed mobile drawer subdivision selector by consuming dynamic country place hierarchy directly from `PLACE_HIERARCHY`.
  - Replaced hardcoded mobile country select options with dynamic country list prioritizing Kenya and listing all 196+ jurisdictions alphabetically with dynamic subdivision unit headers.
  - Synchronized country and subdivision filter state, active-filter styles (`.sheet-select.active-filter`), and desktop-to-mobile reset handlers across both views.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.18`.

## [0.40.17] - 2026-11-23

### Security & Hardening
- **Integrate Company Logo Uploads into Pending Media Deletions and Takedown Quarantine (#442, Parent #438)**:
  - Extended `PhotoUploader::quarantineBusinessLogo` and `PhotoUploader::restoreQuarantinedBusinessLogo` to relocate company logo files into private quarantine (`storage/quarantined_media/<id>/`) upon DPA takedown or vetting rejection, and restore them upon approval.
  - Routed logo deletions and replacements through `pending_media_deletions` journaling in `VettingPurger::enqueueDurableMediaDeletion`, preventing premature unlinks before database commit.
  - Added transaction rollback protection for newly uploaded logo files across `updateBusiness` and `updateBusinessByOwner` to prevent orphaned disk files.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.17`.

## [0.40.16] - 2026-11-23

### Security & Hardening
- **Add Server-Side Session Revocation on Admin Logout for 30-Day Remember-Me Credentials (#441, Parent #438)**:
  - Created persistent `admin_remember_tokens` schema with hashed validator (`validator_hash`), user agent hash, server auth version, and absolute expiry across SQLite and MySQL.
  - Implemented server-side token revocation on admin logout and global session invalidation (`AdminAuth::logout`, `AdminAuth::revokeAllAdminSessions`), guaranteeing that copied or previously stolen remember-me cookies fail closed immediately.
  - Added cryptographic validator token rotation upon every successful session renewal to prevent replay of old cookie tokens.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.16`.

## [0.40.15] - 2026-11-23

### Security & Hardening
- **Apply Token-Bucket Rate Limiter to Public Website Enrichment and Map Proxy Endpoints (#440, Parent #438)**:
  - Applied token-bucket rate limiting (`AntiSpamGate::checkAndRecordRateLimit`, 10 req / 15m, 30 / 24h, 60 / 1h) to `public/api/enrich-website.php` aligned with `public/api/autofill-url.php`.
  - Maintained strict token-bucket rate limiting on map proxy and routing endpoints (`public/api/route-directions.php`, `public/api/geocode.php`).
  - Verified 5-second fetch timeouts, 2 MB payload caps, and SSRF private IP validation across all redirects.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.15`.

## [0.40.14] - 2026-11-23

### Security & Hardening
- **Route All OTP and Transactional Email Generation Through AntiSpamGate and Authenticated SMTP (#439, Parent #438)**:
  - Added strict per-IP OTP rate limits (`OTP_IP_LIMIT_15M = 5`, `OTP_IP_LIMIT_24H = 15`) in `AntiSpamGate::checkAndRecordOutboundMailQuota` to prevent automated brute-force or bulk OTP dispatch to multiple accounts from a single IP.
  - Eliminated subject-based bypass for local unauthenticated PHP `mail()` fallback in `Mailer::dispatchOutboxRow`, enforcing that transactional OTP and auth emails fail closed if SMTP delivery fails.
  - Hardened `Mailer::sendViaAuthenticatedSmtp` in production to reject unauthenticated credentials (`SMTP_USER` / `SMTP_PASS` empty) and enforce mandatory TLS certificate peer and hostname verification.
  - Hardened `Mailer::isPhpMailFallbackEnabled` to return `false` unconditionally in production environments.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.14`.

## [0.40.13] - 2026-11-23

### Security & Data Protection
- **Implement PWA Service Worker Cache Eviction for Withdrawn and Quarantined Listings (#437, Parent #434)**:
  - Added cache tombstone synchronization in `public/sw.js` and `public/assets/js/directory.js` to immediately purge withdrawn, rejected, or quarantined listings under Kenya DPA 2019 s. 40 (Right to Erasure & Rectification).
  - Emitted `410 Gone` with `X-BBiz-Listing-Status: quarantined` and `X-BBiz-Tombstone: 1` when specific quarantined/rejected listings are queried, and `404 Not Found` for nonexistent listings.
  - Added HTTP cache control headers on directory JSON feeds: `Cache-Control: public, max-age=180, stale-while-revalidate=300, must-revalidate` for unauthenticated feeds, `X-BBiz-Directory-Version`, `X-BBiz-Tombstones`, and `Cache-Control: private, no-store, no-cache` for authenticated visitors.
  - Implemented 24-hour TTL expiry (`MAX_CACHE_AGE_MS = 86400000`) and message listener (`EVICT_TOMBSTONES`, `PURGE_LISTING`) in `public/sw.js` to ensure stale listing snapshots do not persist indefinitely in offline CacheStorage.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.13`.

## [0.40.12] - 2026-11-23

### Security & Data Protection
- **Add Rate Limiting and Click-to-Reveal Obfuscation for Unauthenticated Directory API (#436)**:
  - Enforced per-IP rate limiting (120 req / 15m) on `public/api/businesses.php` using `AntiSpamGate::checkAuthAttemptAllowed`.
  - Added `PhoneNormalizer::maskPhoneNumber` to obfuscate raw phone and WhatsApp numbers (`+254 7XX XXXX85`) on unauthenticated bulk directory queries.
  - Fuzzed geographic coordinates to 3 decimal places (~110m neighbourhood resolution) on unauthenticated bulk directory responses to prevent residential harvesting.
  - Enabled exact coordinates and unmasked E.164 phone numbers for single listing views (`?id=...` or `?slug=...`), authenticated sessions, or explicit user reveal interaction (`?reveal=1`).
  - Added click-to-reveal handler `onCardContactClick` in `public/assets/js/directory.js` to hydrate dialable numbers dynamically on human interaction.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.12`.

## [0.40.11] - 2026-11-23

### Security & Data Protection
- **Align Privacy Notice and Submission Contract for Optional Founder Attribution (#435)**:
  - Enforced strictly opt-in founder attribution in `public/submit.php`, setting `founder_role = 'none'` and clearing personal identifiers (`founder_name`, `founder_school`, `founder_year`) when publication consent is omitted or unselected.
  - Added explicit voluntary publication notice and consent disclosure under Kenya DPA 2019 ss. 28 & 29 on the submission form.
  - Updated `public/privacy.php` and `public/charter.php` (`src/bootstrap.php`) to clearly distinguish between public business entity data and optional founder personal data.
  - Added self-service founder attribution management, editing, and instant revocation to `public/owner/edit-business.php` and `public/admin/edit-business.php`.
  - Updated `VettingPurger::updateBusinessByOwner` and `VettingPurger::updateBusiness` to support persisting and revoking founder attribution fields.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.11`.

## [0.40.10] - 2026-11-23

### Added
- **Populate Business Card Banners with Official Company Logos (#431)**:
  - Added official company logos in `public/assets/img/logos/` for Branfoot Enterprises Limited, Bustani Bistro, Harriet's Botanicals, Impel Web Studio, Nifty Payroll, SasaSignal, TAG Creative, and Ujuzi Software Solutions Ltd.
  - Added `business_logos_v1` database migration in `Database.php` and bumped `SCHEMA_INIT_VERSION` to `2026-11-v40-business-logos` to populate `logo_path` across businesses.
  - Preserved existing user-uploaded logos and fallback school initials badges where logos are unset.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.10`.

## [0.40.9] - 2026-11-23

### Added
- **User and Admin Editable Company Logo Management (#429)**:
  - Enabled business owners to upload, preview, replace, and remove their official company logo via the Owner Portal (`public/owner/edit-business.php`).
  - Added company logo management controls to the Admin Console (`public/admin/edit-business.php` and `public/admin/add-business.php`).
  - Added optional company logo upload during initial business vetting submission (`public/submit.php`).
  - Implemented `PhotoUploader::processBusinessLogo` with transparency preservation for PNG/WebP, decompression-bomb guards, 8 MB per-file limits, secure upload path resolution, and automatic deletion of superseded logo files.
  - Added live client-side logo preview and removal toggle in `public/assets/js/form-enhancements.js`.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.9`.

## [0.40.8] - 2026-11-23

### Added
- **Company Logo in Card & Profile Banners in Place of Abbreviation (#427)**:
  - When a business has an official logo (`b.logo_path`), the directory listing card renders the company logo inside the school-tinted hero banner (`.card-banner-logo`, `data-testid="biz-card-banner-logo"`) instead of falling back to text initials.
  - Detail view profile showcase renders the company logo inside `.photo-branded-banner` when no premise gallery photos are present.
  - Hydrated `logo_path` in `public/api/businesses.php` directory API payload and `public/index.php` SSR payload.
  - Configured Akili AI (`akili-ai`) sample logo showcase for preview testing on `preview.busherianbiasharas.com`.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.8`.

## [0.40.7] - 2026-11-23

### Added
- **30-Day Persistent Admin Session on Device (#424)**:
  - Added a "Remember me on this machine for 30 days" checkbox on the Admin Sign In screen (`public/admin/login.php`, `data-testid="admin-remember-me-checkbox"`).
  - Implemented secure HMAC-SHA256 bound persistent cookie authentication in `src/Services/AdminAuth.php` (`bbiz_admin_remember`), restoring admin sessions seamlessly across browser restarts without re-entering credentials for 30 days.
  - Hardened cookie validation against credential rotation, session revocation, and signature tampering.

### Changed
- **Admin Console Default 25 Items with 50/100 Splits (#425)**:
  - Updated admin record management pagination (`public/admin/index.php`) to display 25 business items by default (up from 10).
  - Updated per-page selector splits to `[25, 50, 100]`.
  - Bumped PWA service worker cache version to `bbiz-pwa-v0.40.7` in `public/sw.js`.

## [0.40.6] - 2026-11-23

### Added
- **Direct Website Action Button on Business Detail Page (#422)**:
  - Added an interactive `Website` action button (<span>🌐</span> Website) in `.detail-cta-bar` alongside WhatsApp, Call, Directions, and Share on the detail view (`public/index.php`, `public/assets/js/directory.js`).
  - Added a dedicated official website row in Column 2 under Contact & Location with outbound referral attribution (`utm_source=busherianbiasharas.com`).
  - Enabled `flex-wrap: wrap` on `.detail-cta-bar` in `public/assets/css/app.css` to guarantee graceful wrapping across mobile devices and small viewports.
  - Added regression test suite coverage in `tests/test_streamlined_directory_features.php`.

## [0.40.5] - 2026-11-23
### Changed
- **School Banner Color Harmony with Badge Tints & Typography (#425)**:
  - Aligned blank initial banner backgrounds to the exact pastel tints of the school badges: `#EEF0FA` for AHS and `#EBF5ED` for AGHS, with matching subtle borders.
  - Set bold initials typography in school brand colors: `#2E3192` for AHS and `#1E5E2A` for AGHS.
  - Bumped PWA cache to `bbiz-pwa-v0.40.5` in `public/sw.js` and updated `VERSION` to `0.40.5`.

## [0.40.4] - 2026-11-23
### Added
- **Interactive Column Header Sort Controls in Admin Console (#424)**:
  - Added clickable sort arrows (▲ ascending / ▼ descending) to table column headers in `public/admin/index.php` ("2. Manage Existing Business Records").
  - Enabled multi-column sorting by Business Name (`b.name`), Record Number (`b.business_number`), Category (`sec.name, spec.name`), Location (`b.city_county, b.area_neighborhood`), and Status (`b.status`).
  - Active sort column and direction are visually highlighted in dark forest green (`#114B3E`), with inactive arrows in muted grey (`#9CA3AF`).
  - Seamlessly preserved search filter (`q`), status filter (`status`), records per page (`per_page`), and pagination query state during sorting.
  - Aligned school initial banner colors to exact AHS Blue (`#2E3192`) and AGHS Green (`#1E5E2A`) badge shades in `public/assets/css/app.css`.
  - Added unit regression test coverage in `tests/test_streamlined_directory_features.php` (Test 18).
  - Bumped PWA cache to `bbiz-pwa-v0.40.4` in `public/sw.js` and updated `VERSION` to `0.40.4`.

## [0.40.3] - 2026-11-23
### Changed
- **Blank School Initials Banner in Lieu of Photos for Equal Height & Placement (#423)**:
  - Added smart business initials extractor `getBusinessInitials(name)` in `public/assets/js/directory.js` generating crisp, intuitive initials from business names.
  - Implemented school-branded blank hero banner (`.card-initials-banner`) in lieu of photographs when a business lacks photos, ensuring no listing is favored or disadvantaged based on photo presence.
  - Applied school-specific banner colors: Alliance High School Blue (`#2E3192`, `.banner-ahs`) for AHS, Alliance Girls High School Green (`#1E5E2A`, `.banner-aghs`) for AGHS, and dual gradient for joint listings (`.banner-both`).
  - Standardized directory layout to a unified `business-grid` where all businesses maintain their natural ranking and search relevance without separating or elevating photo listings over text listings.
  - Enforced full height stretch (`height: 100%`) on `.biz-card` with bottom-pinned action buttons (`margin-top: auto`), ensuring identical card height and placement across all grid columns.
  - Added unit regression test coverage in `tests/test_streamlined_directory_features.php` (Test 16).
  - Bumped PWA cache to `bbiz-pwa-v0.40.3` in `public/sw.js` and updated `VERSION` to `0.40.3`.

## [0.40.2] - 2026-11-22
### Changed
- **Preview Sample Photographs Isolation (Never Push to Production) (#422)**:
  - Guarded sample photo updates in `src/Config/Database.php` with `Database::isPreview()` so sample photo updates never execute against production.
  - Reset seeded business records in `src/Services/CategoryMatcher.php` to clean `'photos_json' => '[]'`.
  - Filtered `public/assets/photos/` from production deployment manifests in `.github/workflows/deploy-ftp.yml` and `.github/scripts/ftps_deploy.py` so test assets never deploy to live production.
- **Removed Visual Open Now/Closed Badges Across Cards & Feeds (#421)**:
  - Removed `.open-status-btn` from directory listing card headers in `public/assets/js/directory.js` and hid the selector in `public/assets/css/app.css` to eliminate visual badge clutter.
  - Removed `.badge-status` from list view items and modal header pills for a clean, dignified presentation.
  - Fully preserved underlying Open Now business logic and filtering (`HoursEngine::evaluateStatus`, `state.openNow`, `#chk-open-now`, `#sheet-open-now`, and `/api/businesses.php?open_now=1`), ensuring users can continue to filter by currently open businesses.
  - Preserved complete weekly operating hours schedules in profile views and detail modals.
  - Added unit regression test coverage in `tests/test_streamlined_directory_features.php` (Test 17).
  - Bumped PWA cache to `bbiz-pwa-v0.40.2` in `public/sw.js` and updated `VERSION` to `0.40.2`.

## [0.40.1] - 2026-11-22
### Fixed
- **Directory Card Alignment by Photo Presence to Eliminate Grid Stretch Blowout (#420)**:
  - Partitioned directory listings into dedicated photo showcase group (`.cards-photo-group`) and compact text group (`.cards-text-group`) in `public/assets/js/directory.js` and `public/assets/css/app.css`.
  - Grouped all listings with photographs into their own `.business-grid` showcase, ensuring 16:9 photo hero cards align next to each other with uniform row heights.
  - Grouped all listings without photographs into their own `.business-grid` section, ensuring compact text cards align next to each other with zero height blowout or empty white space caused by neighboring photo heroes.
  - Extended photo and text group alignment to AI Biashara Advisor concierge mode (`directMatches` and `ecosystemMatches`).
  - Added unit regression test coverage in `tests/test_streamlined_directory_features.php` (Test 16).
  - Bumped PWA cache to `bbiz-pwa-v0.40.1` in `public/sw.js` and updated `VERSION` to `0.40.1`.

## [0.40.0] - 2026-11-21
### Added
- **Directory Listing Card Photographs Showcase & Interactive Carousel (#415, #416)**:
  - Added visual photo hero showcase (`.card-photo-hero`) to directory listing cards in `public/assets/js/directory.js` and `public/assets/css/app.css` for businesses with uploaded photographs.
  - Rendered photo hero in clean 16:9 aspect ratio with 12px rounded framing positioned immediately beneath the card header bar (`.card-header-bar`), keeping school affiliation badges, operating status pills, and share triggers evenly aligned across grid rows.
  - Implemented client-side multi-photo carousel navigation with floating desktop chevron navigation buttons (`‹` and `›`), glassmorphic photo counter badge (`📷 1 / N`), and centered dot pagination indicators for direct photo jumps.
  - Added native mobile touch swipe (`touchstart` / `touchend`) gesture support for fluid mobile carousel navigation without triggering card navigation.
  - Preserved compact, dignified card layout for listings without photographs with zero empty boxes or layout shift.
  - Added unit test coverage in `tests/test_streamlined_directory_features.php` (Test 14).

### Fixed
- **Mobile Viewport Full-Width Layout Alignment (#415, #417)**:
  - Eliminated mobile horizontal body blowout where `.business-grid`'s rigid `minmax(320px, 1fr)` and wide unwrapped header badge rows caused the main content body to exceed screen width, forcing horizontal scrolling to view cards.
  - Overrode `.business-grid` on mobile screens to `grid-template-columns: 100% !important`, setting `.directory-layout-wrapper` to `align-items: stretch !important` so card widths match the header and footer width exactly.
  - Added `flex-wrap: wrap` to `.card-header-bar`, `.header-badges`, and `.card-secondary-row` so school badges, open status pills, appointment badges, and action links gracefully reflow on narrow phone displays.
  - Constrained mobile search capsules and quick category rails to 100% width with boundary isolation.
  - Added unit regression test assertions in `tests/test_streamlined_directory_features.php` (Test 15).
- **Sticky Mobile Header & Relocated Install App to Drawer (#415, #418)**:
  - Replaced `overflow-x: hidden` with modern `overflow-x: clip` across `html`, `body`, and `.directory-layout-wrapper`, ensuring `position: sticky` and `position: -webkit-sticky` on `.site-header` function reliably across mobile browser rendering engines without being trapped by ancestor clipping.
  - Pinned `.site-header` with `top: 0 !important; z-index: 1000 !important;` on mobile, keeping `+ List Your Biashara` (`.btn-list-biashara`) and the `☰` hamburger menu toggle permanently accessible during long directory feeds.
  - Relocated `📲 Install App` from the top header into the hamburger menu drawer (`#btn-install-app-drawer`) on mobile devices, ensuring the header title, CTA, and menu trigger fit on a single line.

## [0.39.1] - 2026-11-21
### Fixed
- **Cache-Busting Query Strings on `form-enhancements.js` & Static Assets (#413)**:
  - Added dynamic `?v=<?= urlencode(bbiz_app_version()) ?>` version query strings to `form-enhancements.js`, `consent-metrics.js`, and `app.css` across all owner, admin, and public submission pages (`owner/edit-business.php`, `admin/edit-business.php`, `admin/add-business.php`, `submit.php`, etc.).
  - Added global `bbiz_app_version()` helper function in `src/bootstrap.php` to centrally resolve application version from `VERSION`.
  - Bumped Service Worker cache identifier to `bbiz-pwa-v0.39.1` in `public/sw.js` and added explicit `public, max-age=3600, must-revalidate` caching directive in `public/.htaccess` so returning clients immediately load updated 10-photo upload logic without browser cache stalls.
  - Added automated unit regression tests in `tests/run_unit_tests.php` (Test 66).

## [0.39.0] - 2026-11-21
### Added
- **Expanded Listing Photos Capacity to 10 Photos (#411)**:
  - Raised maximum photo upload allowance from 5 to 10 photos across `PhotoUploader::MAX_PHOTOS` and `form-enhancements.js`, allowing businesses to showcase more products, storefronts, and work in action.
  - Scaled aggregate upload request ceiling from 20 MB to 40 MB (`PhotoUploader::MAX_TOTAL_UPLOAD_BYTES`) to comfortably accommodate 10 client-side optimized images.
  - Updated photo upload field labels, badges, and founder charter FAQs to reflect the new 10-photo maximum.
  - Enhanced Airbnb showcase CSS (`app.css`) and detail page handler (`directory.js`) to support 5+ photo counts smoothly, preserving the 5-photo mosaic layout with overflow photos accessible in the high-resolution lightbox.
  - Added unit regression tests in `tests/run_unit_tests.php` (Test 18 & Test 65) verifying 10-photo GD processing and upfront validation exception on 11 photos.

## [0.38.1] - 2026-11-20
### Added
- **60-Second Client-Side Countdown Timer on OTP Verification Screen (#410)**:
  - Added a responsive 60-second client-side countdown timer badge (`#otp-timer-badge` / `#claim-otp-timer-badge`) on the verification code input screens in `public/owner/login.php` and `public/owner/claim.php`.
  - Dynamically guides business owners entering their 6-digit email code (`⏱️ Code entry window: 60s remaining`), pacing input and eliminating uncertainty around code validity.
  - Automatically transitions at 0 seconds to helpful next steps (`⏱️ 60s elapsed. Code didn’t arrive or expired? You can request a fresh code below.`) and highlights the resend action (`🔄 Resend 6-digit code (Ready)`), keeping client pacing aligned with the server's 60-second anti-spam resend cooldown.
  - Added unit regression assertions in `tests/run_unit_tests.php` (Test 64) verifying badge elements, 60s interval countdown, and zero `<code>` tags.

## [0.38.0] - 2026-11-20
### Added
- **Airbnb-Style Top Photographs Showcase on Business Detail Page (Parent: #405, Child: #406)**:
  - Repositioned the business photo gallery directly to the top of the detail page (`#view-biz-detail`), removing the buried photo section from Column 1.
  - Implemented an adaptive CSS grid mirroring Airbnb's listing presentation: 5+ photos render as a signature mosaic (1 large dominant hero photo on the left spanning full height + a 2x2 grid on the right); 3-4 photos render as a prominent hero with stacked right tiles; 1-2 photos render as full-width hero banners; and listings with 0 photos display a dignified branded crest banner with alumni motifs and initials (retiring the placeholder text box).
  - Added a floating glassmorphic pill button in the bottom right (`#btn-show-all-photos` displaying `Show all N photos`) that triggers the high-resolution lightbox modal (`openPhotoLightbox`), with smooth micro-zoom hover states on desktop and touch-friendly swipe carousel navigation on mobile (`#mobile-photo-counter` showing `1 / N`).
- **Minimalist Mobile Filter Experience & Native Bottom Sheet (Parent: #405, Child: #407)**:
  - Streamlined the mobile directory view by replacing the cramped 8-section vertical drawer with an uncluttered, modern 3-tier mobile architecture.
  - **Tier 1 (Sticky Search Capsule)**: Added `#mobile-search-capsule` prominently at the top of the mobile feed featuring instant search autocomplete and a dedicated `Filters` button with an active filter count badge (`#mobile-active-filter-badge`).
  - **Tier 2 (Single-Row Horizontal Quick Rail)**: Added a swipeable, single-line horizontal pill strip (`#mobile-quick-rail`) allowing mobile users to toggle `Open Now`, `Perks`, `B2B Ready`, `Bookable`, and School (`AHS` / `AGHS`) filters in 1 tap without opening any menus or wrapping into cluttered lines.
  - **Tier 3 (Native Bottom Sheet Modal)**: Introduced a sleek bottom sheet (`#mobile-filter-sheet`) with a drag handle, clean segmented controls for sorting (`Referenced`, `Trending`, `Newest`, `Nearest`, `A–Z`) and school selection, cascading Country & County/City selects, and a sticky bottom primary action button (`Show N Biasharas`) that updates matching counts dynamically.

## [0.37.0] - 2026-11-20
### Fixed
- **Intelligent Kenya Phone Number Normalization (#404)**: Upgraded `PhoneNormalizer::normalize` to automatically recognize and properly format all common local Kenyan phone input patterns for Kenyan listings:
  - Automatically transforms 10-digit national numbers (`07xxxxxxxx` and `01xxxxxxxx`) into canonical E.164 `+2547xxxxxxxx` / `+2541xxxxxxxx`.
  - Automatically resolves 9-digit numbers missing national/international prefixes (`7xxxxxxxx` and `1xxxxxxxx`) into `+2547xxxxxxxx` / `+2541xxxxxxxx`.
  - Detects and resolves missing country-code accidents where users prefixed their 9-digit local number with `+` (e.g. `+746588589` or `+110123456`), preventing mistaken international collision with Russia/Kazakhstan (+7) or North America (+1) and properly normalizing to `+254746588589` / `+254110123456`.
  - Corrects transposition typos for Kenya's country code (`+245...` -> `+254...`, e.g. `+245722366164` -> `+254722366164`).
  - Strips accidental trunk zero after country code (`+2540...` or `+254 (0) 7...` -> `+2547...`).
  - Preserves legitimate explicit foreign international numbers (e.g. `+44...`, `+1...`, `+41...`) entered for diaspora or international listings without modification.
- **Client-Side Phone Sanitization & Formatting (#404)**: Added `formatKenyaPhone()` in `directory.js` ensuring that directory cards, list view rows, sector view items, booking modals, and the canonical business detail header always display clean `+254...` numbers and build working `tel:` and `https://wa.me/254...` links even if legacy data or cached responses are served.
- **Database Self-Healing Phone Remediation Migration (#404)**: Added `PhoneNormalizer::remediateStoredPhoneNumbers($pdo)` wired into `Database::migrateAndSeed()` (schema version `2026-11-v35-kenya-phone-normalization`), automatically updating legacy stored rows across `businesses`, `business_vetting_queue`, and `business_owners` on production deployment.

## [0.36.0] - 2026-11-20
### Added
- **Sideways Photo Gallery Carousel & Navigation Arrows (#403)**: Replaced rigid 3-column photo grid with a smooth horizontal carousel track (`.photos-carousel-track`) equipped with left and right navigation buttons (`#btn-photos-scroll-left` and `#btn-photos-scroll-right`). Automatically displays all uploaded photos (supporting 4+ photos without truncation), features touch/swipe scrolling with CSS snap alignments, and dynamically hides/fades navigation arrows when photos fit the viewport or reach scroll boundaries.
- **Airbnb-Style Full-Screen Photo Lightbox (#403)**: Clicking any photo thumbnail in the gallery opens an elegant, high-resolution dark-mode lightbox modal (`#bb-photo-lightbox`) mirroring Airbnb's experience. Includes a prominent photo counter (`1 / 4`), listing title, floating previous/next navigation chevrons, interactive bottom thumbnail strip with active border highlighting, touch swipe navigation (left/right to switch, down to close), and full keyboard accessibility (`Escape` to close, `ArrowLeft` / `ArrowRight` to step).

### Removed
- **Uncluttered Photos Header**: Removed the redundant subtext *"Three to five photos of the premises, work, or products."* from the Photos & Premises panel on the business profile page, leaving a clean, distraction-free visual gallery.

## [0.35.0] - 2026-11-20
### Added
- **Verified Alumni Recommendations & Take-Back Toggle (#Mockup)**: Added "Recommended by Busherians" panel on canonical business profiles matching Kanyi's prototype, backed by a persistent `business_recommendations` table and `/api/recommend.php`. Alumni can endorse businesses they have used in one tap with automatic alumni credential capture (Name, School, Departure Year), and can tap the button again at any time to take back / retract their recommendation with real-time count updates and engagement score recomputation.
- **Profile Details Confirmation Footnote Strip (#Mockup)**: Added bottom footnote strip to canonical business profiles stating *"Details confirmed by the owner in [Month Year]"* with direct links to owner claim/login (`/owner/login.php`) and takedown/report (`/takedown.php?biz=[slug]`).
- **Greyed-Out State for Listings Without WhatsApp**: Automatically detects when a business lacks an active WhatsApp line and renders a disabled, greyed-out button (`.btn-action-disabled`) across directory cards, list view rows, sector view items, and canonical detail headers, preventing dead taps while guiding clients to direct phone calls.

### Changed
- **Confine Install App Button Strictly to Mobile**: Updated PWA Install App button (`#btn-install-app`) to `header-mobile-only` and enforced desktop suppression (`@media (min-width: 901px) { .btn-install-pwa { display: none !important; } }`), ensuring the installation prompt appears only on mobile phone viewports and inside the mobile navigation drawer.
- **Clean Business Cards (Omit Visual Popular & New Badges)**: Removed `🔥 Popular` and `✨ New` badge pills from directory cards to keep card headers uncluttered and professional, while leaving all underlying popularity ranking algorithms and 7-day cold-start prominence boosts fully active in the ranking engine.

## [0.34.0] - 2026-11-19
### Fixed
- **Responsive Zero-Scroll List View (#399)**: Replaced rigid 6-column HTML `<table>` in List View with an adaptive, modern responsive list design (`.biz-list-group` / `.biz-list-item`) that completely eliminates horizontal scrolling on both mobile viewports and desktop/laptop screens. Displays all essential business information with clear visual hierarchy: prominent company title, school badge, live open/closed status badge, specialty, location, founder attribution, alumni perk callout, and 1-tap touch-friendly WhatsApp, Call, and Details action buttons.
- **Directory Card Secondary Taxonomy & Route URL Scheme Sanitization (#186, #278, #394)**: Restored secondary field badge (`.card-taxonomy-secondary`) on directory cards, sanitized Google Maps route links in cards and canonical profile headers via `sanitizeHttpUrl` to prevent unsafe scheme injection, and aligned adversarial E2E suite for regulated business owner verification.
- **Strict TypeError in Sidebar Location Filter (#371)**: Fixed fatal PHP 8 TypeError where `array_keys()` on sequential county array passed integer index into `htmlspecialchars()`, halting page output inside `<select id="filter-city">` and preventing the directory cards grid, scripts, and footer from rendering.

### Added
- **Cascading Country -> Subdivisions Location Filter (#401)**: Added visible `#filter-country` dropdown under LOCATION in the sidebar filter dock listing Kenya prioritized at the top followed by all 196 countries alphabetically, dynamically populating `#filter-city` with the selected country's administrative subdivisions (all 47 Kenyan Counties, 51 US States, UK Counties/Councils, Canadian Provinces/Territories, Tanzanian Districts, Ugandan Districts, South African Provinces, etc.), complete with dynamic unit label headers (`📍 All Counties`, `📍 All States`, `📍 All Provinces`, etc.) and a subtle active-state highlight.
- **Collapsible Sector Filter with Animated Chevron & Active Hint (#400)**: Made the sidebar **SECTOR** filter header an interactive, accessible button (`#btn-toggle-sector-collapse`) with a rotating indicator arrow (`▾` / `▸`). Starts collapsed by default to allow all primary filter categories (Sector, Location, School, Show Only) to fit on the screen without excessive vertical scrolling, smoothly revealing all 15 sectors on demand and showing the active sector label in a pill badge beside the title.
- **Streamlined Mobile Experience & Hamburger Slide-Over Drawer (#398)**: Redesigned directory mobile viewport (`<= 900px`) to display only essential elements directly on screen — header with BB crest logo, directory title, tagline, single `+ List Your Biashara` button, and hamburger menu button (`#btn-mobile-menu`); main body showing only the view switcher buttons (`Cards`, `List`, `By Industry`), active business listings, bottom map toggle bar and dock (revealed when checked), and 4-column footer. Shifted all auxiliary controls into a smooth slide-over drawer (`#filters-sidebar.drawer-open` with `#drawer-backdrop`), including keyword search, Advisor shortcut, quick filter pills, sorting options, sector/location/school filters, "Show Only" checkboxes, and secondary header actions (Owner Sign In, B2B Shortlist, Install App, and Privacy Notice).
- **Opt-In Bottom Map Visibility Checkboxes (#397)**: Kept the bottom proximity and pin map dock (`#pane-map`) hidden by default (`display: none`), providing synchronized toggle checkboxes in both the left sidebar under *Show Only* (`#chk-show-map`) and in a dedicated bar above the bottom map dock (`#chk-show-map-bottom`), with automatic reveal when clicking *Route* on any business card.
- **Streamlined Directory Layout & Left Filter Panel (`#370`, `#371`, `#372`, `#373`, `#374`, `#389`, Parent `#370`)**:
  - Implemented responsive left sidebar filter panel (`#filters-sidebar`) with collapsible toggle, real-time sector category vertical list with business counts, location dropdown with "Near me" GPS filter, signature school text buttons (without logos), and "Show Only" checkboxes (`Open now` and `Offers an alumni perk`).
  - Added top view switcher toolbar (`.view-switcher-bar`) with Grid View (`#btn-view-grid`), List View (`#btn-view-list`), and By Sector (`#btn-view-sector`) modes, active count indicator, and directory sorting select.
  - Refined directory cards to strictly display the 12 requested items: Company name, What they do, Location, Brief tagline, Founded by/Co-Founded by attribution, Alumni perks, direct 1-tap WhatsApp and Call action buttons, Details link, Route directions, School badge, Share button, and live Open/Closed status button.
  - Refined theme palette: main body canvas in Heritage Ivory (`#FBF8F3`), with Site Header and 4-Column Directory Footer framed in the signature Jungle Green and Bush Crimson/Maroon heritage gradient blend (`#0B332A` / `#114B3E` to `#7B1113` / `#560B0D`) accented with Heritage Gold (`#D4AF37`).
  - Updated default listings per page to 25 with options for 50 and 100 (`#389`), retiring the previous 10-item default across server rendering, client state, and API.
- **Canonical Business Profile Page (`#375`, `#376`, `#377`, `#378`, Parent `#375`)**:
  - Implemented clean `/biz/{slug}` URL routing in `public/.htaccess` with server-side OpenGraph and Twitter/WhatsApp rich social preview metadata emitted in `public/index.php`.
  - Built dedicated canonical profile view (`#view-biz-detail`) matching Kanyi's mockup: hero banner with cover/monogram initials, 3fr/2fr 2-column layout (Services & Capabilities tag list, Photos & Premises showcase, Recommended by Busherians, Contact & Landmark info, full weekly opening hours table with today highlighted, WhatsApp share, and "More in Sector" mini-cards).
  - Added browser history management (`history.pushState`) for seamless back-and-forth navigation between directory listings and canonical business profiles.
- **4-Column Heritage Directory Footer (`#379`, `#380`, `#381`, Parent `#379`)**:
  - Structured footer into 4 clean navigation columns: THE DIRECTORY, FOR BUSINESS OWNERS, HELP AND TRUST, and COMMUNITY.
  - Added direct official school links for Alliance High School (`https://www.alliancehighschool.ac.ke/`) and Alliance Girls High School (`https://aghs.sc.ke/`).
  - Added footer base strip with school motto *"Strong to Serve. Walk in the Light."*, directory relationship disclaimer, and zero-logo text badges in signature AHS Navy (`#2E3192`) and AGHS Green (`#1E5E2A`).
- **Founder & Co-Founder Voluntary Attribution (`#382`, `#383`, `#384`, `#392`, Parent `#382`)**:
  - Added `founder_role`, `founder_name`, `founder_school`, and `founder_year` columns to `businesses` schema and SQLite/MySQL database migrations (`Database::ensureFounderAttributionColumns`).
  - Added optional Founder vs. Co-Founder attribution radio toggle to registration form (`public/submit.php`), capturing role, founder full name, school, and completion year.
  - Added Founder (Solo) vs. Co-Founder (Joint with others) choice on the simplified form (`#392`), clarifying that non-alumni co-founders or partners do not need to be listed &mdash; only the submitting alumnus is credited until fellow alumni co-founders sign up to claim and link themselves to the business.
  - Displayed verified attribution badge on directory cards and profile hero (`"👤 Founded by [Name] (AHS '98)"` / `"👤 Co-Founded by [Name] (AGHS '12)"`).
- **Progressive Onboarding & Registration Form Redesign (`#385`, `#386`, `#387`, `#388`, `#390`, `#391`, `#393`, `#395`, `#396`, Parent `#385`)**:
  - Restructured `public/submit.php` into an ultra-simple 8-field registration form matching Kanyi's mockup (`#386`): Business name, What you do in one line, Sector, Area or town, WhatsApp number, Your name, School (AHS/AGHS), Year left, optional School number, and one-tap founder attribution and listing completion checkboxes.
  - Retained the single URL link window (`WebsiteEnricher` / `autofill-box`, `#387`) at the top of the form for 1-click automatic extraction of business details from websites with SSRF protection.
  - Collapsed all comprehensive secondary questions (House, Level 2 Specialty & regulator, full description, service mode, address/pin/hours, perks, B2B checkboxes, direct phone, public email, website, booking, socials, photos) under a `Show Advanced / Additional Details` (`#toggle-advanced-details`, `#390`) checkbox.
  - Updated terms and privacy notices across `public/submit.php`, `public/privacy.php`, and `src/bootstrap.php` (`#391`) to explicitly state that the directory retains and publicly displays the alum's Name and Year of Departure to show the world if they choose founder attribution, while School Number is strictly deleted immediately upon review as it is not pertinent beyond initial vetting.
  - Retired obsolete "Same as Direct Phone above" checkboxes (`#393`) from `public/submit.php`, streamlining the form so WhatsApp is entered directly under business details and primary phone defaults cleanly.
  - Added collapsible toggle checkbox `[ ] Operates in an additional industry / sector (spans two fields)` (`#toggle-secondary-sector`, `#395`) directly below the primary Sector selection, smoothly revealing secondary sector/specialty pickers on demand while keeping the default registration form completely clutter-free.
  - Realigned Sector and Contact rows on registration form (`#396`): placed optional `Specialty in sector` to the right of `Sector *` in a 2-column grid (with `None — Select Sector first...` placeholder), and paired `Area or town *` to the right of `WhatsApp number *` in a balanced 2-column grid.
  - Resolved Adversarial Audit finding H-01 in `public/submit.php` and `src/Services/VettingPurger.php`: enabled graceful fallbacks when advanced fields are collapsed, automatically defaulting business description to tagline, direct phone and submitter phone to WhatsApp number, house name to `'Unspecified'` without failing vetting validation, and area_neighborhood to area_town.

## [0.33.0] - 2026-11-18
### Added
- **Standalone Preview Subdomain Environment (`#359`, `#361`, Parent `#359`)**:
  - Configured first-class standalone preview environment on `preview.busherianbiasharas.com` backed by isolated SQLite database storage (`storage/busherian_biasharas.sqlite`), ensuring zero mutation risk to production MySQL (`egimba_bbiz`).
  - Added `Database::isPreview()` and `Database::isProduction()` helpers in `src/Config/Database.php` to automatically detect the preview subdomain and default driver to SQLite without manual configuration.
  - Implemented 1-click signed administrator handoff (`PreprodSandbox::generateProdToPreprodHandoffUrl('/admin/')`) linking production admin console directly to `https://preview.busherianbiasharas.com/admin/` with automatic session establishment.
  - Added persistent visual environment indicator banners in both public directory (`public/index.php`) and admin console (`public/admin/index.php`) notifying visitors of the simulated SQLite preview environment with a direct link back to live production.
  - Enforced staging SMTP isolation (`SIMULATE_EMAILS=yes`) and local sandbox test data reset/seeding in `public/admin/sandbox.php` on preview while guarding live production against accidental test resets.
- **Dedicated FTPS Deployment Pipeline for Preview (`#362`, Parent `#359`)**:
  - Added `.github/workflows/deploy-preview.yml` deploying directly to `/preview.busherianbiasharas.com/www` upon push to `preview` branch (or via workflow dispatch).
  - Updated `.github/scripts/ftps_deploy.py` with explicit `--mode preview` mapping (`clean_root = (app_root or "/preview.busherianbiasharas.com/www")`, mapping `public/*` to `{clean_root}/html/*` and application files to `{clean_root}/*`).
  - Added preview HTTP & schema readiness verification probe targeting `https://preview.busherianbiasharas.com/api/health.php?verify_schema=1`.

### Removed
- **Legacy `/admin/preprod` Subpath Architecture (`#360`, Parent `#359`)**:
  - Removed all legacy `/admin/preprod` subpath calculations, base path rewrites, output buffer manipulations, and custom cookie prefix hacks (`BBIZ_PREPROD_SESS`, `bbiz_preprod_owner_session`) from `src/Services/AdminAuth.php`, `src/Services/OwnerAuth.php`, `src/Services/EngagementRanker.php`, and `src/Services/PreprodSandbox.php`.
  - Removed legacy `/admin/preprod/` path stripping in `public/api/track-metric.php`.
  - Retired `.github/workflows/deploy-preprod.yml` and obsolete code-injection staging routines.

## [0.32.8] - 2026-11-18
### Fixed
- **Authentication OTP Mail Transport Fallback & Diagnostics (`#365`)**: 
  - Updated `Mailer::dispatchOutboxRow` to automatically fall back to PHP's local `mail()` transport for critical owner authentication verification codes (`Verification Code` / `6-Digit`) whenever remote authenticated SMTP connection or TLS handshake encounters shared-hosting socket latency or hostname mismatch errors.
  - Added explicit socket error code and string logging (`$errno`, `$errstr`) to `Mailer::sendViaAuthenticatedSmtp` when `stream_socket_client` fails to connect.

## [0.32.7] - 2026-11-18
### Fixed
- **Anti-Spam OTP Silent-Drop Elimination & Differentiated Rate Limits (`#363`)**: 
  - Adjusted `AntiSpamGate::verifySubmission` to distinguish single-field OTP actions (`owner_otp_send`, `claim_otp_send`, `submit_owner_otp`) from full business submissions, reducing the time-trap from 4 seconds to 1 second so legitimate users requesting or resending a code are not falsely flagged as bots.
  - Raised connection rate limits for OTP actions to 6 attempts per 15 minutes and 20 per 24 hours (up from 2 per 15m), and eliminated deceptive `silent_drop=true` masking on rate-limit violations so owners receive transparent, actionable feedback instead of faux success banners with suppressed delivery.
  - Added explicit user-facing error mappings in `public/owner/login.php` and `public/owner/claim.php` for `layer5_ip_rate_limit_15m`, `layer5_ip_rate_limit_24h`, and `layer5_global_rate_limit_1h`.
- **PHP Mail Fallback Override Resilience (`#363`)**: Updated `Mailer::isPhpMailFallbackEnabled()` so `ALLOW_PHP_MAIL_FALLBACK=yes` functions as an authoritative fallback override when remote SMTP transport connectivity times out on shared hosting.

## [0.32.6] - 2026-11-18
### Fixed
- **Admin Category Left-Join & Unmapped Category Visibility (`#356`)**: Updated `public/admin/index.php` to query sectors and specialties via `LEFT JOIN` instead of `INNER JOIN` across both pending vetting queue and existing business management lists. Added `unmapped_category` status filter option, top-bar diagnostic banner with direct filter link, and highlighted `⚠️ Unmapped Category` warning badge for listings with missing or orphaned taxonomy references.
- **Production Mail Transport Incident Documentation (`#357`)**: Documented the unconfigured production SMTP transport incident preventing transactional OTP verification email dispatch on `/owner/claim.php`, mitigation via production `.env` `SMTP_HOST` configuration on Angryhosting, and planned post-verification automated health & pre-flight transport failsafes.

## [0.32.5] - 2026-11-16
### Changed
- **Biashara Advisor Isolation to Pre-Prod Sandbox (`#352`, Parent `#351`)**: Gated the Ask Biashara Advisor pill toggle, natural-language query panel, and consent modal in `public/index.php` behind `PreprodSandbox::isPreprod()`, automated test suites (`APP_ENV=testing`), explicit environment flags (`BBIZ_ENABLE_ADVISOR`), and on-demand query preview (`?advisor=1`). Restored standard clean directory navigation for public production visitors while retaining full conversational testing in preprod environments.
- **Newsletter Alignment for Live Release (`#353`, Parent `#351`)**: Updated the `Habari za Biasharas` newsletter in `extras/projects/busherian-biasharas/habari-za-biasharas.md` to exclude the Advisor from live client-facing feature sections, positioning it under "Coming Next" as an upcoming preview, and recompiled `habari-za-biasharas.pdf`.
- **Service Worker Cache Identifier (`#354`, Parent `#351`)**: Bumped client cache version to `bbiz-pwa-v0.32.5` in `public/sw.js` and refreshed asset versioning.

## [0.32.4] - 2026-11-16
### Fixed
- **Compound Phrase Semantic Normalization & Token Isolation (`#371`, Parent `#370`)**: Introduced `COMPOUND_PHRASE_NORMALIZATIONS` in `CategoryMatcher` to normalize idiomatic compound phrases (`team building` -> `teambuilding`, `corporate retreat` -> `corporate_retreat`, `building materials` -> `building_materials`, `real estate` -> `real_estate`, `interior design` -> `interior_design`, etc.) prior to unigram token splitting. Prevents erroneous token fragmentation where multi-word concepts were split into ambiguous unigrams like "building", which previously caused queries for team building to match construction, hardware, and contractor categories.
- **Cross-Sector Disambiguation Guard & Hospitality Concept Expansion (`#372`, Parent `#370`)**: Added an explicit disambiguation guard in `CategoryMatcher::searchSpecialties` that suppresses construction and industrial specialties from matching when a query carries team building or corporate retreat intent. Enriched concept keywords and synonym lexicons for `Hotel, Boutique Resort & Airbnb`, `Event Planning, Tent/Sound & Decor`, `Conference & MICE Tourism`, and `Team Building & Bush Expeditions` to recognize retreat venues, country lodges, workcation getaways, and event grounds.
- **Bidirectional Team Building & Retreat Adjacency in NeedGraph (`#373`, Parent `#370`)**: Registered `Team Building & Bush Expeditions` as an authoritative source in `NeedGraph::ADJACENCY_MAP`, establishing bidirectional companion linkages with `Hotel, Boutique Resort & Airbnb` (Retreat Lodge & Venue Grounds), `Event Planning, Tent/Sound & Decor` (Outdoor Tents & PA Systems), `Car Hire, Chauffeur & Executive Transfer` (Staff Shuttles & Safari Vans), `Event & Corporate Catering` (Group Catering & Barbecue), and `Commercial Photography & Videography` (Offsite Media Coverage). Enhanced `NeedGraph::getClusterForSpecialties` with collapsed string similarity to cleanly resolve compound entities into the `events_retreats` journey cluster.
- **Direct Capability Matching & Semantic Pollution Shield (`#374`, Parent `#370`)**: Enhanced `ConciergeRetriever` with `matchesDirectCapability()` and upgraded `matchesDirectKeywordThreshold()` to inspect multi-word phrases and collapsed token stems across listing capability digests and normalized keywords. Ensures businesses with verified capabilities (such as The Last Village Lodge offering corporate team-building, conference facilities, and country lodge grounds) are accurately retrieved as #1 Direct Matches while enforcing cluster boundaries to prevent unrelated categories from leaking into search results.
- **Service Worker Cache Identifier (`#370`)**: Bumped client cache version to `bbiz-pwa-v0.32.4` in `public/sw.js` and updated asset query strings in `public/index.php`.

## [0.32.3] - 2026-11-16
### Fixed
- **Advisor Keystroke Auto-Firing Elimination (`#365`, Parent `#364`)**: Removed the 400ms debounce auto-search trigger on keystrokes in `public/assets/js/directory.js` while in Biashara Advisor mode. Queries are now submitted exclusively when the visitor presses Enter, clicks "★ Ask Advisor", or selects a preset starter chip, preventing rapid multi-request bursts while typing.
- **Cache-First Rate Limit Bypassing (`#365`, Parent `#364`)**: Reordered request processing in `public/api/concierge.php` so the 15-minute Intent Cache check executes *prior* to recording rate-limit consumption. Identical questions and repeated clicks on preset starter prompt chips are served directly from the decoupled cache without deducting from visitor rate-limit quotas.
- **Right-Sized Tiered Rate Limits (`#366`, Parent `#364`)**: Elevated the standard anonymous visitor rate limit from 10 to 30 queries per 15 minutes, from 50 to 150 queries per 24 hours, and global hourly circuit breakers from 100 to 300 queries. Introduced privileged rate limits for authenticated listing owners (60/15m, 300/24h, 500/1h) and system administrators (120/15m, 600/24h, 1000/1h).
- **Dynamic Cooldown & Retry-After Header Emission (`#367`, Parent `#364`)**: Enhanced `AntiSpamGate::checkAndRecordRateLimit()` to dynamically calculate the exact remaining seconds until the oldest query in the active rate-limit window rolls off. Emitted standard HTTP `Retry-After: <seconds>` headers and JSON `retry_after: <seconds>` on all 429 Too Many Requests responses.
- **Adaptive Client-Side Cooldown Countdown (`#368`, Parent `#364`)**: Enhanced `#btn-retry-concierge` in `public/assets/js/directory.js` to parse `Retry-After` headers and JSON payloads, dynamically disabling the retry button and displaying a live countdown (`Wait Xs…`). Automatically re-enables the button with `🔄 Try Again` as soon as the cooldown window expires.
- **Service Worker Cache Identifier (`#364`)**: Bumped client cache version to `bbiz-pwa-v0.32.3` in `public/sw.js`.

## [0.32.2] - 2026-11-16
### Fixed
- **Leaflet Invalid LatLng Exception Defense (`#361`, Parent `#359`)**: Introduced `isValidLatLng()` in `public/assets/js/directory.js` ensuring coordinates passed to Leaflet (`L.marker`, `L.circle`, `L.circleMarker`, `L.latLngBounds`, and `map.setView`) are validated finite numbers between [-90, 90] and [-180, 180]. Fixed a bug where businesses without physical coordinates triggered `Error: Invalid LatLng object: (undefined, undefined)` when attempting to render markers in `renderInteractiveMap()`. Wrapped map updates in `renderConciergeResults()` in a resilient error boundary so map pin glitches never abort card rendering.
- **Global Business Journey Terminology & Parochial Copy Cleanup (`#362`, Parent `#359`)**: Replaced internal design strings referencing "Kenyan need graph" across `public/assets/js/directory.js` with global business ecosystem phrasing (*"The Advisor maps verified alumni practitioners and companion partners across complete business lifecycles"* and *"Complementary partners across your business journey"*). Updated the Advisor typing indicator to *"Consulting verified alumni network & business journey graph…"*.

### Added
- **Dedicated Biashara Advisor Query Input Bar (`#360`, Parent `#359`)**: Added a dedicated, prominent text input bar (`#concierge-panel-input`) and submit button (`#btn-concierge-panel-submit`) inside `#concierge-panel` in `public/index.php`, as well as inside the welcome card (`#concierge-welcome-input`). Kept all three search inputs (`#search-q`, `#concierge-panel-input`, `#concierge-welcome-input`) in two-way synchronization, allowing visitors to type their business challenges directly without searching for an input field.
- **Globally Diverse Starter Prompt Chips (`#362`, Parent `#359`)**: Replaced location-restricted prompt chips with globally relevant business journey chips covering enterprise launches, commercial architecture, corporate retreats, agricultural export logistics, and book publishing across both `public/index.php` and `public/assets/js/directory.js`.
- **Service Worker Cache Identifier (`#359`)**: Bumped client cache version to `bbiz-pwa-v0.32.2` in `public/sw.js`.

## [0.32.1] - 2026-11-16
### Fixed
- **Decoupled Concierge Cache & Cache Poisoning Defense (`#349`, Parent `#348`)**: Refactored `ConciergeService` and `public/api/concierge.php` to decouple response caching from entity presentation. The 15-minute response cache now stores lightweight business IDs and contextual match metadata rather than full entity cards, dynamically re-hydrating listings strictly from live approved database records via `hydrateApprovedBusinesses()`. Quarantined or deleted businesses are evicted immediately from cached responses without waiting for TTL expiry (remediates Pass-5 Adversarial HIGH-01).
- **Sensitive Narrative PII Protection & Local Smart Mode Forcing (`#349`, Parent `#348`)**: Expanded `QueryScrubber` with comprehensive regex heuristics (`SENSITIVE_HEALTH_LEGAL_PATTERN`, `ADDRESS_PII_PATTERN`, `NAME_INTRO_PATTERN`) detecting medical symptoms, reproductive health, chronic conditions, family legal disputes, personal street addresses, and individual name disclosures. Queries triggering sensitive heuristics are strictly restricted to deterministic `local_smart` mode, preventing transmission of sensitive personal narratives to external generative APIs (remediates Pass-5 Adversarial HIGH-02).
- **Interactive Adult (18+) Attestation & Consent Gate Hardening (`#350`, Parent `#348`)**: Enhanced `public/index.php` and `public/assets/js/directory.js` with an explicit adult (18+) statutory attestation checkbox (`#concierge-adult-attestation`), enforcing Kenya DPA 2019 ss. 32–33 child data protections. Hardened `public/api/concierge.php` to validate adult attestation, prevented starter chips and deep links from manufacturing unprompted consent in `localStorage`, and added an inline reset button (`#btn-concierge-reset-consent`) to allow visitors to withdraw consent at any time (remediates Pass-5 Adversarial HIGH-03, MED-11, LOW-02).
- **Concurrent Media Quarantine Re-Verification Lock (`#350`, Parent `#348`)**: Hardened `VettingPurger::updateBusiness()` and `updateBusinessByOwner()` to re-query the live database status before executing filesystem photo promotion or restoration, eliminating race conditions where concurrent DPA quarantine requests could be undone by simultaneous updates (remediates Pass-5 Adversarial HIGH-04).
- **Prompt Injection Defense & Structural LLM Formatting (`#350`, Parent `#348`)**: Updated `ConciergeService::buildCandidatesPayload()` to strip newlines, delimiters, system tags, and instruction overrides from untrusted directory listing fields before injection into generative prompts. Added `sanitizeGroundedProse()` to strip URLs, emails, phone numbers, and payment handles from generated prose (remediates Pass-5 Adversarial HIGH-05).
- **Stale Concierge Cache Invalidation (`#351`, Parent `#348`)**: Added `VettingPurger::invalidateConciergeCache()` to immediately clear all concierge caches in `app_settings` upon business approval, admin update, owner update, DPA quarantine, restoration, and deletion. Added 15-minute cache purge to `VettingPurger::enforceRetentionCeilings()` (remediates Pass-5 Adversarial MED-01).
- **Concierge Card Contract Normalization (`#351`, Parent `#348`)**: Unified card payload across standard directory search and concierge recommendations (`phone_primary`, `phone_display`, `whatsapp_number`, `website_url`, `service_mode`, `b2b_ready`, `accepts_lpo`, `hours_status`, `distance_km`, `school_badge`, `momentum_badge`), eliminating UI rendering disparities between search modes (remediates Pass-5 Adversarial MED-02).
- **Candidate Pre-Ranking & Intent Filter Alignment (`#351`, Parent `#348`)**: Ordered candidate pre-ranking in `ConciergeRetriever` deterministically by `engagement_score DESC, b.id ASC LIMIT 250` prior to application-level scoring. Filtered candidates by natural-language operational constraints (`open_now`, `b2b_only`) detected from visitor queries (remediates Pass-5 Adversarial MED-03, MED-04).
- **DPA Cross-Border Transfer Register Gemini Addition (`#352`, Parent `#348`)**: Updated `docs/DPA_TRANSFER_REGISTER.md` to `2026-11-v5`, adding Google Gemini Generative AI API (Item 8) with Kenya DPA 2019 legal grounds (ss. 48–49), transfer mechanisms, and pre-processing redaction safeguards (remediates Pass-5 Adversarial MED-05).
- **SMTP Credential Aliasing & Strict Production TLS Verification (`#352`, Parent `#348`)**: Updated `Mailer` and `.env.example` to support both `SMTP_USER`/`SMTP_USERNAME` and `SMTP_PASS`/`SMTP_PASSWORD` environment variables with clear validation on partial configurations. Enforced strict TLS certificate and peer name verification (`verify_peer => true`, `verify_peer_name => true`) when running in production (remediates Pass-5 Adversarial MED-06, MED-07).
- **Atomic Application Settings & Race Elimination (`#353`, Parent `#348`)**: Refactored `Database::setSetting()` to execute atomic upserts (`INSERT ... ON DUPLICATE KEY UPDATE` for MySQL, `INSERT OR REPLACE INTO` for SQLite), eliminating multi-step race conditions in concierge caching and dynamic settings (remediates Pass-5 Adversarial MED-08).
- **Early Request Payload Size Cap (`#353`, Parent `#348`)**: Bound `public/api/concierge.php` incoming request size to 4KB via `CONTENT_LENGTH` and `php://input` inspections, rejecting oversized inputs upfront before parsing (remediates Pass-5 Adversarial MED-09).
- **Web Migration Probe CSRF & Token Hardening (`#353`, Parent `#348`)**: Hardened migration endpoint probe in `Database::checkMigrationReadiness()` to require authenticated admin token validation, preventing unauthenticated enumeration of database schema state (remediates Pass-5 Adversarial MED-10).
- **Engagement Score Public Redaction & Appointment Delivery State (`#354`, Parent `#348`)**: Redacted raw floating-point `engagement_score` and `reference_count_90d` from `public/api/record-checkout.php` responses. Updated `public/api/book-appointment.php` to accurately report email delivery state (`sent` vs `quota_delayed` vs `delivery_pending`) (remediates Pass-5 Adversarial MED-12, MED-13).
- **Gemini Model Alignment (`#355`, Parent `#348`)**: Updated model identifier to 'gemini' across `ConciergeService` and documentation (remediates Pass-5 Adversarial LOW-01).

### Added
- **1-Week "New" Momentum Badge Window (`#356`, Parent `#348`)**: Refactored `EngagementRanker` momentum cues so that all newly approved listings unconditionally receive the dignified "New" badge for their first 7 days (`NEW_BADGE_WINDOW_DAYS = 7`), replacing the previous 14-day window and 30% newcomer share cap. Updated `public/assets/js/directory.js` tooltip to accurately inform visitors: *"Listed within the past week"*.
- **Unit Test Suite Isolation Hardening (`#357`, Parent `#348`)**: Hardened `tests/run_unit_tests.php` to automatically unlink stale temp SQLite files before running assertions, preventing PID reuse collisions across test executions.

## [0.32.0] - 2026-11-14
### Added
- **Conversational "Ask Biashara Advisor" Search Toggle (`#344`, Parent `#343`)**: Introduced dual-mode search switch in `public/index.php` and `public/assets/css/app.css` allowing visitors to toggle between standard keyword/sector search and natural-language Biashara Advisor mode (`#btn-concierge-toggle`).
- **Interactive Kenya DPA 2019 Transparency Modal (`#344`, Parent `#343`)**: Gated Biashara Advisor on explicit statutory opt-in consent (`#concierge-consent-modal`) detailing ss. 25, 32, 48–49 data protection safeguards (automatic scrubbing of phones, emails, national IDs, and URLs before processing, zero visitor tracking profiles, and strict hydration from verified directory records), persisted locally in `localStorage.bbiz_concierge_consent`.
- **Kenyan Biashara Starter Prompt Chips & Panel (`#344`, Parent `#343`)**: Added curated starter chips covering corporate formation, commercial construction, corporate retreats in Naivasha, agricultural export logistics, and publishing / book launches to guide users into high-context conversational queries.
- **Two-Tier Card Partitioning & Underlying Need Badges (`#345`, Parent `#343`)**: Updated `public/assets/js/directory.js` to render conversational advisor guidance with animated typing states, partitioning results into Direct Matches and Smart Ecosystem Suggestions (`.biz-card-ecosystem`). Ecosystem cards showcase `★ Underlying Need: [label]` badges and plain-English adjacency rationales (`card-adjacency-reason`).
- **Ecosystem Growth Callout & WhatsApp Invitation Shortcut (`#346`, Parent `#343`)**: When unmet adjacent specialties are returned with zero directory listings (`unmet_ecosystem_specialties`), renders a community growth card (`concierge-unmet-card`) with a 1-click WhatsApp share invitation linking directly to `/submit.php`.
- **Interactive Journey Follow-Up Chips (`#345`, Parent `#343`)**: Rendered context-aware follow-up chips (`[data-followup]`) for explored business journeys, allowing visitors to drill deeper into companion requirements with a single click.
- **Service Worker Cache Identifier (`#343`)**: Bumped client cache version to `bbiz-pwa-v0.32.0` in `public/sw.js`.

## [0.31.0] - 2026-11-14
### Added
- **Visitor PII Redaction & Prompt Hygiene (`QueryScrubber`) (`#339`, Parent `#338`)**: Created `src/Services/QueryScrubber.php` to redact telephone numbers (+254/07/01 and international 7+ digits), email addresses, national ID / passport numbers, and URLs from visitor questions before external inference, enforcing Kenya DPA 2019 ss. 25, 32, 48–49. Updated `public/privacy.php` with transparent disclosures on AI Concierge processing.
- **Grounded LLM Advisor & Strict Grounding Verifier (`ConciergeService`) (`#340`, Parent `#338`)**: Built `src/Services/ConciergeService.php` supporting Google Gemini REST inference with strict anti-hallucination post-verification (`verifyAndHydrateGrounding()`). Automatically drops any unapproved or unknown business ID, hydrating authoritative contact, location, and operational data directly from MySQL records.
- **Deterministic Local Smart Mode Fallback (`#340`, Parent `#338`)**: Implemented local rule-based synthesis (`synthesizeLocalSmartMode()`) providing dignified, structured recommendations and unmet-need commentary whenever the external inference API is unconfigured, timed out, or quota-limited, ensuring the advisor never fails with a broken screen.
- **Public Concierge API Endpoint & AntiSpam Circuit-Breakers (`#341`, Parent `#338`)**: Created `public/api/concierge.php` with explicit Kenya DPA consent verification (`consent_required`), per-IP rate limiting (<=10/15m) and global hourly budget (<=100/1h) via `AntiSpamGate`, and a 15-minute response cache for normalized queries to avoid redundant API costs.
- **Service Worker Cache Version (`#338`)**: Bumped client cache version to `bbiz-pwa-v0.31.0` in `public/sw.js`.

## [0.30.0] - 2026-11-14
### Added
- **Kenyan Business Need-Adjacency Graph (`NeedGraph`) (`#335`, Parent `#334`)**: Implemented `src/Services/NeedGraph.php` defining real-world business lifecycles and companion synergies across the 15 Level 1 Sectors and 116+ Level 2 Specialties. Mapped upstream prerequisites, downstream operational steps, and companion services with human-facing badge labels (`underlying_need_label`) and plain-English rationale templates (`explainAdjacency()`). Registered 8 named Kenyan biashara journey clusters (`company_launch`, `property_construction`, `events_retreats`, `health_wellness`, `agribusiness_value_chain`, `tech_digital_scaling`, `creative_publishing`, `relocation_facility`) with automatic cluster resolution (`getClusterForSpecialties()`).
- **Hybrid Candidate Retriever & Multi-Factor Scoring (`ConciergeRetriever`) (`#336`, Parent `#334`)**: Built `src/Services/ConciergeRetriever.php` implementing Layer 2 candidate retrieval for the Intelligent Biashara Concierge. Queries strictly approved listings with active `business_ai_index` rows, parses raw search queries into intent tokens and categories, and partitions output into two tiers: `direct_matches` (direct solutions to requested problem) and `ecosystem_matches` (adjacent companion businesses discovered via `NeedGraph`).
- **Live Context Scoring & Seeded Peer Fairness Rotation (`#336`, Parent `#334`)**: Multi-factor scoring incorporating category match, keyword overlap, B2B/LPO acceptance boost (`+3.0`), `LocationEngine` Haversine distance proximity boost (`+3.0` for <=5km, `+1.5` for <=15km), `HoursEngine` local operating hours boost (`+2.0` for `OPEN_NOW`, `+1.0` for `CLOSING_SOON`), and `EngagementRanker` momentum boost (`+min(2.5, 0.4*ln(1+score))`). Implemented 2-hour slotted deterministic peer rotation (`applyPeerFairnessRotation()`) within near-tie score bands to ensure equal alumni peers rotate presentation order fairly across visits without early ID monopolies.
- **Unmet Ecosystem Demand Detection (`#335`, `#336`, Parent `#334`)**: Added automated detection for inferred adjacent specialties with zero approved directory listings (`unmet_ecosystem_specialties`), generating contextual community growth prompts (*"Know a fellow Busherian in [Specialty]? Invite them to list their biashara!"*) to transform latent community demand into directory expansion.
- **Service Worker Version Bump (`#334`)**: Bumped client cache identifier to `bbiz-pwa-v0.30.0` in `public/sw.js`.

## [0.29.0] - 2026-11-14
### Added
- **Sanitized AI Capability Index (`business_ai_index`) (`#331`, Parent `#330`)**: Added `business_ai_index` table (`business_id`, `capability_digest`, `keywords_norm`, `embedding_json`, `content_hash`, `indexed_at`, `updated_at`) with foreign key cascading to `businesses(id)`, and bumped `Database::SCHEMA_INIT_VERSION` to `'2026-11-v29-ai-index'` with idempotent schema migrations across SQLite and MySQL 8.0 (`src/schema.sql`, `src/Config/Database.php`).
- **Prompt-Injection Defense & Capability Digest Builder (`#332`, Parent `#330`)**: Created `src/Services/AiListingIndexer.php` to distill verified public fields into prompt-injection-safe capability summaries. Implemented `AiListingIndexer::sanitizePromptInjection()` to neutralize instruction override phrases, role delimiters (ChatML tokens, `system:`, `[INST]`), and markdown/HTML data exfiltration cues. Added `buildNormalizedKeywords()` producing deduplicated, stop-word-filtered keyword tokens, and `computeContentHash()` for deterministic dirty-checking.
- **Transactional Moderation Lifecycle Sync (`#332`, Parent `#330`)**: Wired `AiListingIndexer::indexBusiness()` into `VettingPurger::approveBusiness()`, `updateBusiness()`, `updateBusinessByOwner()`, `createBusinessDirectly()`, and `restoreQuarantinedBusiness()`, and wired `AiListingIndexer::purgeBusiness()` into `rejectBusiness()`, `quarantineBusinessDpa()`, and `deleteBusiness()`, ensuring unapproved, quarantined, or deleted listings are purged from the AI index immediately.
- **CLI Index Rebuild & Audit Utility (`#331`, Parent `#330`)**: Added `bin/rebuild-ai-index.php` (`--audit-only` or full rebuild) to audit index coverage, rebuild stale or missing records, and prune orphan entries.

## [0.28.0] - 2026-11-14
### Added & Changed
- **Phone Extension Parsing, Dialable E.164 Cleaning & Length Validation (`#327`, Parent `#326`)**: Added `PhoneNormalizer::splitExtension()` to reliably separate extensions (`ext 104`, `x205`, `#12`, `,104`) from base phone digits, returning dialable numbers for `tel:` and `wa.me` links without trailing extension digits corrupting calls. Added `PhoneNormalizer::normalizeWithExtension()` to preserve cleanly formatted display strings (`+14155550132 ext. 104`), and enforced ITU-T E.164 bounds (7 to 15 digits) via `PhoneNormalizer::isValidE164Length()`.
- **Country-Aware IANA Timezones & Local Operating Hours (`#328`, Parent `#326`)**: Added `LocationEngine::COUNTRY_TIMEZONES` mapping all 197 supported countries to primary canonical IANA timezones with sub-national US/Canada/Australia state and major city overrides (`LocationEngine::getTimezoneForCountry()`). Updated `HoursEngine::evaluateStatus()` to accept an optional target timezone, returning `'timezone'` and `'timezone_abbr'` and evaluating `🟢 Open Now` and closing countdowns against the business's local clock rather than server EAT.
- **Timezone-Aware Directory Feeds & Appointment Booking (`#328`, Parent `#326`)**: Wired local business timezones into `public/api/businesses.php` (both SQL pagination and `open_now` candidate chunk scanning) and `public/index.php`. Updated `public/api/book-appointment.php` to evaluate past-date checks against the listing's local date, validate calendar dates with `checkdate()`, and pass the business's timezone abbreviation to `Mailer::sendBookingInquiry()`, formatting booking notifications with dynamic local timezones (`({$tzAbbr})`) instead of hardcoded `(EAT)`.

## [0.27.2] - 2026-11-14
### Security & Data Integrity
- **Redact Moderation Capabilities from Owner Personal Data Export (`#321`, Parent `#320`)**: Excluded internal administrator review correspondence (`[Review Required]`, moderation emails containing `/admin/review-action.php`) from `OwnerAuth::exportOwnerPersonalData()`, fixed regex to redact HTML-encoded `&amp;sig=` capability tokens, and enforced an active administrator session via `AdminAuth::requireAdminSession()` on `/admin/review-action.php` as defense in depth against self-approval (remediating Adversarial CRITICAL C-01).
- **Unify Legacy Click Ingestion into EngagementRanker (`#322`, Parent `#320`)**: Updated `public/api/track-click.php` to require an approved listing status, route all weighted signals (`call`, `whatsapp`, `directions`, `website`, `booking`) through `EngagementRanker::recordCheckout()` with 24h HMAC deduplication, human interaction proof, bot filtering, and owner/admin exclusion, and record non-weighted UI metrics (`shortlist`) with zero ranking weight (remediating Adversarial HIGH H-01).
- **Fail-Closed Deployment Maintenance (`#323`, Parent `#320`)**: Hardened `src/bootstrap.php` to fail closed under `.maintenance_deploy.json` without an arbitrary 120s timeout, preventing mixed-code execution during interrupted FTPS uploads (remediating Adversarial HIGH H-02).
- **Atomic Engagement Checkout Transactions & SQL Aggregate Momentum Badges (`#324`, Parent `#320`)**: Wrapped 24h deduplication checks, event inserts, and score recomputations in a single database transaction in `EngagementRanker::recordCheckout()` to eliminate concurrency races (remediating Adversarial MEDIUM M-03), and replaced in-memory full-directory scans in `EngagementRanker::computeMomentumContext()` with fast indexed SQL aggregate queries (remediating Adversarial MEDIUM M-04).

## [0.27.1] - 2026-11-13
### Fixed
- **Non-Kenyan Phone Numbers Rewritten as +254 (`#318`)**: Every submitted phone was passed through the Kenya-only `normalizeKenyanPhone()`, so Swiss mobiles (`079 123 45 67`) were silently stored as `+254791234567` and other local numbers lacked a country code (breaking `wa.me` links). Added `src/Services/PhoneNormalizer.php` (country-aware E.164 normalization for all 197 supported countries — calling codes, trunk prefixes incl. NANP `1`, Hungary `06`, Russia/Kazakhstan `8`, and Italy's significant leading `0`), applied it with the listing's selected country across `public/submit.php` and all `VettingPurger` write paths (admin add/edit and owner edit, which previously stored phones un-normalized), and made website auto-fill infer country only from ccTLDs (`.co.ke`, `.ch`, `.co.uk`) without ever guessing for generic TLDs. Live audit confirmed all 25 existing listings were unaffected. Added Section 54 unit assertions.

## [0.27.0] - 2026-11-13
### Added
- **Directory Sort Selector (`#313`, `#314`)**: Added a `Sort:` dropdown beside the per-page control on `public/index.php` (Most Referenced, Trending This Week, Newest Added, Nearest to Me, Alphabetical, Directory #), wired in `public/assets/js/directory.js` with `?sort=` URL sync via `history.replaceState`, server-resolved sort reflection, and automatic browser-location request when *Nearest to Me* is chosen without coordinates.
- **Dignified Momentum Badges (`#315`)**: Added `EngagementRanker::computeMomentumContext()` / `resolveMomentumBadge()` emitting only a `momentum_badge` flag (`popular` | `new` | `null`) from `/api/businesses.php` and homepage SSR. `🔥 Popular with Busherians` marks top-decile listings with at least 3 references in 90 days; `✨ New on Biasharas` marks listings in their first 14 days, shown only while newcomers are a distinctive minority (≤ 30% of listings) so launch-week directories are not blanketed. No raw counts, scores, or "low engagement" labels are ever shown publicly.
- **Private Owner Engagement Insights (`#316`)**: Added `EngagementRanker::getOwnerEngagementSummary()` and `renderOwnerInsightsHtml()`, rendering a per-listing 30-day / 90-day breakdown (Profile Checkouts, Direct Contacts, Direction Requests, Website & Social Visits, Concierge Recommendations) on `/owner/index.php`, visible only to the signed-in linked owner.

## [0.26.0] - 2026-11-13
### Added & Changed
- **Default Engagement-Ranked Directory Ordering, Explicit Sort Modes & 2-Hour Tie Rotation (`#309`, `#310`)**: Updated `public/index.php` (SSR Page 1) and `public/api/businesses.php` (both standard SQL pagination and batched `open_now=1` scanning) to default to descending time-decayed engagement band order (`most_referenced`) with deterministic 2-hour bijective near-tie rotation (`EngagementRanker::buildEngagementBandSqlExpr` & `buildTieJitterSqlExpr`) and automatic `nearest` fallback when proximity coordinates (`lat`/`lng`) are supplied without an explicit override. Added explicit `sort=most_referenced|trending|newest|nearest|az|directory_number` query modes across SQLite and MySQL 8.0 without exposing raw click counts publicly.
- **Biashara Concierge Citation Integration (`#311`)**: Added `EngagementRanker::recordConciergeCitation()`, `?id=<business_id>` single-record lookup on `public/api/businesses.php`, `[data-concierge-biz-id]` recommendation card click delegation, and `/?biz=<id>&ref=concierge` deep-link modal hydration + deferred human-verified `concierge_ref` (`3.0` pts) attribution in `public/assets/js/directory.js`.

## [0.25.0] - 2026-11-13

### Added
- **Engagement Ranking Schema, Composite Index, Weighted Click Events & 24h Deduplication Table (`#305`, Parent `#304`)**: Added `engagement_score` (`DECIMAL(10,4) NOT NULL DEFAULT 0.0000`), `reference_count_90d` (`INT UNSIGNED NOT NULL DEFAULT 0`), and composite index `idx_biz_status_engagement (status, engagement_score DESC, id DESC)` on `businesses`, added `signal_group` (`VARCHAR(32) NULL`), `signal_weight` (`DECIMAL(5,2) NOT NULL DEFAULT 1.00`), and `idx_click_biz_created (business_id, created_at)` on `click_events` (with automatic backfill of legacy click rows), created `engagement_dedup_24h` (`dedup_hash CHAR(64) PRIMARY KEY`, `expires_at INT UNSIGNED NOT NULL`), bumped `Database::SCHEMA_INIT_VERSION` to `'2026-11-v25-engagement'`, and added one-time `engagement_scores_backfill_v1` + `Database::getSchemaReadiness()` verification across MySQL 8.0 and SQLite (`src/schema.sql`, `src/Config/Database.php`).
- **Cookie-Free, Sybil-Resistant `/api/record-checkout.php` Endpoint (`#306`, Parent `#304`)**: Added `public/api/record-checkout.php` (`POST` JSON, anonymous cookie-free headers emitting zero `Set-Cookie` headers and storing zero visitor profiles), wired human-interaction proof (`human_verified_*`) and checkout recording across modal opens (`profile_checkout` / `concierge_ref`), website/social links (`external_link`), directions/map actions (`directions_ref`), and Call/WhatsApp/Email/Booking actions (`direct_contact`) (`public/assets/js/directory.js`, `public/api/book-appointment.php`, `public/api/track-click.php`, `public/sw.js` `bbiz-pwa-v0.25.0`), enforced daily-rotating 24h deduplication `HMAC-SHA256(UTC_DATE | IP_HMAC | UserAgent_Class | business_id | signal_group, IP_HASH_SECRET)`, and excluded signed-in owners clicking their own linked listings and signed-in admins without starting anonymous PHP sessions.
- **`EngagementRanker` Dual-Horizon Decay Engine, CLI Recompute Worker & DPA Retention Sweep Integration (`#307`, Parent `#304`)**: Added `src/Services/EngagementRanker.php` computing `0.60 * DecayedPoints_14d + 0.30 * DecayedPoints_90d + 0.10 * ln(1 + LifetimeRefs) + ColdStartBoost_14d` (`3.5` pt newcomer boost decaying linearly over 14 days), wired lifecycle score initialization/zeroing/restoration and retention sweep refresh into `src/Services/VettingPurger.php`, added flock-guarded `bin/recompute-engagement-ranks.php`, and added Section 51 unit assertions (`tests/run_unit_tests.php`) + `test_playwright_ch3_chunk10_engagement_checkout_recorder` (`e2e/test_smoke.py`).

## [0.24.1] - 2026-11-13

### Fixed
- **Community Charter FAQ Copy Typo (`#302`)**: Corrected `"override organic search oralumni verification?"` to `"override organic search or alumni verification?"` in `public/charter.php`, bumped Service Worker cache key to `bbiz-pwa-v0.24.1` (`public/sw.js`), and added regression assertion in `tests/run_unit_tests.php`.

## [0.24.0] - 2026-11-13

### Added
- **Community Charter & Founder FAQ Page (`#299`, Parent `#298`)**: Added shared single-source-of-truth charter renderer `bbiz_render_community_charter_clauses()` (`src/bootstrap.php`) and standalone `public/charter.php` (`X-BBiz-Public-Cacheable: 1`) publishing Clauses 1–4 (*Free-Forever Core Listing Guarantee*, *Zero Fee on Direct Bookings & Self-Served B2B Leads*, *Optional Growth Tools & Fair Placement Cap — max 2 badged Pro spots/category & max 1 labeled Corporate Sponsor/sector*, and *Verification & Privacy Non-Negotiables — zero non-alumni listings, immediate school vetting purge & zero ad-tech trackers*), and allowlisted `/charter.php` in `public/api/track-metric.php` and `public/sw.js` (`bbiz-pwa-v0.24.0`).
- **Registration & Homepage Free-Forever Guarantee Callouts + Charter Modal (`#300`, Parent `#298`)**: Added top `100% Free-Forever Core Guarantee` callout (`data-testid="submit-charter-callout"`) on `public/submit.php`, added footer Charter summary strip + interactive `#community-charter-modal` on `public/index.php` (`public/assets/js/directory.js`, `public/assets/css/app.css`), and added Section 50 PHP unit assertions (`tests/run_unit_tests.php`) + `test_playwright_ch1_chunk4_community_charter` (`e2e/test_smoke.py`).

## [0.23.0] - 2026-11-13

### Added
- **Installable Progressive Web App (PWA) Manifest, Maskable Heritage Icon & Standalone Meta Tags (`#288`, Parent `#287`)**: Generated full-bleed W3C safe-zone maskable icon `public/assets/img/bb-icon-maskable-512.png` (`512x512` opaque RGB `#114B3E` -> `#7B1113` heritage gradient), upgraded `public/site.webmanifest` and mirrored `public/manifest.webmanifest` (`display: "standalone"`, `start_url: "/?source=pwa"`, `scope: "/"`, `theme_color: "#7B1113"`, `short_name: "Biasharas"`, separate `purpose: "any"` and `purpose: "maskable"` icon entries, and 4 quick-launch `shortcuts`), added `application/manifest+json` MIME + `Service-Worker-Allowed: /` + `no-cache` headers in `public/.htaccess`, and emitted standalone mobile-web-app / iOS home-screen meta tags in `bbiz_head_meta_tags()` (`src/bootstrap.php`).
- **Privacy-Safe Network-First Service Worker & Offline Directory Snapshot (`#289`, Parent `#287`)**: Added `public/sw.js` (`bbiz-pwa-v0.23.0`) enforcing a strict same-origin `GET`-only allowlist (explicitly excluding cross-origin tile servers, non-`GET` requests, `/admin/*`, `/owner/*`, `/submit.php`, `/takedown.php`, `/uploads/*`, and write/tracking APIs), anonymous `{ credentials: 'omit' }` pre-caching plus `X-BBiz-Public-Cacheable: 1` runtime cache gating on unauthenticated `public/index.php`, `public/privacy.php`, and `public/api/businesses.php` responses (ensuring `owner_email_hints` and edit links never enter Cache Storage), Network-First live delivery so Kenya DPA s. 40 takedowns and live EAT hours take effect immediately when online, and `X-BBiz-Offline-Snapshot: 1` + client-side offline search/filter fallback + `#pwa-offline-banner` when offline (`public/assets/js/directory.js`, `public/assets/css/app.css`).
- **Smart `📲 Install App` Header CTA, 2-Step Home-Screen Guide Modal & PWA Shortcut Deep-Links (`#290`, Parent `#287`)**: Added `#btn-install-app` and accessible `#pwa-install-modal` (`public/index.php`) capturing native `beforeinstallprompt` on Android/Chromium while providing a 2-step platform-aware install guide on iOS/iPadOS Safari and desktop browsers (auto-hidden when running in `standalone` mode), added `?filter=bookable|has_perk|b2b_ready|open_now|AHS|AGHS` and `?q=...` URL shortcut deep-links in `public/assets/js/directory.js`, and added Section 49 unit tests (`tests/run_unit_tests.php`) + `test_playwright_ch1_chunk5_installable_pwa` (`e2e/test_smoke.py`).

## [0.22.0] - 2026-11-13

### Added
- **Appointment & Consultation Booking Schema, Write Validation & Listing Forms (`#283`, Parent `#282`)**: Added `booking_enabled` (`TINYINT(1) NOT NULL DEFAULT 0`), `booking_url` (`VARCHAR(350) NULL`), and composite index `idx_status_booking` (`src/schema.sql`, `src/Config/Database.php`, `SCHEMA_INIT_VERSION = '2026-11-v22-booking'`), added `VettingPurger::normalizeBookingFields()` + `AntiSpamGate::isBlockedSpamOrShortenerUrl()` (enforcing `http(s)://` URLs up to 350 chars, rejecting URL shorteners and spam TLDs, auto-enabling booking when a new booking URL is entered, and requiring at least one phone/WhatsApp/email channel for built-in booking), wired persistence across `submit.php`, `createBusinessDirectly()`, `updateBusiness()`, and `updateBusinessByOwner()`, and added `bbiz_render_booking_fields()` + `sessionStorage` draft support across all 4 listing forms (`src/bootstrap.php`, `public/assets/js/form-enhancements.js`).
- **Modal `📅 Book Appointment` CTA, UTM Attribution & 7-Layer Anti-Spam Built-In Booking Dispatch (`#284`, Parent `#282`)**: Added `public/api/book-appointment.php` (lazy on-demand `GET ?action=tokens` keeping initial `GET /` session-cookie-free, plus `POST` email booking dispatch resolving `email_public` / `owner_email` strictly server-side with zero `owner_email` client exposure), `AntiSpamGate::verifyBookingInquiry()` enforcing all **7 anti-spam layers** (disguised off-screen `website` field, signed `HMAC-SHA256` `>= 4s` & `<= 2h` time-trap + single-use `used_nonces` replay lock, `human_verified_*` interaction token, CamelCase/URL/domain/TLD/ALL-CAPS/scam heuristics, and `2/15m` + `5/24h` + `15/1h` rate limits with silent HTTP `200` spam drop), `Mailer::sendBookingInquiry()` + Kenya DPA s. 38 third-party inquiry PII redaction in `OwnerAuth::exportOwnerPersonalData()`, external scheduler `withBookingAttribution()` (`utm_campaign=appointment_booking`), and built-in WhatsApp + Email booking drawer in `public/assets/js/directory.js` + plain-language disclosure in `public/privacy.php`.
- **Directory `📅 Book Appointment` Filter Pill, Card Badges & Booking Conversion Analytics (`#285`, Parent `#282`)**: Added `bookable=1` filtering and `is_bookable` / `has_booking_email` serialization in `public/api/businesses.php` and `public/index.php`, added `#quick-filter-bookable` (`📅 Book Appointment`) filter pill and `.badge-bookable` card/modal badges, added `'booking'` event tracking in `public/api/track-click.php`, `public/api/track-metric.php`, `public/owner/index.php` (`booking_clicks_total`), `public/admin/metrics.php` (`Bookings` column), and B2B CSV shortlist export, and added Section 48 unit tests (`tests/run_unit_tests.php`) + `test_playwright_ch1_chunk3_appointment_booking` (`e2e/test_smoke.py`).

## [0.21.0] - 2026-11-12

### Added
- **Multi-Sector / Secondary Specialty Schema, Validation & Dual-Category Search API (`#279`, Parent `#278`)**: Added nullable `secondary_sector_id` and `secondary_specialty_id` foreign keys plus composite index `idx_status_sec_sector_spec` (`src/schema.sql`, `src/Config/Database.php`, `SCHEMA_INIT_VERSION = '2026-11-v21-sec-spec'`), added `VettingPurger::normalizeSecondaryCategoryPair()` to enforce valid approved sector/specialty pairing while rejecting half-empty pairs and duplicates of the primary specialty, persisted secondary categories across `createBusinessDirectly()`, `updateBusiness()`, and `updateBusinessByOwner()` (with `Secondary Category` critical-edit courtesy alerts), and updated `/api/businesses.php` (using distinct MySQL 8.0 native prepared placeholders), `public/index.php`, and `OwnerAuth::getBusinessesForOwner()` so cross-sector founders appear under **both** their primary and secondary categories and keyword searches.
- **Optional Secondary Specialty Form UI, Secondary Natural-Language Quick Finder & Multi-Business Portfolio Guidance (`#280`, Parent `#278`)**: Added `bbiz_render_secondary_specialty_fields()` (`src/bootstrap.php`), reusable `attachFinderWidget()` + cascading secondary selectors + `#btn-clear-secondary-specialty` + combined Primary/Secondary statutory regulator visibility sync + 2-hour `sessionStorage` draft persistence (`public/assets/js/form-enhancements.js`), secondary category badges on directory cards, detail modals, owner portal cards, and B2B procurement CSV exports (`public/assets/js/directory.js`, `public/owner/index.php`, `public/assets/css/app.css`), and a clear **Multi-Business Portfolio** guidance note across `/submit.php`, `/owner/edit-business.php`, `/admin/add-business.php`, and `/admin/edit-business.php` directing alumni with separate companies to submit independent listings under the same Owner Login Email.
- **Unit & Playwright E2E Coverage (`#278`–`#280`)**: Added Section 47 unit assertions in `tests/run_unit_tests.php` and `test_playwright_ch1_chunk2_secondary_specialty_and_multi_business_guidance` in `e2e/test_smoke.py`.

## [0.20.1] - 2026-11-12

### Enhanced
- **Natural-Language Specialty Finder, Morphological Stemming & 116-Specialty Concept Graph (`#276`, Parent `#272`)**: Upgraded `CategoryMatcher` (`src/Services/CategoryMatcher.php`), `bbiz_render_specialty_finder()` (`src/bootstrap.php`), and `bindSpecialtyQuickFinder()` (`public/assets/js/form-enhancements.js`) with English/Swahili conversational stopword stripping (`NL_STOPWORDS`), occupational & morphological root stemming (`OCCUPATION_STEM_MAP` + `stemWord()`), Sørensen–Dice character-bigram typo tolerance, and a rich domain concept vocabulary (`SPECIALTY_CONCEPT_KEYWORDS`) across all `116` Level 2 specialties so first-person sentences (e.g. `"I am an arthropod macro photographer"`, `"We help first-time authors edit and publish their memoirs"`, `"I fix broken refrigerators and washing machines"`) resolve instantaneously in `< 3ms` with zero external API latency or PII sharing.

## [0.20.0] - 2026-11-12

### Added
- **Publishing Taxonomy Expansion & English/Swahili Synonym Lexicon (`#273`, Parent `#272`)**: Expanded `CategoryMatcher::DEFAULT_TAXONOMY` (`116` Level 2 specialties across `15` Industry Sectors) to represent publishing across both Creative/Media (`Book, Academic & Digital Publishing`, `Editorial, Ghostwriting & Author Services`, `Podcast, Broadcast & Content Studio`) and Education/Coaching (`Author Coaching & Manuscript Advisory`), added safe in-place legacy slug migration in `CategoryMatcher::ensureTaxonomyUpToDate()` (`SCHEMA_INIT_VERSION = '2026-11-v20-pub'`), expanded `CategoryMatcher::SYNONYM_LEXICON` with English and Swahili publishing/editorial terms (`publisher`, `publishing`, `book`, `books`, `author`, `editing`, `editorial`, `proofreading`, `ghostwriting`, `manuscript`, `isbn`, `mchapishaji`, `uchapishaji`, `vitabu`, `kitabu`, `mwandishi`), and added `CategoryMatcher::searchSpecialties()` plus synonym-aware `suggestCategoryFromText()` scoring.
- **Instant Type-Ahead Specialty Finder Across All Listing Forms (`#274`, Parent `#272`)**: Added `bbiz_render_specialty_finder()` (`src/bootstrap.php`), `.specialty-finder-*` heritage styles (`public/assets/css/app.css`), and keyboard-accessible (`ArrowDown`/`ArrowUp`/`Enter`/`Escape`) + click type-ahead search (`public/assets/js/form-enhancements.js`) above the Sector/Specialty selectors on `/submit.php`, `/owner/edit-business.php`, `/admin/add-business.php`, and `/admin/edit-business.php`, auto-selecting both Level 1 Sector and Level 2 Specialty (and updating statutory regulator visibility) in one click.
- **Unit & Playwright E2E Coverage (`#272`–`#274`)**: Added Section 46 unit assertions in `tests/run_unit_tests.php` and `test_playwright_ch1_chunk1_publishing_specialty_finder` in `e2e/test_smoke.py`.

## [0.19.0] - 2026-11-12

### Security & Kenya DPA 2019 Hardening (Pass-3 Wave 3 — MEDIUM)
- **Uncapped Subject Export, Third-Party Redaction & Admin-Correspondence Subject Erasure (`#241` / `M-01`, Parent `#230`)**: Removed the `LIMIT 100` cap in `OwnerAuth::exportOwnerPersonalData()`, included full listing columns, `reply_to`, redacted correspondence bodies (scrubbing signed URL query tokens and third-party email addresses via `[redacted-third-party-email]`), and linked-business `takedown_requests` (redacting third-party complainant PII), and updated `OwnerAuth::deleteOwnerAccountAndData()` to scrub subject email, full name, and phone inside administrator-addressed `email_outbox` records.
- **Locked Co-Owner-Safe Erasure Classification (`#242` / `M-02`, Parent `#230`)**: Moved `linkedBizIds` lookup and sole-vs-co-owned classification inside `OwnerAuth::deleteOwnerAccountAndData()`'s active database transaction behind `owner_erasure_lock` and sorted per-business `VettingPurger::acquireBusinessLifecycleLock()` locks so concurrent ownership links cannot lose co-owned listings.
- **Batched `open_now` Schedule Evaluation Without `500`-Candidate Truncation (`#243` / `M-03`, Parent `#231`)**: Replaced the `LIMIT 500` `open_now=1` query in `public/api/businesses.php` with bounded `LIMIT 250 OFFSET` schedule-only batch scans across all matching candidates, computing exact `total_count` and hydrating only the `<= per_page` winning rows in distance/number order.
- **Server-Side `page_path` Sanitization & Route Allowlisting (`#244` / `M-04`, Parent `#230`)**: Hardened `public/api/track-metric.php` to extract `PHP_URL_PATH` (stripping all `?query` and `#fragment` strings), normalize paths, and map routes strictly against an application route allowlist (recording any non-allowlisted path as `/[other]`).
- **Durable Server-Side Analytics Consent Provenance & Fixed Non-Rolling 12-Month Expiry (`#245` / `M-05`, Parent `#230`)**: Added `analytics_consents` (`src/schema.sql`, `Database.php`), `public/api/consent.php`, and non-rolling `CONSENT_META_KEY` TTL enforcement in `public/assets/js/consent-metrics.js` (`CONSENT_NOTICE_VERSION = '2026-11-v4'`), requiring an active server-side consent record under the current notice version in `public/api/track-metric.php`, purging consent + visitor metrics on withdrawal/rejection, and sweeping expired consents in `VettingPurger::enforceRetentionCeilings()`.
- **Adult-Only (`18+`) Onboarding Gate for First-Time Google OAuth & Owner Listing Edits (`#246` / `M-06`, Parents `#229`, `#230`)**: Updated `public/owner/google-callback.php` to hold first-time Google OAuth profiles in ephemeral session state until an explicit `POST` `age_attestation_18` + Privacy Notice confirmation is submitted before calling `OwnerAuth::upsertOwner()`, and required `owner_adult_attestation_18` across `public/owner/edit-business.php` and `VettingPurger::updateBusinessByOwner()` (updating `age_attested_at` and `privacy_notice_version`).
- **Sub-Processor & Cross-Border Transfer Register + Notice `2026-11-v4` Alignment (`#247` / `M-07`, Parent `#230`)**: Added `docs/DPA_TRANSFER_REGISTER.md` mapping every external integration, endpoint, data element, Kenya DPA ss. 48–50 transfer ground, technical safeguard, and hosting backup/DPA verification checklist, and aligned `public/privacy.php` (`2026-11-v4`, zero `<code>` tags).
- **Token-Fenced 120s Outbox Worker Leases & Total SMTP Delivery Deadline (`#248` / `M-08`, Parent `#231`)**: Added `lease_token` to `email_outbox`, extended `Mailer::OUTBOX_LEASE_SECONDS` to `120`s while bounding `Mailer::SMTP_TOTAL_DEADLINE_SECONDS` to `25`s via dynamic remaining-deadline `stream_set_timeout()` in `Mailer::sendViaAuthenticatedSmtp()`, and fenced completion updates through `Mailer::finalizeLeasedOutboxRow(... WHERE status = 'sending' AND lease_token = :ltok)`.
- **Central `15/hour` Production Outbound SMTP Quota Alignment (`#249` / `M-09`, Parents `#229`, `#231`)**: Aligned `AntiSpamGate::CENTRAL_DISPATCH_MAIL_LIMIT_1H` to `15` (`<= 15 / 1h`) so every enqueued and retried outbound email enforces the mandatory `15/hour` production circuit breaker centrally.
- **TLS-Enforced Production SMTP & Disabled Unencrypted PHP `mail()` Fallback (`#250` / `M-10`, Parents `#229`, `#230`)**: Added `Mailer::isSmtpSecurityAllowed()` (requiring `'ssl'` or `'tls'` with peer certificate verification in `production`), disabled local PHP `mail()` fallback by default (`Mailer::isPhpMailFallbackEnabled() === false`, keeping failed messages queued for encrypted retry), and documented explicit TLS SMTP settings in `.env.example`.
- **Post-Deploy Schema Readiness Verification & MySQL 8.0 HTTP Runtime Smoke Gate (`#251` / `M-11`, Parent `#232`)**: Added `Database::getSchemaReadiness()` (`SCHEMA_INIT_VERSION = '2026-11-v19-med'`), exposed `schema_version` and `schema_ready` in `public/api/health.php`, allowed post-deploy `?verify_schema=1` locked schema migration when `.deploy_manifest.json` is present, added a post-promotion live `/api/health.php?verify_schema=1` gate in `.github/workflows/deploy-ftp.yml`, added a MySQL 8.0 HTTP runtime smoke step in `.github/workflows/unit.yml`, and added Section 45 unit + Playwright E2E regressions (`tests/run_unit_tests.php`, `e2e/test_smoke.py`).

## [0.18.0] - 2026-11-12

### Security & Kenya DPA 2019 Hardening (Pass-3 Wave 2 — HIGH)
- **Admin Header Auth Rate Limiting & Automation Secret Separation (`#236` / `H-01`, Parent `#229`)**: Enforced `AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'admin_login', ...)` inside `AdminAuth::isAuthenticated()` before checking `HTTP_X_ADMIN_TOKEN`, separated `AdminAuth::verifyAutomationHeaderToken()` so human `ADMIN_PASSWORD_HASH` passwords are never accepted via headers, and disabled `HTTP_X_ADMIN_TOKEN` in `APP_ENV=production` unless `ALLOW_ADMIN_HEADER_TOKEN=true`.
- **Takedown Authoritative Owner Email Boundary (`#237` / `H-02`, Parent `#229`)**: Removed public `email_public` from immediate owner auto-quarantine eligibility in `public/takedown.php` and added `VettingPurger::isAuthoritativeOwnerEmailForTakedown()` enforcement inside both `submitTakedownRequest()` and `confirmOwnerTakedownByToken()`, escalating non-owner public inbox or transferred-ownership tokens to `'pending_review'`.
- **Atomic FTPS Mid-Promotion Rollback & Fail-Closed Maintenance Gate (`#238` / `H-03`, Parent `#232`)**: Journaled `(r_dir, backup_name, dst_name)` into `promoted_backups` immediately after `ftp.rename(dst_name, backup_name)` (before `ftp.rename(stg_name, dst_name)`) in `.github/scripts/ftps_deploy.py`, refreshed `.maintenance_deploy.json` periodically during multi-file promotions, and retained a non-expiring `"rollback_failed": true` maintenance gate in `src/bootstrap.php` if rollback restoration itself fails.
- **Quarantine Media Lifecycle Serialization & Private Photo Staging (`#239` / `H-04`, Parent `#230`)**: Added per-business database locks (`VettingPurger::acquireBusinessLifecycleLock()` / `acquireNamedSequenceLock()`), staged newly uploaded photos privately inside `storage/quarantined_media/<businessId>/` via `PhotoUploader::processBusinessPhotos()`, promoted photos to `public/uploads/businesses/` only after commit when locked status is `'approved'`/`'pending'`, and serialized `PhotoUploader::sweepOrphanedMedia()` via `media_sweep_lock`.
- **Durable `pending_media_deletions` Journal & Honest Erasure Reporting (`#240` / `H-05`, Parent `#230`)**: Added `pending_media_deletions` table (`SCHEMA_INIT_VERSION = '2026-11-v18-high'`), enqueued and drained media deletion obligations across `VettingPurger::deleteBusiness()`, `OwnerAuth::deleteOwnerAccountAndData()`, `enforceRetentionCeilings()`, and `/admin/index.php`, and surfaced explicit partial/queued media-cleanup notices on owner/admin erasure flows whenever disk unlinks require retry.
- **Regression Suite (`#236`–`#240`)**: Added Section 44 (`44a`–`44d`) unit tests in `tests/run_unit_tests.php`, FTPS mid-promotion & rollback-failure fault-injection tests in `tests/test_unit.py`, and Playwright browser E2E regressions in `e2e/test_smoke.py`.

## [0.17.1] - 2026-11-12

### Fixed

- **Exact Schema-Aligned Submitter Vetting Field Bounds (`[Adversarial R3-LOW-01]`)**: Added `VettingPurger::validateVettingIdentityPayload()` and explicit server-side + HTML `maxlength` checks in `public/submit.php` aligning `house_name` (`<= 80`), `school_number` (`<= 30`), `submitter_name` (`2..150`), `submitter_email` (`<= 190`), and `submitter_phone` (`<= 40`) with `business_vetting_queue` in `src/schema.sql` (closes #233, part of #231).
- **Batched `owner_email_hints` Queries on Authenticated Directory Responses (`[Adversarial R3-LOW-02]`)**: Added `OwnerAuth::getMaskedOwnerEmailHintsForBusinesses()` and replaced per-listing `getMaskedOwnerEmailHintsForBusiness()` loops in `public/api/businesses.php` and `public/index.php` so authenticated pages resolve masked hints in at most two `IN (...)` SQL queries (closes #234, part of #231).
- **CLI `flock()` Overlap Guards, Non-Cancellable Pre-Prod Promotion & CI Python Parity Docs (`[Adversarial R3-LOW-03]`)**: Added `bbiz_acquire_cli_worker_lock()` non-blocking `flock(LOCK_EX | LOCK_NB)` overlap guards to `bin/process-email-outbox.php` and `bin/purge-dpa-retention.php`, set `cancel-in-progress: false` in `.github/workflows/deploy-preprod.yml`, and documented local `ds` conda (`Python 3.11`) vs GitHub Actions (`actions/setup-python@v5`, `Python 3.11`) parity in `README.md` (closes #235, part of #232).

## [0.17.0] - 2026-11-12

### Security
- **Explicit Claim Publication Consent & Removal of Silent GET Auto-Redemption (`[Adversarial R2-MED-01]`)**: Separated ownership verification from Kenya DPA 2019 publication consent across `OwnerAuth::redeemVerifiedClaimInvite()` and `public/owner/claim.php`, replacing automatic `GET` claim redemption for signed-in owners with an explicit `POST` `authenticated_claim` confirmation form and requiring explicit `claim_publication_consent` and `claim_age_attestation_18` checkboxes across all claim onboarding flows (closes #209, part of #197, part of #198).
- **Ephemeral 2-Hour `sessionStorage` Submission Drafts Excluding Vetting PII, Media & Consents (`[Adversarial R2-MED-02]`)**: Migrated `public/assets/js/form-enhancements.js` from indefinite `localStorage` (`bbiz_submit_draft_v1`, purged automatically on load) to tab-scoped `sessionStorage` (`bbiz_submit_draft_v2`) with a strict 2-hour TTL, excluding `house_name`, `house_custom`, `year_left`, `school_number`, `business_photos_data_json`, `age_attestation_18`, and `dpa_consent_directory` (closes #210, part of #198).
- **Exclusive `email_outbox` Worker Leases, Challenge Freshness Suppression & Exponential Retry Backoff (`[Adversarial R2-MED-03]`)**: Added `lease_until`, `next_retry_at`, and `expires_at` columns (`status` including `'sending'` and `'expired'`) to `email_outbox`, enforced atomic single-statement worker leasing (`rowCount() === 1`), suppressed expired or superseded OTP / magic-link challenges prior to SMTP dispatch, and added exponential retry backoff + POSIX `flock()` execution locking in `bin/process-email-outbox.php` (closes #211, part of #199).
- **Central Dispatch-Time Mail Quota Ceiling & Fail-Closed Rate-Limit Serialization Mutex (`[Adversarial R2-MED-04]`)**: Added `AntiSpamGate::consumeCentralDispatchQuota()` enforced directly inside `Mailer::dispatchOutboxRow()` across all web and retry callers, and hardened `AntiSpamGate::acquireRateLimitSerializationLock()` to self-heal missing `rate_limit_mutex` rows or throw `RuntimeException` rather than silently swallowing lock errors (closes #212, part of #199).
- **Isolated Per-IP & Per-Account Authentication Throttles (`[Adversarial R2-MED-05]`)**: Added `AntiSpamGate::checkAuthAttemptAllowed()` enforcing per-IP and keyed per-account brute-force limits across `AdminAuth::login()`, `public/owner/login.php`, and `public/owner/claim.php` without a shared global ceiling that could let an attacker lock out unrelated alumni users (closes #213, part of #197).
- **Adult-Only (18+) Data-Subject Ingestion Gates & Urgent Minor-Data Takedown Escalation (`[Adversarial R2-MED-16]`)**: Enforced Kenya DPA 2019 s. 33 adult-only (`18+`) data-subject and media attestations across `public/submit.php`, `public/admin/add-business.php` (`require_adult_attestation`), and `public/owner/claim.php`, and added a dedicated urgent `minor_data_s33` escalation category on `public/takedown.php` (closes #224, part of #198).
- **Server-Revocable `AdminAuth` Sessions & Credential-Rotation Invalidation (`[Adversarial R2-MED-17]`)**: Bound `AdminAuth` sessions to both a server-side `admin_auth_version` counter in `app_settings` (`AdminAuth::revokeAllAdminSessions()`) and an HMAC fingerprint of current admin credentials (`currentCredentialFingerprint()`), and added a CSRF-protected `Revoke All Admin Sessions` button on `public/admin/index.php` (closes #225, closes #197).

### Privacy & Kenya DPA 2019
- **Comprehensive Field & Table Retention Sweep (`[Adversarial R2-MED-06]`)**: Expanded `VettingPurger::enforceRetentionCeilings()` and `bin/purge-dpa-retention.php` (with `flock()` overlap guard) to redact >30-day `email_outbox` `recipient_email`, `reply_to`, and `subject` alongside `body_html`, delete >7-day `owner_auth_tokens`, redact >90-day resolved `takedown_requests` PII, clear finalized `categories.submitted_by_email`, purge >180-day `click_events` alongside `usage_metrics`, and pseudonymize >365-day `moderation_audit_log` personal emails and IP hashes (closes #214, part of #198).
- **Self-Service Owner JSON Data Export & Co-Owner-Safe Subject Account Erasure (`[Adversarial R2-MED-07]`)**: Added `OwnerAuth::exportOwnerPersonalData()` and `OwnerAuth::deleteOwnerAccountAndData()` wired to `public/owner/index.php` so verified owners can download a structured JSON archive of their personal records or permanently erase their Owner Account, tokens, sessions, category attribution, and correspondence while preserving co-owned businesses for co-owners and verifying complete disk media removal on deleted sole-owned listings (closes #215, part of #198).
- **Plain-Language Privacy Notice Aligned with Live Flows, Processors & Transfers (`[Adversarial R2-MED-08]`)**: Updated `public/privacy.php` (`PRIVACY_NOTICE_VERSION = '2026-11-v3'`, zero `<code>` or SQL strings) to document every data category, processor, cross-border transfer safeguard (Kenya DPA ss. 48–49), table-level retention window, and self-service export/erasure right (closes #216, part of #198).
- **Unbundled Analytics vs Map-Tile Consent & `track-metric.php` PII Scrubbing (`[Adversarial R2-MED-09]`)**: Decoupled `Allow for Analysis` (`consent-metrics.js` pseudonymous visitor wording) from OpenStreetMap tile loading (`directory.js` loads `tile.openstreetmap.org` strictly after explicit `#btn-enable-osm-map` opt-in), and hardened `public/api/track-metric.php` to scrub accidental emails, phone numbers, and query strings from `meta_label` and `page_path` behind per-IP rate limits (closes #217, closes #198).

### Changed
- **SQL Haversine Spatial Pagination, Bounded `open_now` Scan & Read API Budgets (`[Adversarial R2-MED-10]`)**: Moved Haversine distance filtering, radius cutoffs, and distance sorting directly into SQL across MySQL 8.0 and SQLite in `public/api/businesses.php`, bounded `open_now=1` candidate scans (`LIMIT 500`), capped search/custom-category query lengths (`<= 120` chars), and enforced per-IP read budgets on `public/api/businesses.php` and `public/api/categories.php` (closes #218, part of #199).
- **Whole-Release Two-Phase FTPS Staging, Maintenance Gate, Rollback & Rename Source Retirement (`[Adversarial R2-MED-11]`)**: Upgraded `.github/scripts/ftps_deploy.py`, `.github/workflows/deploy-ftp.yml`, `.github/workflows/deploy-preprod.yml`, and `src/bootstrap.php` to stage and `SIZE`-verify all release files in Phase 1 before touching live targets, activate a brief `.maintenance_deploy.json` `503` gate during Phase 2 promotion with `.bbiz_prev_*` rollback on mid-promotion failure, refuse unverified `SIZE` responses by default, and retire `git diff -M --name-status` rename source (`R*`) paths alongside `D` deletions (closes #219, part of #200).
- **CLI-First Production Schema Migrations & MySQL `GET_LOCK` Serialization (`[Adversarial R2-MED-12]`)**: Set `BBIZ_DISABLE_WEB_AUTO_MIGRATE=true` in `.env.example` for production deployments using `php bin/migrate-and-seed.php`, and serialized any enabled MySQL web auto-migration behind `GET_LOCK('bbiz_schema_migrate', 15)` in `Database::ensureInitialized()` (closes #220, part of #200).
- **Exact Schema & Validator Column/URL Ceiling Alignment (`[Adversarial R2-MED-13]`)**: Aligned `src/schema.sql`, `Database.php` auto-migrations, and `VettingPurger::validateListingWritePayload()` on exact column ceilings (`tagline <= 180`, `website_url <= 350`, `regulator_name`/`regulator_license <= 120`, `social_* <= 255`, `email <= 190`) so valid inputs never hit `SQLSTATE[22001]` under MySQL `STRICT_TRANS_TABLES` (closes #221, part of #199).
- **Post-Commit Media Unlink Queue, Isolated Post-Commit Mail Flush & Atomic Takedown Confirmation (`[Adversarial R2-MED-14]`)**: Deferred filesystem media deletions in `VettingPurger::deleteBusiness()`, `approveBusiness()`, and `rejectBusiness()` until enclosing database transactions commit (`flushDeferredMediaDeletions()`), isolated post-commit mail dispatch errors in `public/submit.php`, and wrapped `VettingPurger::confirmOwnerTakedownByToken()` + `quarantineBusinessDpa()` atomically (closes #222, closes #199).
- **Multi-Process `proc_open` Concurrency, Boundary, FTPS Two-Phase & Playwright E2E Regression Suites (`[Adversarial R2-MED-15]`)**: Added real 2-worker `proc_open` OS concurrency race suites (exclusive outbox lease + atomic single-use nonce), exact length boundary tests, two-phase FTPS rollback/rename unit tests, and `data-testid`-scoped Playwright browser E2E regressions across `tests/run_unit_tests.php`, `tests/test_unit.py`, and `e2e/test_smoke.py` (closes #223, closes #200).
- tag: `v0.17.0`

## [0.16.0] - 2026-11-12

### Security
- **Fail-Closed Production Email Simulation Prohibition & Strict Non-Production OTP Preview Guard (`[Adversarial R2-HIGH-01]`)**: Added `OwnerAuth::canRenderDevOtpPreview()` requiring **both** `APP_ENV !== 'production'` (or isolated `/admin/preprod` sandbox) **and** `SIMULATE_EMAILS === 'yes'` before assigning or rendering `$devPreviewOtpCode` on `public/owner/login.php` and `public/owner/claim.php`, and updated `Database::validateProductionSecrets()` to fail closed (`RuntimeException` -> HTTP `503`) if a production environment ever leaves `SIMULATE_EMAILS` enabled (`yes`/`true`/`1`) (closes #205, part of #197).
- **Safe `JSON_HEX_*` Inline Script Serialization & DOM-Built Category/Autofill Status Nodes (`[Adversarial R2-HIGH-02]`)**: Added `bbiz_json_for_html_script()` (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`) across all inline `<script>` JSON payloads in `public/owner/edit-business.php`, `public/admin/edit-business.php`, `public/admin/add-business.php`, `public/index.php`, and `public/submit.php` so `</script>` sequences in co-owner regulator text or taxonomy payloads can never break out of `<script>` tags, and refactored `fuzzyBox` and `statusBox` in `public/submit.php` to construct DOM elements with `textContent` rather than `innerHTML` (closes #206, part of #197).
- **Strict Separation of Public Contact Email (`email_public`) from Owner Privilege & One-Time Link Remediation (`[Adversarial R2-HIGH-03]`)**: Removed `email_public` from automatic owner linking (`OwnerAuth::autoLinkMatchingBusinesses()`), password-reset / sign-in recognition (`isRecognizedOwnerEmail()`), masked owner hints (`getMaskedOwnerEmailHintsForBusiness()`), and unscoped claim eligibility when designated owners exist (`isEmailEligibleForClaim()`), surfacing `email_public` matches strictly as non-authoritative claim suggestions (`getCorporateDomainClaimSuggestions()`) and executing a one-time `purged_public_email_links_v1` migration (`OwnerAuth::revokeUnverifiedPublicEmailAutoLinks()`) to revoke historical `email_public`-only links where a distinct `owner_email` exists (closes #207, part of #197).
- **Fail-Closed Quarantine Media Relocation & State-Aware Quarantined Owner Editing (`[Adversarial R2-HIGH-04]`)**: Hardened `PhotoUploader::quarantineBusinessPhotos()` to verify off-webroot vault writes and public file removal before succeeding (throwing `RuntimeException` to roll back `quarantineBusinessDpa()` if any image remains in `public/uploads/`), added `PhotoUploader::deleteRemovedBusinessPhotos()`, and made `VettingPurger::updateBusiness()` and `updateBusinessByOwner()` state-aware and transactional so replacement photos uploaded onto a `quarantined_dpa` listing are immediately relocated into `storage/quarantined_media/<biz_id>/` inside the transaction (closes #208, part of #198).
- tag: `v0.16.0`

## [0.15.1] - 2026-11-12

### Security
- **Approved-Only & Per-IP Throttled Anonymous `?biz=<id>` Hint Lookups (`[Adversarial R2-LOW-01]`)**: Updated `OwnerAuth::getMaskedOwnerEmailHintsForBusiness()` with `$requireApproved` enforcement and restricted unauthenticated `/owner/login.php?biz=<id>` lookups strictly to `status = 'approved'` listings behind per-IP `AntiSpamGate::checkAndRecordRateLimit()` (`owner_biz_hint_lookup`) throttling so pending, rejected, or quarantined business names and masked email hints cannot be enumerated on sequential IDs (closes #201, part of #197).
- **`POST` + Session CSRF Requirement for Owner & Admin Sign-Out (`[Adversarial R2-LOW-03]`)**: Removed `GET`-based session termination on `/owner/logout.php` and `/admin/login.php?logout=1`, requiring `POST` + `AntiSpamGate::verifyCsrfToken()` to log out (while rendering a side-effect-free confirmation screen on `GET`), and converted all Sign Out navigation controls across `public/owner/index.php`, `public/owner/claim.php`, and `public/admin/index.php` into inline `POST` forms carrying session CSRF tokens (closes #203, part of #197).

### Fixed
- **Unambiguous 24-Hour (`00:00`–`24:00`) & Equal-Endpoint Shift Semantics in `HoursEngine` (`[Adversarial R2-LOW-02]`)**: Updated `HoursEngine::validateScheduleJson()`, `buildScheduleJsonFromPost()`, `evaluateStatus()`, and `formatScheduleTable()` to reject ambiguous equal-endpoint intervals (`08:00`–`08:00`, `00:00`–`00:00`), require explicit `["00:00", "24:00"]` for 24-hour open days, and evaluate continuous 24/7 vs single-day 24-hour schedules consistently across midnight in `Africa/Nairobi` (EAT) (closes #202, part of #199).

### Documentation
- **Synced `egm-skills/busherian-biasharas/` (`SKILL.md` + `MEMORY.md`) Across Remote & Local Checkout (`[Adversarial R2-LOW-04]`)**: Added project-specific operational memory archive `MEMORY.md`, ignored local `MEMORY.md` in `.gitignore`, and synchronized both `SKILL.md` and `MEMORY.md` to `ericgitonga/egm-skills` and local `/home/gitonga/Develop/projects/egm-skills/busherian-biasharas/` (closes #204, part of #200).
- tag: `v0.15.1`

## [0.15.0] - 2026-11-12

### Security
- **Transactional Claim Onboarding & `OwnerAuth::getOwnerById()` (`[Adversarial MED-01]`)**: Added `OwnerAuth::getOwnerById()` and wrapped all `password_verify` and `verify_otp` claim onboarding branches in `public/owner/claim.php` inside atomic database transactions so owner profile persistence, password updates, single-use claim invite consumption, and business ownership linking either all succeed together or roll back cleanly (closes #172, closes #158).
- **Atomic Replay Nonces, Serialized Rate Limits, Global Outbound Mail Circuit Breaker & 7-Layer OTP Gate (`[Adversarial MED-02]`)**: Replaced two-step nonce checks with atomic single-statement `AntiSpamGate::claimSingleUseNonce()`, serialized `AntiSpamGate::checkAndRecordRateLimit()`, added `AntiSpamGate::checkAndRecordOutboundMailQuota()` enforcing a `<= 15 / 1h` global outbound mail circuit breaker and `60s` (`<= 4 / 1h`) per-recipient OTP cooldown across `submit.php`, `takedown.php`, `owner/claim.php`, and `owner/login.php`, gated `/owner/login.php` `send_otp` behind the full 7-layer `AntiSpamGate`, and removed unthrottled automatic OTP dispatches from `password_login` (closes #173).
- **Atomic Single-Use OTP & Magic-Link Consumption, Prior-OTP Invalidation & Brute-Force Ceiling (`[Adversarial MED-03]`)**: Updated `OwnerAuth::issuePasswordOtp()` to atomically invalidate prior unused OTP challenges on issuance, added `owner_auth_tokens.failed_attempts` enforcing a strict `< 5` failed-attempt lockout per OTP challenge, and updated `OwnerAuth::verifyOtpAndSetPassword()` and `OwnerAuth::verifyAndConsumeMagicLink()` to consume tokens via single-statement `UPDATE ... WHERE id = :id AND used_at IS NULL` row-count verification (closes #174, part of #158).
- **POST-Only Signed Admin Moderation Links, `pending` State Guard & `moderation_audit_log` (`[Adversarial MED-04]`)**: Bound a cryptographic single-use `jti` token ID into `Mailer::generateSignedAdminActionUrl()` / `verifySignedAdminAction()`, converted `GET /admin/review-action.php` into a side-effect-free confirmation screen (protecting pending listings from email link scanners/prefetchers) while requiring `POST` + session CSRF + atomic `used_nonces` `jti` claim to execute, enforced `WHERE id = :id AND status = 'pending'` preconditions in `VettingPurger::approveBusiness()` / `rejectBusiness()`, and added persistent `moderation_audit_log` recording across approve, reject, quarantine, restore, and delete operations (closes #175).
- **Upfront Upload Count, File/Aggregate Byte Caps & Pre-Decode Image Dimension Guard (`[Adversarial MED-05]`)**: Added `PhotoUploadValidationException` and pre-decode `getimagesizefromstring()` header inspection in `PhotoUploader::processBusinessPhotos()` / `sanitizeAndSaveImageBytes()`, rejecting `> 5` photos, `> 8 MB` single files, `> 20 MB` aggregate payloads, and `> 4096px` or `> 16 MP` images prior to `imagecreatefromstring()` allocation across `submit.php`, `owner/edit-business.php`, `admin/add-business.php`, and `admin/edit-business.php` (closes #176).
- **Orphaned Upload Deletion, Off-Webroot Quarantine Media Vault & Retention Sweep (`[Adversarial MED-06]`)**: Added strict `realpath()` upload-root containment (`PhotoUploader::resolveSafeUploadFilePath()`), post-commit deletion of removed photo files in `VettingPurger::updateBusiness()` and `updateBusinessByOwner()`, automatic relocation of listing media out of public webroot into `storage/quarantined_media/<biz_id>/` during `quarantineBusinessDpa()` (and restoration on `restoreQuarantinedBusiness()`), and `PhotoUploader::sweepOrphanedMedia()` inside `VettingPurger::enforceRetentionCeilings()` (closes #177).
- **Consent-Gated OpenStreetMap Tiles & Plain-Language Third-Party Map Disclosure (`[Adversarial MED-07]`)**: Updated `public/privacy.php` (zero `<code>` tags) to disclose OpenStreetMap (`tile.openstreetmap.org`), Nominatim, and OSRM data flows in plain language, and gated Leaflet tile loading in `public/assets/js/directory.js` and `public/assets/js/consent-metrics.js` behind visitor consent or explicit `#btn-enable-osm-map` opt-in so zero third-party map requests fire on unconsented page loads (closes #178).
- **Kenya DPA 2019 Consent Timestamps, Privacy Notice Versioning & Curated Listing Provenance (`[Adversarial MED-08]`)**: Added `consent_recorded_at`, `privacy_notice_version`, `age_attested_at`, `listing_provenance` (`'owner_submitted'`, `'admin_curated'`, `'claimed'`), and `admin_provenance_note` to `businesses`, recorded explicit consent metadata on `submit.php` and `owner/claim.php`, and required explicit lawful-basis selection and authorization notes on `public/admin/add-business.php` (closes #179, closes #159).
- **Server-Side Paginated Directory API, Batched Ownership Checks & Anonymous Email-Hint Omission (`[Adversarial MED-09]`)**: Added bounded server-side `LIMIT/OFFSET` pagination (`page`, `per_page` in `1..100`, default `10`) and `pagination` metadata to `public/api/businesses.php` and `public/index.php`, replaced per-row `canOwnerEditBusiness()` N+1 queries with batched `OwnerAuth::getEditableBusinessIdsForOwner()`, omitted `owner_email_hints` for unauthenticated callers, and updated `public/assets/js/directory.js` to paginate against server responses with cross-page shortlist retention (closes #180, closes #161).
- **Server & Frontend Category Hierarchy, Coordinate Bounds & Safe `http(s)` URL Scheme Enforcement (`[Adversarial MED-15]`)**: Added `CategoryMatcher::validateSectorAndSpecialtyPair()` and `VettingPurger::validateListingWritePayload()` enforcing valid Level 1 -> Level 2 category pairs, field length/coordinate bounds, and `http(s)://` URL schemes across all write endpoints, plus `sanitizeHttpUrl()` / `withOutboundAttribution()` scheme guards in `public/assets/js/directory.js` blocking `javascript:`, `data:`, and `vbscript:` links (closes #186).

### Changed
- **Atomic Staged Explicit FTPS Promotion, Release Manifest & Retired File Cleanup (`[Adversarial MED-10]`)**: Upgraded `.github/scripts/ftps_deploy.py`, `.github/workflows/deploy-ftp.yml`, and `.github/workflows/deploy-preprod.yml` to upload via temporary `.bbiz_stage_*` files verified with `SIZE` before atomic `RNFR`/`RNTO` rename, emit `.deploy_manifest.json`, and retire `git diff --diff-filter=D` deleted files via `--deleted-files-list` while protecting `.env`, `storage/`, and `public/uploads/.htaccess` (closes #181).
- **Schema-Resilient & PII-Sanitized Pre-Prod Snapshot Cloning (`[Adversarial MED-11]`)**: Refactored `PreprodSandbox::cloneSnapshotRowsIntoSqlite()` / `cloneApprovedFromProductionMysql()` to inspect SQLite target columns dynamically via `PRAGMA table_info(...)`, wipe auth/vetting/outbox/takedown tables in the sandbox, and scrub `owner_email`, `admin_provenance_note`, and `submitted_by_email` during production snapshot cloning (closes #182).
- **CLI Schema/Pin Runner (`bin/migrate-and-seed.php`) & Monotonic `business_sequences` Allocation (`[Adversarial MED-12]`)**: Decoupled schema migrations, taxonomy seeding, and external map-pin HTTP backfills from web requests via `schema_init_version` fast-path guards in `Database.php` and dedicated CLI runner `bin/migrate-and-seed.php`, introduced atomic `business_sequences` allocation (`VettingPurger::allocateNextBusinessNumber()`), and removed global `renumberBusinesses()` rewrites on deletion so approved businesses retain permanent `business_number` identifiers (closes #183, closes #160).
- **Overnight Carryover Operating Hours & Strict 24h Schedule Validation (`[Adversarial MED-13]`)**: Updated `HoursEngine::evaluateStatus()` to evaluate previous-day overnight shifts (`close < open`, including Sunday -> Monday wraparound) in `Africa/Nairobi` (EAT) for both `OPEN_NOW` and `CLOSING_SOON`, and added `HoursEngine::validateScheduleJson()` rejecting malformed or out-of-range `HH:MM` intervals (closes #184).
- **In-Flight Directory Fetch Cancellation (`AbortController`) & Retryable Error Banner (`[Adversarial MED-14]`)**: Added `AbortController` cancellation, monotonic `latestRequestSeq` stale-response guards, and an accessible retryable error banner (`data-testid="directory-fetch-error"`, `data-testid="directory-fetch-retry"`) in `public/assets/js/directory.js` so transient API failures never masquerade as empty search results (closes #185).
- tag: `v0.15.0`

## [0.14.2] - 2026-11-12

### Security
- **Strict Session-Bound CSRF Token Generation & Verification (`[Adversarial LOW-01]`)**: Removed the `'stateless'` session-ID fallback from `AntiSpamGate::generateFormTokens()` and `AntiSpamGate::verifyCsrfToken()`, adding `AntiSpamGate::generateCsrfToken()` that invokes `OwnerAuth::startSecureSession()` and fails closed (`RuntimeException` on generation, `false` on verification) whenever `session_id()` is empty (closes #187, part of #161).

### Changed
- **Transaction-Decoupled Email Outbox, Retry Worker & DPA Retention Cron Heartbeat (`[Adversarial LOW-02]`)**: Deferred network SMTP / `mail()` delivery in `Mailer::enqueueAndSend()` whenever `$pdo->inTransaction()` is active, flushing queued `email_outbox` rows strictly post-commit via `Mailer::flushPendingOutbox()` (and discarding queued IDs on rollback via `Mailer::clearPendingOutbox()`); added bounded CLI retry worker `bin/process-email-outbox.php` (`Mailer::processRetryQueue()`) and admin `Retry Outbox Now` action; surfaced honest UI error/delay notices when immediate OTP email delivery fails (`Mailer::wasDeliveredOrSimulated()`); and added `VettingPurger::recordCronHeartbeat()` / `getRetentionCronHealth()` in `bin/purge-dpa-retention.php` and `public/admin/index.php` to surface stale (`> 36h`) or unconfigured cron warnings (closes #188, part of #160).
- **Synced `README.md`, `SKILL.md` (`busherian-biasharas` + `egm-skills`), & `CategoryMatcher` Docs (`[Adversarial LOW-03]`)**: Updated `README.md`, `SKILL.md`, and `CategoryMatcher::similarity()` comments to accurately document substring + normalized Levenshtein + `similar_text()` fuzzy deduplication, OpenStreetMap tile / OSRM / Nominatim usage alongside self-hosted CSS/JS/Leaflet, explicit `conda activate ds` test commands, and current release version (closes #189).

### Fixed
- **Allowed Tracked `public/uploads/.htaccess` in Full-Sync FTPS Deployments**: Permitted exact Apache hardening file `public/uploads/.htaccess` in `.github/scripts/ftps_deploy.py` (`validate_relative_deploy_path()`) while keeping all uploaded media under `public/uploads/*` blocked so `workflow_dispatch` full-tree syncs succeed cleanly (closes #194, part of #160).
- tag: `v0.14.2`

## [0.14.1] - 2026-11-12

### Fixed
- **Angryhosting Explicit FTPS Let's Encrypt Generation Y Chain & Shared-Host SAN Verification (`[Adversarial HIGH-07]` Follow-up)**: Supplied Let's Encrypt's subordinate Generation Y cross-signed intermediates (`.github/scripts/letsencrypt_geny_intermediates.pem` — `ISRG Root YE`/`YR` cross-signed by `ISRG Root X1`/`X2` + `YE1`/`YE2`/`YR1`/`YR2`, zero self-signed roots) in `build_verified_tls_context()` for FTPS servers like Angryhosting (`:21`) that omit intermediate stapling in `ServerHello`, added `--tls-servername` SAN alignment (`angryhosting.com` when `FTP_SERVER` is a vanity domain/IP on Angryhosting) across control and passive data channels, and unpacked `*ftplib.all_errors` in `ftps_deploy.py` `main()` error handling (closes #192, part of #160).
- tag: `v0.14.1`

## [0.14.0] - 2026-11-12

### Security
- **Disabled Corporate Email Domain Auto-Ownership & Revoked Unverified Legacy Links (`[Adversarial HIGH-01]`)**: Removed `corporate_domain_match` from `OwnerAuth::autoLinkMatchingBusinesses()`, `OwnerAuth::isRecognizedOwnerEmail()`, and `OwnerAuth::getMaskedOwnerEmailHintsForBusiness()` so any colleague, contractor, or recycled corporate mailbox at `@<domain>` can no longer automatically take over a company's directory listing; added `OwnerAuth::getCorporateDomainClaimSuggestions()` for informational claim guidance on `/owner/index.php` and one-time startup migration `OwnerAuth::revokeUnverifiedCorporateDomainLinks()` in `Database.php` preserving only exact `email_public` matches (closes #164, part of #158).
- **Server-Side Revocable Persistent Owner Sessions, Session ID Regeneration & "Sign Out All Devices" (`[Adversarial HIGH-02]`)**: Centralized hardened PHP session initialization in `OwnerAuth::startSecureSession()` (`use_strict_mode=1`, `use_only_cookies=1`, `httponly=true`, `samesite=Lax`, `HTTP_X_FORWARDED_PROTO=https`) across all owner, public form, and CSRF endpoints; enforced `session_regenerate_id(true)` across password login, OTP verification, claim redemption, magic links, and Google OAuth; replaced stateless HMAC cookies with server-backed `owner_sessions` (`v2.<selector>.<validator>` with `SHA-256` validator hashes) and `business_owners.auth_version`, revoking all other devices automatically on password change/reset and adding a CSRF-protected **Sign Out All Devices** action on `/owner/index.php` and `/owner/logout.php` (closes #165, part of #158).
- **Full IPv4/IPv6 Transition & Special-Purpose SSRF Normalization (`[Adversarial HIGH-03]`)**: Hardened `WebsiteEnricher`, `LocationEngine`, and `public/api/autofill-url.php` to canonicalize and unwrap IPv4-mapped (`::ffff:0:0/96`), IPv4-translated (`::ffff:0:0:0/96`), IPv4-compatible (`::/96`), NAT64 (`64:ff9b::/96`, `64:ff9b:1::/48`), 6to4 (`2002::/16`), and Teredo (`2001::/32`) IPv6 addresses, reject CGNAT (`100.64.0.0/10`), `192.0.0.0/24`, benchmarking (`198.18.0.0/15`), ULA (`fc00::/7`), link-local, loopback, multicast, IPv6 zone IDs (`%`), and non-canonical octal/hex/decimal numeric IPv4 literals, and bracket IPv6 addresses in `CURLOPT_RESOLVE` (closes #166).
- **Zero Alumni Vetting PII in Outbound Emails & `email_outbox` (`[Adversarial HIGH-04]`)**: Stripped `House`, `Year Left`, and `School Number` from `Mailer::notifyAdminNewSubmission()` so one-time alumni verification data is never sent over SMTP or persisted in `email_outbox.body_html`; linked `email_outbox.business_id` for erasure cascades on business deletion, added startup scrubber `Mailer::scrubLegacyVettingPiiFromOutbox()`, and aligned plain-English retention and encrypted hosting backup disclosures on `public/privacy.php` (closes #167, part of #159).
- **Authenticated / Email-Confirmed Reversible Listing Pause & Admin Takedown Review Queue (`[Adversarial HIGH-05]`)**: Prevented unauthenticated third-party `/takedown.php` submissions from hiding live businesses or wiping `business_vetting_queue` verification evidence; added `businesses.quarantine_prev_status` and `takedown_requests`, enabling immediate self-pause for signed-in verified owners, single-use confirmation links (`VettingPurger::confirmOwnerTakedownByToken()`) for designated owner emails, and a `pending_review` **Takedown & Privacy Review Queue** + **Restore Listing** action in `public/admin/index.php` while preserving pending vetting rows across retention sweeps (closes #168, part of #159).
- **Fail-Closed Production MySQL Handling & Zero Production Sample Seeding (`[Adversarial HIGH-06]`)**: Added `DatabaseUnavailableException` and HTTP `503` maintenance responses (`Retry-After: 60`, JSON on `/api/*`, branded HTML on web routes) in `src/bootstrap.php` and `src/Config/Database.php` when `APP_ENV=production` and MySQL is unreachable — never falling back to a local SQLite database or running `CategoryMatcher::seedSampleBusinesses()` in production — and set `DB_DRIVER_FALLBACK=none` in `.env.example` while preserving `PreprodSandbox` SQLite isolation (closes #169, part of #160).
- **Verified Explicit FTPS TLS Certificate & Hostname Enforcement in CI/CD (`[Adversarial HIGH-07]`)**: Replaced unverified inline `ftplib.FTP_TLS()` deployment scripts in `.github/workflows/deploy-ftp.yml` and `.github/workflows/deploy-preprod.yml` with `.github/scripts/ftps_deploy.py` (`VerifiedFTPTLS` using `ssl.create_default_context()`, `CERT_REQUIRED`, `check_hostname = True`, TLS 1.2+, explicit `AUTH TLS` prior to `login()`, and SNI-verified `prot_p()` passive data sockets, plus deploy path allowlisting) (closes #170, part of #160).

### Changed
- **Playwright Chromium Browser E2E Suite, Dynamic Ground-Truth Assertions & MySQL 8.0 CI Parity (`[Adversarial HIGH-08]`)**: Upgraded `e2e/` (`e2e/run.py`, `e2e/test_smoke.py`, `.github/workflows/e2e.yml`) to a shared-browser headless Playwright Chromium suite using `data-testid` locators and dynamic `/api/businesses.php` ground-truth counts (removing hardcoded seed counts/names), added browser & socket negative regression tests covering pre-consent network/cookie hygiene, image upload pixel decode + corrupt/oversized rejection, `CRIT-01`/`CRIT-02`/`HIGH-01`..`HIGH-07`, and added a `mysql:8.0` service-container job (`unit-mysql`) alongside `unit` in `.github/workflows/unit.yml` (closes #171).
- tag: `v0.14.0`

## [0.13.1] - 2026-11-11

### Security
- **Verified-Mailbox Owner Identity Boundary on Registration & Claim Setup (`[Adversarial CRIT-01]`)**: Eliminated unauthenticated `OwnerAuth::upsertOwner()`, `setOwnerPassword()`, `linkOwnerToBusiness()`, and `establishOwnerSession()` writes from `public/submit.php` and `public/owner/claim.php` so submitting or claiming with an existing owner's email can never overwrite `password_hash`, mutate profile metadata, or issue an unauthorized `bbiz_owner_session` cookie; separated public registration from password setup (issuing a 6-digit mailbox verification OTP only for brand-new emails) and required current-password authentication or 6-digit email OTP verification on `/owner/claim.php` inside an atomic database transaction with `OwnerAuth::getOwnerById()` and session ID regeneration on login (closes #162).
- **Eliminated Unsigned `claim` Request Parameter & Enforced Single-Use Signed Claim Invitations (`[Adversarial CRIT-02]`)**: Removed all unsigned `$_REQUEST['claim']` / `bbiz_pending_claim_biz_id` ownership-linking paths across `public/owner/login.php`, `public/owner/google-callback.php`, `public/owner/verify-link.php`, and `public/owner/claim.php`; replaced them with session-bound HMAC-verified claim tokens (`OwnerAuth::storeVerifiedClaimInSession()`, `OwnerAuth::redeemVerifiedClaimFromSession()`), recipient-bound admin claim URLs (`em` parameter signed into `claim_invite|...`), designated-owner eligibility checks (`OwnerAuth::isEmailEligibleForClaim()`), defense-in-depth authorization checks inside `OwnerAuth::verifyOtpAndSetPassword()`, and atomic single-use `used_nonces` consumption (`OwnerAuth::consumeClaimInviteOnce()`), backed by unit and live HTTP negative regression tests (closes #163).
- tag: `v0.13.1`

## [0.13.0] - 2026-11-11

### Added
- **Password-First Owner Sign-In & 6-Digit Email Verification Code (`OTP`)**: Standardized owner authentication around **Email + Password** (`🔑 Sign In with Email & Password`) on `/owner/login.php`, paired with a 6-digit numeric email verification code (`OwnerAuth::issuePasswordOtp()`, `OwnerAuth::verifyOtpAndSetPassword()`, `Mailer::sendOwnerPasswordOtp()`) for setting or resetting a password directly inside the owner's active Safari/Chrome browser tab — eliminating mobile email-app webview cookie isolation (#151, #152, #154; tag: `v0.13.0`).
- **Required Owner Password & Immediate Session Cookie on Submission & Claim**: Required every business submitter (`/submit.php`) and invitation claimant (`/owner/claim.php`) to choose an `8+` character Owner Password up front and immediately established their 30-day `bbiz_owner_session` cookie so `✏️ Edit My Business` appears on their directory card without friction (#151, #153).
- **Privacy-Masked Linked Email Hints & Prominent Sign-In Entry Points**: Added `OwnerAuth::maskEmailAddress()` and `OwnerAuth::getMaskedOwnerEmailHintsForBusiness()` (e.g. `j•••••••e@yahoo.com`) surfaced inside the Business Detail Modal callout (`👤 Own this business? Sign in to edit`) and on `/owner/login.php?biz=<id>`, alongside a clearer `✏️ Owner Sign In / Edit Listing` header button (#151, #152, #154).
- **1-Click Admin WhatsApp Owner Access Link & Approval Email Notification**: Added `💬 WhatsApp Link` / `💬 Send via WhatsApp` buttons in `/admin/index.php` and `/admin/edit-business.php` pre-addressed to the listing's WhatsApp/primary phone with a signed 30-day `/owner/claim.php` password-setup URL, plus authenticated SMTP / `-f` envelope alignment and `Mailer::notifyOwnerListingApproved()` on vetting approval (#151, #152, #155).

## [0.12.0] - 2026-11-11

### Added
- **Isolated Pre-Prod Preview & Sandbox Environment (`/admin/preprod`) (`#147`, `#148`, `#149`, `#150`)**:
  - Added `PreprodSandbox` (`src/Services/PreprodSandbox.php`) and subpath runtime hooks (`src/bootstrap.php`, `AdminAuth.php`, `OwnerAuth.php`, `PhotoUploader.php`) enabling the full application to run under `/admin/preprod` with isolated SQLite persistence (`preprod.sqlite`), isolated session/owner cookies, simulated outbound emails (`SIMULATE_EMAILS=yes`), `X-Robots-Tag: noindex`, and Admin credential gating with 1-click signed handoff from `/admin/index.php` and `X-Preprod-Access` header support for CLI E2E suites (closes `#148`).
  - Added `/admin/preprod/admin/sandbox.php` (`public/admin/sandbox.php`) and a sticky Pre-Prod Preview banner providing 1-click Dummy Listing Seeding (3 approved + 2 pending AHS/AGHS listings), Read-Only Live Prod Snapshot Cloning, Clean Seed DB Reset, Simulated Email Outbox inspection, and an 8-check In-Browser Self-Test Runner (closes `#149`).
  - Added `.github/workflows/deploy-preprod.yml` for automated FTPS deployment from the `preprod` branch to `/busherianbiasharas.com/www/preprod_app/` and `/busherianbiasharas.com/www/html/admin/preprod/`, and updated `e2e/test_smoke.py` for subpath & `PREPROD_ACCESS_TOKEN` execution (closes `#150`, closes `#147`).
  - tag: `v0.12.0`

## [0.11.0] - 2026-11-11

### Added
- **Admin Console & Main Public Directory Pagination (`10 / 25 / 50 / 100`, Default `10`) (`#142`, `#143`, `#144`)**:
  - Paginated **Manage Existing Business Records** on the Admin Console (`public/admin/index.php`) with a `Show: 10 / 25 / 50 / 100 per page` selector defaulting to `10`, live `Showing X–Y of Z business records` counter, and page navigation controls (`#admin-pagination`) preserving active search (`q`) and status (`status`) filters (closes `#143`).
  - Paginated the Main Public Directory (`public/index.php`, `public/assets/js/directory.js`) with an identical `Show: 10 / 25 / 50 / 100 per page` selector (`#per-page-select`) defaulting to `10`, automatic reset to page `1` on filter/search changes, `Showing X–Y of Z Verified Busherian Biasharas` header summary, and accessible `#directory-pagination` controls while keeping all filtered pins visible on the interactive map (closes `#144`, closes `#142`).
  - tag: `v0.11.0`

## [0.10.0] - 2026-11-11

### Added
- **Automatic Maps Link -> `latitude` / `longitude` Coordinate Inference & Existing Listing Pin Backfill (`#138`, `#139`, `#140`, `#141`)**:
  - Enhanced `LocationEngine::parseCoordinatesFromInput()` (`src/Services/LocationEngine.php`) to deterministically extract exact coordinates from Google Maps (`!3d!4d` entity pin prioritized over `@viewport`, `!2d!3d` iframe embeds, `?q=`/`?ll=`/`?query=`/`?destination=`, `/maps/search/<lat>,<lng>`, and DMS pins), Apple Maps, Waze, OpenStreetMap (`mlat`/`mlon` and `#map=`), and Open Location Codes (Plus Codes) (closes `#139`).
  - Added SSRF-safe short/place Maps URL unrolling (`LocationEngine::resolveCoordinatesFromUrlOrInput()` + `LocationEngine::parseCoordinatesFromGoogleEmbedHtml()`) with map-domain allowlisting and `CURLOPT_RESOLVE` IP pinning to resolve `maps.app.goo.gl`, `goo.gl/maps`, `g.co`, and Google Maps `?q=...&ftid=...` place links into exact `latitude`/`longitude` coordinates (closes `#139`).
  - Unified listing coordinate inference in `LocationEngine::resolveCoordinatesFromListingData()` across `public/submit.php`, `VettingPurger::createBusinessDirectly()`, `VettingPurger::updateBusiness()`, `VettingPurger::updateBusinessByOwner()`, and `WebsiteEnricher::extractFromHtml()`, plus live browser auto-fill in `public/assets/js/form-enhancements.js` via `/api/geocode.php` so the **🧭 Directions** button activates automatically even when `latitude`/`longitude` are left blank (closes `#140`).
  - Added `VettingPurger::backfillMissingCoordinatesFromPins()`, CLI script `bin/backfill-map-pins.php`, and idempotent startup migration (`coords_pin_backfill_v1` in `Database::ensureInitialized()`) to automatically populate missing `latitude`/`longitude` for existing listings with listed map pins (such as `#13 TAG Creative`) (closes `#141`, closes `#138`).
  - tag: `v0.10.0`

## [0.9.0] - 2026-11-11

### Added
- **Submitter Name, Email & Direct Phone on Pending Listings (`#136`)**:
  - Updated the Admin Vetting Queue (`public/admin/index.php`) and Admin Business Editor (`public/admin/edit-business.php`) so every pending listing prominently displays the **Name**, **Email Address** (clickable `mailto:` link), and **Direct Phone Number** (clickable `tel:` call link + 1-click `💬 WhatsApp` link) of the person requesting the review alongside their temporary alumni vetting details.

## [0.8.9] - 2026-11-10

### Changed
- **Alphabetical World Countries & Official Standard Administrative Units (`#132`, `#133`, `#134`)**:
  - Updated `LocationEngine::PLACE_HIERARCHY` so all 197 world countries are ordered strictly alphabetically (`Afghanistan` to `Zimbabwe`) while keeping `Kenya` -> `Nairobi` pre-selected by default on new forms.
  - Populated every country's Level-2 subdivision dropdown with its official standard administrative units in strict alphabetical order: all **47 Counties of Kenya** (`Baringo` to `West Pokot`), **50 US States + District of Columbia** (`Alabama` to `Wyoming`), **Tanzanian & Ugandan Districts**, and official Provinces, States, Counties, Districts, Regions, Governorates, Prefectures, Parishes, Emirates, and Cantons worldwide.
  - Added `LocationEngine::PLACE_UNIT_LABELS` and dynamic unit labels across `src/bootstrap.php`, `public/assets/js/form-enhancements.js`, `public/index.php`, and `public/assets/js/directory.js` so the Level-2 field label and filter dropdown dynamically reflect the selected country's administrative unit (`County`, `State`, `District`, `Province`, etc.).

## [0.8.8] - 2026-11-10

### Changed
- **Map School Colour Legend (`#130`):**
  - Updated the map toolbar legend under **Live Busherian Proximity & Pin Map** in `public/index.php` from `Green = Open Now • Crimson = Closed` to **`Green = AHS • Green & Maroon = AGHS`** with matching colour swatches.

## [0.8.7] - 2026-11-10

### Added
- **GitHub-Style 2-Step "Delete Listing" Confirmation Modal (`#126`, `#127`, `#128`):**
  - Added `bbiz_render_github_delete_modal()` in `src/bootstrap.php` and wired it across `/owner/edit-business.php`, `/owner/index.php`, `/admin/edit-business.php`, and `/admin/index.php`.
  - **Step 1:** Asks if the user is sure of what they are doing and requires clicking **Yes, I am sure — Proceed** (`#btn-delete-step1-yes`).
  - **Step 2:** Requires typing the exact **Business Name** (`#delete-confirm-name-input`) before unlocking the final Delete submit button (`#btn-delete-final-submit`), backed by strict server-side `confirm_business_name` verification.

## [0.8.6] - 2026-11-10

### Changed
- **Cookie Consent Button Wording (`#124`):**
  - Updated the cookie consent banner buttons and matching Privacy Notice labels from `Accept` / `Reject All` to **`Allow for Analysis`** and **`Allow only Essential`** across `public/assets/js/consent-metrics.js`, `public/privacy.php`, and `public/admin/metrics.php`.

## [0.8.5] - 2026-11-10

### Changed
- **School-Themed Map Markers for AHS & AGHS (`#122`):**
  - Styled **AHS** map markers in solid Busherian forest green (`#114B3E`, `.bbiz-pin-ahs`) and **AGHS** map markers with the green-and-maroon gradient blend matching the logo crest (`linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%)`, `.bbiz-pin-aghs`) in `public/assets/js/directory.js` and `public/assets/css/app.css`.

## [0.8.4] - 2026-11-10

### Added
- **Multi-Device Owner Password Sign-In & Rolling 30-Day Session Renewal (`#118`, `#119`, `#120`):**
  - Added `password_hash` to `business_owners` (`src/Config/Database.php` and `src/schema.sql`) and added `OwnerAuth::setOwnerPassword()` and `OwnerAuth::authenticateWithPassword()` (`src/Services/OwnerAuth.php`).
  - Added rolling 30-day session cookie auto-renewal in `OwnerAuth::getCurrentOwner()` so active owners stay signed in continuously without expiring after 30 days.
  - Updated `/owner/login.php` so returning owners on any computer or phone can sign in immediately with **Email + Password**, **Continue with Google**, or **Send 1-Click Sign-In Link (No Password Needed)**.
  - Updated `/owner/index.php` and `/owner/claim.php` so owners can set or update their password anytime.

## [0.8.3] - 2026-11-10

### Added
- **Arts, Visual & Performing Arts Industry Sector & Specialties (`#112`, `#113`, `#114`):**
  - Added **Arts, Visual & Performing Arts** (`arts-visual-performing-arts`) as a dedicated Industry Sector in `CategoryMatcher::DEFAULT_TAXONOMY` with 8 specialties: *Visual Arts, Fine Art & Galleries*, *Performing Arts, Theatre & Dance*, *Music, Live Bands, DJs & Sound Production*, *Film, Cinema, Animation & Directing*, *Sculpture, Ceramics, Pottery & Crafts*, *Illustration, Comics & Digital Art*, *Literary Arts, Poetry & Creative Writing*, and *Cultural Heritage, Curatorial & Museums*.
  - Added Swahili and English search synonyms (`sanaa`, `art`, `arts`, `artist`, `theatre`, `gallery`, `band`, `mziki`, `muziki`) in `CategoryMatcher::SYNONYM_LEXICON` and idempotent taxonomy synchronization (`CategoryMatcher::ensureTaxonomyUpToDate`) in `Database::ensureInitialized()`.
- **All 197 World Countries in Place Taxonomy (`#116`):**
  - Expanded `LocationEngine::PLACE_HIERARCHY` from 14 countries to **all 197 countries in the world** (Kenya first as default, followed alphabetically from Afghanistan to Zimbabwe), including all 47 Kenyan counties + major towns, all 50 US states + D.C., all Canadian provinces, UK cities, Australian states, and capital/major cities for every country worldwide.
- **"Same as Direct Phone above" Checkboxes on `/submit.php` (`#115`):**
  - Added **Same as Direct Phone above** checkboxes (`#same_as_direct_phone_primary` and `#same_as_direct_phone_whatsapp`) under **Public Business Phone** and **WhatsApp Business Number** on `/submit.php`, with live client-side auto-sync in `public/assets/js/form-enhancements.js` and server-side POST fallback in `public/submit.php`.

### Changed
- **Site-Wide Lay-Friendly Copy & Zero Displayed Code/Jargon (`#109`, `#110`, `#111`):**
  - Removed all displayed `<code>` blocks, raw SQL commands (`DELETE FROM business_vetting_queue`), internal filenames (`/submit.php`), database table/status/cookie identifiers (`business_vetting_queue`, `quarantined_dpa`, `bbiz_vid`), and developer/cryptographic/statutory jargon (`HMAC-SHA256`, `Schema.org JSON-LD, OpenGraph tags`, `EXIF GPS stripped`, `SSO`, `Level 1` / `Level 2`, `DPA s.29` / `s.33` / `s.40`) across `/submit.php`, `/privacy.php`, `/takedown.php`, `/index.php`, `/owner/*`, `src/bootstrap.php`, `directory.js`, and `consent-metrics.js`.

## [0.8.2] - 2026-04-19

### Added
- **Direct "✏️ Edit My Business" Button on Linked Directory Cards & Modals (`#105`, `#106`)**: Updated `public/index.php`, `public/api/businesses.php`, and `public/assets/js/directory.js` to evaluate `OwnerAuth::canOwnerEditBusiness()` (and `AdminAuth::isAuthenticated()`) for the current session, exposing `can_edit` and `edit_url` on business records, switching the homepage header CTA to `👤 My Biasharas` when signed in, and rendering a direct `✏️ Edit My Business` button and `✓ Your Linked Business` badge right on the owner's business card and modal (closes #106).
- **In-Modal "Own this business? Sign in to edit" Link & Owner Sign-In Guide (`#105`, `#107`)**: Added a contextual `👤 Own this business? Sign in to edit` link (`/owner/login.php?biz=<id>`) in the business modal footer that shows the target business name on `/owner/login.php` and redirects straight to `/owner/edit-business.php?id=<id>` after sign-in, plus a step-by-step **How Owner Sign-In & Business Linking Works** guide on `/owner/login.php` (closes #105, closes #107, tag: `v0.8.2`).

## [0.8.1] - 2026-04-19

### Added
- **AGHS Houses Update (`#99`)**: Confirmed and added `Joan Mjomba` and `Rebecca Karanja` in `Houses::AGHS_HOUSES` (`src/Config/Houses.php`) for one-time AGHS alumni vetting on `/submit.php` (closes #99, tag: `v0.8.1`).
- **Granular Parent/Child Ticketing Audit (`#47`–`#99`)**: Audited all historical and current GitHub tickets across `v0.1.0`–`v0.8.1`, splitting multi-part tickets into dedicated Parent & Child issues and separating unrelated items into distinct standalone tickets.

## [0.8.0] - 2026-04-19

### Added
- **2-Level Place Taxonomy & Cascading Form Selectors (`#96`, `#97`)**: Added `country` column (`DEFAULT 'Kenya'`) to `businesses` (`src/schema.sql`, `src/Config/Database.php` auto-migration), `LocationEngine::PLACE_HIERARCHY` / `getPlaceHierarchy()` / `resolvePlaceFromPost()` covering all 47 Kenyan Counties + key towns, East Africa, and global Busherian diaspora hubs, and cascading `Country` → `City / County / State` selectors (`bbiz_render_place_fields()`) across `/submit.php`, `/owner/edit-business.php`, `/admin/add-business.php`, and `/admin/edit-business.php` (closes #97).
- **Public Directory 2-Level Place Filter (`#96`, `#98`)**: Added cascading `#filter-country` (`🌍 All Countries`) and `#filter-city` (`📍 All Cities / Counties / States`) filters to the directory filter dock (`public/index.php`, `public/assets/js/directory.js`) and `country` + `city_county` query filtering in `/api/businesses.php` (closes #96, closes #98, tag: `v0.8.0`).

## [0.7.2] - 2026-04-19

### Added
- **Registration Form Automatic Draft Persistence (`#95`)**: Added real-time `localStorage` draft persistence (`bbiz_submit_draft_v1`) and server-side sticky POST fallback on `/submit.php` (`public/submit.php`, `public/assets/js/form-enhancements.js`) so alumni listing a new business never lose their progress if interrupted by a refresh, tab closure, phone call, or validation error. Includes a `"💾 Your in-progress listing was automatically restored!"` banner, a `"✕ Clear Draft & Start Fresh"` reset action, full restoration of cascading dropdowns and queued photos, and automatic draft purge on successful submission (closes #95, tag: `v0.7.2`).

## [0.7.1] - 2026-04-19

### Added
- **Outbound Website Referral Attribution (`#94`)**: Updated outbound business website and social links in `public/assets/js/directory.js` (`withOutboundAttribution()`) to append `utm_source=busherianbiasharas.com&utm_medium=referral&utm_campaign=alumni_directory` and use `rel="noopener"` (instead of `rel="noopener noreferrer"`) so destination analytics (GA4, Plausible, server logs) record both the `Referer: https://www.busherianbiasharas.com/` header and UTM source attribution (closes #94, tag: `v0.7.1`).

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
