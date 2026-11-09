# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

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
