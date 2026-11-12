# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

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
