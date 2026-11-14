# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

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
