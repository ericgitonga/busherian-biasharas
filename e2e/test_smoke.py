import base64
import hashlib
import hmac
import json
import os
import re
import sqlite3
import struct
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib
from pathlib import Path
from playwright.sync_api import Browser, BrowserContext, sync_playwright

BASE_URL = os.environ.get("BASE_URL", "http://127.0.0.1:8080").rstrip("/")
BASE_PATH = urllib.parse.urlparse(BASE_URL).path.rstrip("/")
PREPROD_ACCESS_TOKEN = os.environ.get("PREPROD_ACCESS_TOKEN", "").strip()
ADMIN_ACCESS_TOKEN = os.environ.get("ADMIN_ACCESS_TOKEN", "").strip()

_PW = None
_BROWSER: Browser | None = None

# Minimal 1x1 transparent PNG used to satisfy Leaflet OpenStreetMap tile requests deterministically offline in CI
_TINY_TILE_PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z/C/HgAGgwJ/lK3Q6wAAAABJRU5ErkJggg=="
)


def _make_solid_png_bytes(width: int = 80, height: int = 60, rgb: tuple[int, int, int] = (17, 75, 62)) -> bytes:
    """Build a valid uncompressed RGB PNG in pure stdlib (zero Pillow dependency) for browser upload tests."""
    def _chunk(tag: bytes, payload: bytes) -> bytes:
        return struct.pack(">I", len(payload)) + tag + payload + struct.pack(">I", zlib.crc32(tag + payload) & 0xFFFFFFFF)

    raw_rows = b"".join(b"\x00" + bytes(rgb) * width for _ in range(height))
    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + _chunk(b"IHDR", ihdr) + _chunk(b"IDAT", zlib.compress(raw_rows)) + _chunk(b"IEND", b"")


def _get_browser() -> Browser:
    global _PW, _BROWSER
    if _BROWSER is None:
        _PW = sync_playwright().start()
        _BROWSER = _PW.chromium.launch(headless=True)
    return _BROWSER


def _new_browser_context(**kwargs) -> BrowserContext:
    extra_headers = dict(kwargs.pop("extra_http_headers", {}) or {})
    if PREPROD_ACCESS_TOKEN:
        extra_headers["X-Preprod-Access"] = PREPROD_ACCESS_TOKEN
    ctx = _get_browser().new_context(extra_http_headers=extra_headers or None, **kwargs)
    ctx.route(
        "**/*.tile.openstreetmap.org/**",
        lambda route: route.fulfill(status=200, content_type="image/png", body=_TINY_TILE_PNG),
    )
    return ctx


def TEARDOWN():
    global _PW, _BROWSER
    if _BROWSER is not None:
        try:
            _BROWSER.close()
        finally:
            _BROWSER = None
    if _PW is not None:
        try:
            _PW.stop()
        finally:
            _PW = None


def _request(path: str, method: str = "GET", data: bytes | None = None, headers: dict | None = None):
    url = f"{BASE_URL}{path}"
    req_headers = {"User-Agent": "BusherianE2E/1.0"}
    if PREPROD_ACCESS_TOKEN:
        req_headers["X-Preprod-Access"] = PREPROD_ACCESS_TOKEN
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(url, data=data, method=method, headers=req_headers)
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace"), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", errors="replace"), dict(e.headers)


def _fetch_api_payload(query: str = "") -> dict:
    suffix = f"?{query.lstrip('?')}" if query else ""
    status, body, _ = _request(f"/api/businesses.php{suffix}")
    assert status == 200, f"Expected 200 from /api/businesses.php{suffix}, got {status}: {body}"
    payload = json.loads(body)
    assert payload["status"] == "ok"
    return payload


def _fetch_api_businesses(query: str = "") -> list[dict]:
    clean_q = query.lstrip("?")
    params = dict(urllib.parse.parse_qsl(clean_q, keep_blank_values=True))
    if "page" in params or "per_page" in params:
        return _fetch_api_payload(clean_q)["businesses"]

    combined: list[dict] = []
    page_num = 1
    while True:
        paged_params = dict(params)
        paged_params["page"] = str(page_num)
        paged_params["per_page"] = "100"
        payload = _fetch_api_payload(urllib.parse.urlencode(paged_params))
        combined.extend(payload["businesses"])
        pagination = payload.get("pagination") or {}
        total_pages = int(pagination.get("total_pages") or 1)
        if page_num >= total_pages:
            break
        page_num += 1
    return combined


def test_health_endpoint():
    status, body, _ = _request("/api/health.php")
    assert status == 200, f"Expected 200 from /api/health.php, got {status}: {body}"
    payload = json.loads(body)
    assert payload["status"] == "ok"
    assert payload["categories_count"] >= 114
    live_businesses = _fetch_api_businesses()
    assert payload["approved_businesses_count"] >= 1
    assert payload["approved_businesses_count"] == len(live_businesses)


def test_playwright_pre_consent_network_and_cookie_gate():
    """Verify in real Chromium that zero tracking beacons or external CDNs fire before consent (#171)."""
    forbidden_hosts = (
        "fonts.googleapis.com",
        "fonts.gstatic.com",
        "cdn.jsdelivr.net",
        "unpkg.com",
        "cdnjs.cloudflare.com",
        "googletagmanager.com",
        "google-analytics.com",
    )
    ctx = _new_browser_context()
    try:
        page = ctx.new_page()
        seen_urls: list[str] = []
        page.on("request", lambda req: seen_urls.append(req.url))

        resp = page.goto(f"{BASE_URL}/", wait_until="networkidle")
        assert resp is not None and resp.status == 200

        # Cookie consent banner must be visible on initial unconsented visit
        banner = page.get_by_test_id("cookie-banner")
        banner.wait_for(state="visible", timeout=5000)

        assert not any("/api/track-metric.php" in u for u in seen_urls), (
            f"Pre-consent DPA violation: track-metric fired before consent: {seen_urls}"
        )
        assert not any("tile.openstreetmap.org" in u for u in seen_urls), (
            f"MED-07 (#178) violation: OpenStreetMap tiles requested prior to consent: {seen_urls}"
        )
        page.get_by_test_id("map-consent-placeholder").wait_for(state="visible", timeout=5000)
        for bad_host in forbidden_hosts:
            assert not any(bad_host in u for u in seen_urls), f"Forbidden external request to {bad_host}: {seen_urls}"

        cookie_names_initial = {c["name"] for c in ctx.cookies()}
        assert "bbiz_vid" not in cookie_names_initial

        # Clicking "Allow only Essential" must dismiss banner and STILL fire zero analytics or OSM tile requests
        page.get_by_test_id("cookie-reject-btn").click()
        banner.wait_for(state="hidden", timeout=5000)
        page.wait_for_timeout(250)
        assert not any("/api/track-metric.php" in u for u in seen_urls)
        assert not any("tile.openstreetmap.org" in u for u in seen_urls)
        cookies_after_reject = {c["name"]: c["value"] for c in ctx.cookies()}
        assert cookies_after_reject.get("bbiz_consent") == "rejected"
        assert "bbiz_vid" not in cookies_after_reject

        # Clicking "#btn-enable-osm-map" explicitly opts into OSM map tiles without turning on analytics (#178)
        page.locator("#btn-enable-osm-map").click()
        page.locator(".bbiz-leaflet-pin").first.wait_for(state="attached", timeout=8000)
        assert any("tile.openstreetmap.org" in u for u in seen_urls), "Expected OSM tile fetch after explicit map opt-in (#178)"
        assert not any("/api/track-metric.php" in u for u in seen_urls)

        # Reset cookie preference in a fresh context and click "Allow for Analysis" -> beacon fires & bbiz_vid is set
        ctx_accept = _new_browser_context()
        try:
            page_acc = ctx_accept.new_page()
            page_acc.goto(f"{BASE_URL}/", wait_until="networkidle")
            with page_acc.expect_response(lambda r: "/api/track-metric.php" in r.url and r.status == 200, timeout=5000):
                page_acc.get_by_test_id("cookie-accept-btn").click()
            cookies_after_accept = {c["name"]: c["value"] for c in ctx_accept.cookies()}
            assert cookies_after_accept.get("bbiz_consent") == "accepted"
            assert "bbiz_vid" in cookies_after_accept and len(cookies_after_accept["bbiz_vid"]) >= 8
        finally:
            ctx_accept.close()
    finally:
        ctx.close()


def test_playwright_directory_filters_pagination_and_modal():
    """Exercise directory cards, school pills, Swahili search, pagination, and detail modal dynamically (#171)."""
    all_biz = _fetch_api_businesses()
    total_count = len(all_biz)
    assert total_count >= 1

    # Verify unique positive monotonic business_number (#183) and unique BBIZ-* IDs dynamically (zero hardcoded counts)
    nums = [int(b["business_number"]) for b in all_biz]
    uids = {b["business_id"] for b in all_biz}
    assert len(set(nums)) == total_count and all(n >= 1 for n in nums), f"Expected unique positive business_number values, got {nums}"
    assert len(uids) == total_count and all(u.startswith("BBIZ-") for u in uids)

    ahs_biz = _fetch_api_businesses("school=AHS")
    aghs_biz = _fetch_api_businesses("school=AGHS")
    assert len(ahs_biz) + len(aghs_biz) == total_count

    mjengo_biz = _fetch_api_businesses("q=mjengo")
    wakili_biz = _fetch_api_businesses("q=wakili")
    assert len(mjengo_biz) >= 1
    assert all("house_name" not in b and "year_left" not in b and "school_number" not in b for b in all_biz)

    ctx = _new_browser_context()
    try:
        page = ctx.new_page()
        page.goto(f"{BASE_URL}/", wait_until="networkidle")
        # Dismiss cookie banner so footer/pagination buttons are unobstructed
        if page.get_by_test_id("cookie-reject-btn").is_visible():
            page.get_by_test_id("cookie-reject-btn").click()

        cards = page.get_by_test_id("biz-card")
        cards.first.wait_for(state="visible", timeout=8000)

        # Default per-page is 10
        expected_page1 = min(10, total_count)
        assert cards.count() == expected_page1, f"Expected {expected_page1} cards on page 1, got {cards.count()}"

        if total_count > 10:
            # Navigate to Page 2 and verify remaining slice
            page.locator("#directory-pagination button[data-goto-page='2']").first.click()
            expected_page2 = min(10, total_count - 10)
            page.wait_for_function(
                f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_page2}",
                timeout=5000,
            )
            assert cards.count() == expected_page2

        # Switch to 25 per page so up to 25 dynamic cards render on a single view
        page.get_by_test_id("per-page-select").select_option("25")
        expected_25 = min(25, total_count)
        page.wait_for_function(
            f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_25}",
            timeout=5000,
        )

        # Filter by AGHS pill dynamically
        page.get_by_test_id("quick-filter-AGHS").click()
        expected_aghs_cards = min(25, len(aghs_biz))
        page.wait_for_function(
            f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_aghs_cards}",
            timeout=5000,
        )
        rendered_aghs_titles = sorted(t.strip() for t in page.get_by_test_id("biz-card-title").all_inner_texts())
        expected_aghs_titles = sorted(b["name"] for b in aghs_biz[:25])
        assert rendered_aghs_titles == expected_aghs_titles

        # Filter by AHS pill dynamically
        page.get_by_test_id("quick-filter-AHS").click()
        expected_ahs_cards = min(25, len(ahs_biz))
        page.wait_for_function(
            f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_ahs_cards}",
            timeout=5000,
        )

        # Reset to All Schools & run Swahili synonym search ("wakili")
        page.get_by_test_id("quick-filter-all").click()
        search_box = page.get_by_test_id("search-input")
        search_box.fill("wakili")
        expected_wakili_cards = min(25, len(wakili_biz))
        page.wait_for_function(
            f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_wakili_cards}",
            timeout=5000,
        )
        assert cards.count() == expected_wakili_cards

        # Clear search, click first card -> verify detail modal opens with business title and closes cleanly
        search_box.fill("")
        page.wait_for_function(
            f"() => document.querySelectorAll('[data-testid=\"biz-card\"]').length === {expected_25}",
            timeout=5000,
        )
        first_card_title = page.get_by_test_id("biz-card-title").first.inner_text().strip()
        page.get_by_test_id("biz-card-title").first.click()
        modal_backdrop = page.get_by_test_id("biz-modal-backdrop")
        modal_backdrop.wait_for(state="visible", timeout=5000)
        assert first_card_title in page.get_by_test_id("biz-modal-content").inner_text()
        page.locator("#btn-close-modal").click()
        modal_backdrop.wait_for(state="hidden", timeout=5000)
    finally:
        ctx.close()


def test_directory_homepage_and_zero_vetting_exposure():
    status, html, _ = _request("/")
    assert status == 200
    assert "Busherian Biasharas" in html
    assert f'href="{BASE_PATH}/favicon.svg"' in html
    assert f'href="{BASE_PATH}/apple-touch-icon.png"' in html
    assert "/assets/img/og-preview.png" in html
    assert "/assets/img/bb-icon-512.png" in html
    assert "application/ld+json" in html

    st_svg, svg_body, _ = _request("/favicon.svg")
    assert st_svg == 200 and "#114B3E" in svg_body and "#7B1113" in svg_body
    st_og, _, _ = _request("/assets/img/og-preview.png")
    assert st_og == 200
    st_css, css_body, _ = _request("/assets/css/app.css")
    assert st_css == 200 and "border: none;" in css_body

    for cdn in ["fonts.googleapis.com", "cdn.jsdelivr.net", "unpkg.com", "cdnjs.cloudflare.com"]:
        assert cdn not in html, f"Found forbidden CDN {cdn} on homepage"
    for house in ["Livingstone", "Aggrey", "Githaiga House", "Dorcas Luseno", "Joan Waithaka"]:
        assert f"House: {house}" not in html

    # Spatial Haversine search near Westlands (-1.2647, 36.8021) & Kenyan route/geocode APIs
    status2, body2, _ = _request("/api/businesses.php?lat=-1.2647&lng=36.8021&radius_km=5")
    assert status2 == 200
    data2 = json.loads(body2)
    assert data2["count"] >= 1 and data2["businesses"][0]["distance_km"] <= 5.0

    st_rt, body_rt, _ = _request(
        "/api/route-directions.php?from_lat=-1.286389&from_lng=36.817223&to_lat=-1.278800&to_lng=36.771200"
    )
    assert st_rt == 200
    rt = json.loads(body_rt)["route"]
    assert rt["straight_km"] > 4.0 and rt["road_km"] > 4.0 and len(rt["steps"]) >= 1
    steps_joined = " ".join(s.get("instruction", "") for s in rt["steps"]).lower()
    for compass_word in ("northwest", "northeast", "southwest", "southeast", "head north", "head south", "head east", "head west"):
        assert compass_word not in steps_joined

    st_geo, geo_body, _ = _request("/api/geocode.php?q=Marist+Lane,+Karen")
    assert st_geo == 200
    geo_payload = json.loads(geo_body)
    assert abs(geo_payload["location"]["latitude"] - (-1.34447)) < 0.02

    gmaps_place_url = (
        "https://www.google.com/maps/place/Circular+Economy+Hub/@-2.7093033,36.9857735,8z/"
        "data=!4m7!3m6!1s0x184073cc97d95e6f:0xd08f849ff152e4a2!8m2!3d-1.3766863!4d36.9280114"
    )
    st_gpin, body_gpin, _ = _request("/api/geocode.php?q=" + urllib.parse.quote(gmaps_place_url, safe=""))
    assert st_gpin == 200
    gpin_loc = json.loads(body_gpin)["location"]
    assert abs(gpin_loc["latitude"] - (-1.376686)) < 0.0001


def test_website_autofill_api_and_ssrf_protection():
    status, submit_html, headers = _request("/submit.php")
    assert status == 200
    assert "Extract &amp; Auto-Fill from Website" in submit_html
    assert "School Number" in submit_html
    assert "Admission Number" not in submit_html
    assert 'id="regulated-fields-wrap" hidden' in submit_html
    assert 'name="regulator_license"' not in submit_html
    assert "<code>" not in submit_html and "DELETE FROM" not in submit_html

    m = re.search(r'name="csrf_token"[^>]+value="([^"]+)"', submit_html)
    assert m, "Could not find csrf_token in /submit.php"
    csrf_token = m.group(1)

    cookie = headers.get("Set-Cookie") or headers.get("set-cookie") or ""
    req_headers = {"Content-Type": "application/json", "X-CSRF-Token": csrf_token}
    if cookie:
        req_headers["Cookie"] = cookie.split(";")[0]

    # 1. Valid website URL auto-fill extraction
    st_ok, body_ok, _ = _request(
        "/api/autofill-url.php",
        method="POST",
        data=json.dumps({
            "url": "https://www.wakilipeak.busherian-demo.co.ke",
            "csrf_token": csrf_token,
        }).encode("utf-8"),
        headers=req_headers,
    )
    assert st_ok == 200, f"Expected 200 from autofill-url.php, got {st_ok}: {body_ok}"
    extracted = json.loads(body_ok)["data"]
    assert extracted["name"] == "Wakili Peak Advocates LLP"
    assert extracted["phone_primary"] == "+254722998877"
    assert "Advocates" in extracted["suggested_category"]["specialty_name"]

    # 2. SSRF protection blocks IPv4, IPv4-mapped IPv6, NAT64, CGNAT, 192.0.0.0/24, 198.18.0.0/15 & numeric IP literals (#166)
    # (Stay within autofill-url.php's 10 req / 15m per-IP rate limit: 1 valid + 8 SSRF = 9 total)
    ssrf_vectors = [
        "http://127.0.0.1/admin",
        "http://[::ffff:127.0.0.1]/admin",
        "http://[::ffff:169.254.169.254]/latest/meta-data/",
        "http://[64:ff9b::7f00:1]/internal",
        "http://[fc00::1]/internal",
        "http://100.64.0.1/router",
        "http://192.0.0.1/iana",
        "http://2130706433/decimal-loopback",
    ]
    for bad_url in ssrf_vectors:
        st_ssrf, body_ssrf, _ = _request(
            "/api/autofill-url.php",
            method="POST",
            data=json.dumps({"url": bad_url, "csrf_token": csrf_token}).encode("utf-8"),
            headers=req_headers,
        )
        assert st_ssrf == 400, f"Expected 400 SSRF block for {bad_url}, got {st_ssrf}: {body_ssrf}"


def test_privacy_and_dpa_takedown_pages():
    st_priv, html_priv, _ = _request("/privacy.php")
    assert st_priv == 200
    assert "Kenya Data Protection Act, 2019" in html_priv
    assert "OpenStreetMap" in html_priv and "Nominatim" in html_priv and "OSRM" in html_priv
    assert "DELETE FROM" not in html_priv
    assert "business_vetting_queue" not in html_priv
    assert "<code>" not in html_priv

    st_td, html_td, _ = _request("/takedown.php")
    assert st_td == 200
    assert "Request a Listing Removal or Correction" in html_td
    assert "<code>" not in html_td
    assert "quarantined_dpa" not in html_td
    assert 'name="form_sig"' in html_td

    for protected_admin_path in ("/admin/index.php", "/admin/add-business.php", "/admin/metrics.php"):
        st_adm, html_adm, _ = _request(protected_admin_path)
        assert st_adm == 401, f"Expected 401 on unauthenticated {protected_admin_path}, got {st_adm}"
        assert "Temporary Vetting Credentials" not in html_adm


def test_playwright_submission_photo_upload_admin_approval_and_outbox_privacy():
    """Browser E2E: submit listing + valid/corrupt photos -> verify outbox PII absence (#167) -> Admin approve -> delete (#171)."""
    if os.environ.get("SIMULATE_EMAILS", "yes").lower() != "yes":
        return

    before_biz = _fetch_api_businesses()
    before_count = len(before_biz)

    # Pick an unregulated specialty dynamically
    _, cat_body, _ = _request("/api/categories.php")
    sectors = json.loads(cat_body)["sectors"]
    chosen_sec_id, chosen_spec_id = "", ""
    for sec in sectors:
        for sp in sec.get("specialties", []):
            if not sp.get("is_regulated"):
                chosen_sec_id = str(sec["id"])
                chosen_spec_id = str(sp["id"])
                break
        if chosen_spec_id:
            break
    assert chosen_sec_id and chosen_spec_id

    ts_tag = str(int(time.time() * 1000))[-6:]
    unique_biz_name = f"E2E Chromium Roasters {ts_tag}"
    submitter_email = f"e2e.roasters.{ts_tag}@busherian-test.co.ke"
    valid_png = _make_solid_png_bytes(96, 72, (17, 75, 62))

    ctx_sub = _new_browser_context()
    try:
        page = ctx_sub.new_page()
        loaded_at = time.monotonic()
        page.goto(f"{BASE_URL}/submit.php", wait_until="networkidle")
        if page.get_by_test_id("cookie-reject-btn").is_visible():
            page.get_by_test_id("cookie-reject-btn").click()

        # Step 1: One-time alumni vetting
        page.locator("#school_code").select_option("AHS")
        page.locator("#house_name").select_option("Livingstone")
        page.locator("#year_left").fill("2009")
        page.locator("#school_number").fill("SNO-9481-K")
        page.locator("#submitter_name").fill("Eng. E2E Alumnus")
        page.locator("#submitter_email").fill(submitter_email)
        page.locator("#submitter_phone").fill("+254722881199")

        # Step 2: Business identity + category
        page.locator("#biz_name").fill(unique_biz_name)
        page.locator("#biz_tagline").fill("Specialty Kenyan AA coffee roasters tested in headless Chromium")
        page.locator("#biz_description").fill("Roasted daily in Nairobi with zero external CDN dependencies.")
        page.locator("#sector_id").select_option(chosen_sec_id)
        page.locator("#specialty_id").select_option(chosen_spec_id)

        # Upload 1 valid PNG + 1 corrupt non-image payload via file input (#171 image decode + corrupt negative check)
        page.locator("input[name='business_photos[]']").set_input_files([
            {"name": "roastery_front.png", "mimeType": "image/png", "buffer": valid_png},
            {"name": "corrupt_payload.jpg", "mimeType": "image/jpeg", "buffer": b"CORRUPT_NOT_AN_IMAGE_12345"},
        ])

        # Step 3 & 4: Contacts + DPA consents
        page.locator("#phone_primary").fill("+254722881199")
        page.locator("input[name='age_attestation_18']").check()
        page.locator("input[name='dpa_consent_directory']").check()

        # Respect AntiSpamGate Layer 2 (>= 4s human completion window)
        elapsed = time.monotonic() - loaded_at
        if elapsed < 4.3:
            time.sleep(4.3 - elapsed)

        page.locator("#business-submit-form button[type='submit']").click()
        page.get_by_test_id("submit-success-banner").wait_for(state="visible", timeout=10000)

        # Pending submission must NOT appear on public API prior to Admin vetting
        assert len(_fetch_api_businesses()) == before_count

        # HIGH-04 (#167): If local SQLite test DB path is configured, verify email_outbox has zero vetting PII
        sqlite_env = os.environ.get("DB_SQLITE_PATH", "").strip()
        if sqlite_env:
            db_file = Path(sqlite_env) if os.path.isabs(sqlite_env) else (Path(__file__).resolve().parent.parent / sqlite_env)
            if db_file.is_file():
                with sqlite3.connect(str(db_file)) as conn:
                    rows = conn.execute(
                        "SELECT business_id, subject, body_html FROM email_outbox WHERE subject LIKE ?",
                        (f"%{unique_biz_name}%",),
                    ).fetchall()
                assert len(rows) >= 1, "Expected admin notification in email_outbox for new submission"
                for bid, subj, body_html in rows:
                    assert bid is not None and int(bid) > 0
                    for forbidden_pii in ("Livingstone", "SNO-9481-K", "House:", "Year Left", "School Number"):
                        assert forbidden_pii not in subj and forbidden_pii not in body_html, (
                            f"HIGH-04 regression: leaked '{forbidden_pii}' into email_outbox!"
                        )

        # If ADMIN_ACCESS_TOKEN is available, approve in real Chromium Admin Console and verify image + purge!
        if ADMIN_ACCESS_TOKEN:
            ctx_admin = _new_browser_context()
            try:
                adm = ctx_admin.new_page()
                adm.goto(f"{BASE_URL}/admin/login.php", wait_until="networkidle")
                adm.locator("#admin_token").fill(ADMIN_ACCESS_TOKEN)
                adm.locator("form button[type='submit']").click()
                adm.wait_for_url("**/admin/index.php**", timeout=8000)

                assert adm.get_by_test_id("admin-leaked-vetting-count").inner_text().strip().startswith("0")
                pending_queue = adm.get_by_test_id("admin-pending-queue")
                assert unique_biz_name in pending_queue.inner_text()

                # Approve the pending card matching unique_biz_name
                card = adm.locator("[data-testid='admin-pending-card']", has_text=unique_biz_name)
                card.get_by_test_id("admin-approve-btn").click()
                adm.wait_for_load_state("networkidle")

                after_approve = _fetch_api_businesses()
                assert len(after_approve) == before_count + 1
                approved_entry = next((b for b in after_approve if b["name"] == unique_biz_name), None)
                assert approved_entry is not None
                # Valid image decoded & saved; corrupt image discarded -> exactly 1 photo
                assert len(approved_entry["photos"]) == 1, f"Expected 1 sanitized photo, got {approved_entry['photos']}"
                photo_url = approved_entry["photos"][0]
                st_img, _, img_hdrs = _request(photo_url)
                assert st_img == 200
                ctype = (img_hdrs.get("Content-Type") or img_hdrs.get("content-type") or "").lower()
                assert ctype.startswith("image/"), f"Expected image/* Content-Type for {photo_url}, got {ctype}"

                # Clean up the approved E2E test business via Admin exact-name delete modal so DB count stays clean
                adm.goto(f"{BASE_URL}/admin/edit-business.php?id={approved_entry['id']}", wait_until="networkidle")
                adm.locator("button[data-open-delete-modal='1']").click()
                adm.locator("#btn-delete-step1-yes").click()
                adm.locator("#delete-confirm-name-input").fill(unique_biz_name)
                adm.locator("#btn-delete-final-submit").click()
                adm.wait_for_load_state("networkidle")
                assert len(_fetch_api_businesses()) == before_count
                st_del_img, _, _ = _request(photo_url)
                assert st_del_img == 404, f"Expected 404 for deleted business photo {photo_url} (#177), got {st_del_img}"
            finally:
                ctx_admin.close()
    finally:
        ctx_sub.close()


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _http_raw(path: str, method: str = "GET", form: dict | None = None, cookie_jar: dict | None = None):
    url = f"{BASE_URL}{path}"
    headers = {"User-Agent": "BusherianSecurityE2E/1.0"}
    if PREPROD_ACCESS_TOKEN:
        headers["X-Preprod-Access"] = PREPROD_ACCESS_TOKEN
    if cookie_jar:
        headers["Cookie"] = "; ".join(f"{k}={v}" for k, v in cookie_jar.items())
    body_bytes = None
    if form is not None:
        body_bytes = urllib.parse.urlencode(form).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(url, data=body_bytes, method=method, headers=headers)
    opener = urllib.request.build_opener(_NoRedirect())
    try:
        resp = opener.open(req, timeout=10)
        status = resp.status
        raw_body = resp.read().decode("utf-8", errors="replace")
        hdrs = resp.headers
    except urllib.error.HTTPError as e:
        status = e.code
        raw_body = e.read().decode("utf-8", errors="replace")
        hdrs = e.headers

    set_cookies = hdrs.get_all("Set-Cookie") or [] if hasattr(hdrs, "get_all") else []
    if cookie_jar is not None:
        for sc in set_cookies:
            first_pair = sc.split(";", 1)[0].strip()
            if "=" in first_pair:
                ck, cv = first_pair.split("=", 1)
                if cv == "" or "Max-Age=0" in sc:
                    cookie_jar.pop(ck.strip(), None)
                else:
                    cookie_jar[ck.strip()] = cv.strip()
    return status, raw_body, hdrs, set_cookies


def _extract_hidden(html: str, name: str) -> str:
    m = re.search(rf'name="{re.escape(name)}"[^>]*value="([^"]*)"', html)
    if not m:
        m = re.search(rf'value="([^"]*)"[^>]*name="{re.escape(name)}"', html)
    assert m, f"Missing hidden field {name}"
    return m.group(1)


def test_adversarial_crit_01_and_crit_02_regressions():
    if os.environ.get("SIMULATE_EMAILS", "yes").lower() != "yes":
        return

    all_biz = _fetch_api_businesses()
    biz_with_email = [b for b in all_biz if (b.get("email_public") or "").strip()]
    assert len(biz_with_email) >= 2, "Expected at least 2 businesses with email_public in test DB"
    victim_biz = biz_with_email[0]
    victim_biz_id = int(victim_biz["id"])
    victim_public_only_email = victim_biz["email_public"].strip().lower()
    victim_email = f"designated.founder.{victim_biz_id}@busherian-e2e.co.ke"
    attacker_seed_biz = next(
        b for b in biz_with_email if int(b["id"]) != victim_biz_id and b["email_public"].strip().lower() != victim_public_only_email
    )
    other_biz = next(
        b for b in all_biz
        if int(b["id"]) not in (victim_biz_id, int(attacker_seed_biz["id"]))
    )
    other_biz_id = int(other_biz["id"])

    # Designate victim_email as explicit owner_email on victim_biz via Admin so Victim is a legitimate designated owner (#207)
    assert ADMIN_ACCESS_TOKEN, "ADMIN_ACCESS_TOKEN required for E2E owner_email setup"
    adm_jar: dict[str, str] = {}
    _, adm_l_html, _, _ = _http_raw("/admin/login.php", cookie_jar=adm_jar)
    adm_l_csrf = _extract_hidden(adm_l_html, "csrf_token")
    st_al, _, _, _ = _http_raw(
        "/admin/login.php",
        method="POST",
        form={"csrf_token": adm_l_csrf, "admin_token": ADMIN_ACCESS_TOKEN},
        cookie_jar=adm_jar,
    )
    assert st_al == 302
    _, adm_eb_html, _, _ = _http_raw(f"/admin/edit-business.php?id={victim_biz_id}", cookie_jar=adm_jar)
    adm_eb_csrf = _extract_hidden(adm_eb_html, "csrf_token")
    st_aeb, _, _, _ = _http_raw(
        f"/admin/edit-business.php?id={victim_biz_id}",
        method="POST",
        form={
            "csrf_token": adm_eb_csrf,
            "post_action": "save",
            "name": victim_biz["name"],
            "school_badge": victim_biz.get("school_badge") or "AHS",
            "status": "approved",
            "service_mode": victim_biz.get("service_mode") or "physical",
            "tagline": victim_biz.get("tagline") or "Verified Alumni Business",
            "description": victim_biz.get("description") or "Verified Alumni Business description.",
            "sector_id": str(victim_biz["sector_id"]),
            "specialty_id": str(victim_biz["specialty_id"]),
            "country": victim_biz.get("country") or "Kenya",
            "city_county": victim_biz.get("city_county") or "Nairobi",
            "phone_primary": victim_biz.get("phone_primary") or "+254700000001",
            "email_public": victim_public_only_email,
            "owner_email": victim_email,
        },
        cookie_jar=adm_jar,
    )
    assert st_aeb == 200

    # Wait briefly or use a distinct X-Forwarded-For? Wait: /owner/login.php uses REMOTE_ADDR (127.0.0.1), which allows 2 owner_otp_send per 15m!
    # Above used 1st slot (rejected before mail send, after AntiSpamGate), and below uses 2nd slot -> within 2/15m!
    # 1. Establish a real password-protected Victim Owner via verified 6-digit OTP (gated by 7-layer AntiSpamGate #173)
    #    Also pass forged ?claim=<attacker_seed_biz_id> during OTP verification to prove CRIT-02 blocks cross-business linking!
    attacker_seed_biz_id = int(attacker_seed_biz["id"])
    victim_cookies: dict[str, str] = {}
    victim_pw = "VictimSafe#Pass2026"

    st, login_html, _, _ = _http_raw(f"/owner/login.php?mode=reset&claim={attacker_seed_biz_id}", cookie_jar=victim_cookies)
    assert st == 200
    pre_login_phpsessid = victim_cookies.get("PHPSESSID", "")
    csrf_v = _extract_hidden(login_html, "csrf_token")
    login_ts = _extract_hidden(login_html, "form_ts")
    login_nonce = _extract_hidden(login_html, "form_nonce")
    login_sig = _extract_hidden(login_html, "form_sig")
    time.sleep(4.2)
    st_otp, otp_html, _, _ = _http_raw(
        f"/owner/login.php?claim={attacker_seed_biz_id}",
        method="POST",
        form={
            "csrf_token": csrf_v,
            "form_ts": login_ts,
            "form_nonce": login_nonce,
            "form_sig": login_sig,
            "js_interaction_token": "human_verified_1700000000173",
            "website": "",
            "form_step": "send_otp",
            "email": victim_email,
            "claim": str(attacker_seed_biz_id),
            "biz": "0",
        },
        cookie_jar=victim_cookies,
    )
    assert st_otp == 200
    otp_match = re.search(r"Preview Verification Code:.*?<strong[^>]*>(\d{6})</strong>", otp_html, re.S)
    assert otp_match, f"Expected simulated 6-digit OTP preview in testing mode, got HTML snippet: {otp_html[:500]}"
    otp_code = otp_match.group(1)
    csrf_v2 = _extract_hidden(otp_html, "csrf_token")

    st_ver, _, ver_hdrs, _ = _http_raw(
        f"/owner/login.php?claim={attacker_seed_biz_id}",
        method="POST",
        form={
            "csrf_token": csrf_v2,
            "form_step": "verify_otp",
            "email": victim_email,
            "otp_code": otp_code,
            "new_password": victim_pw,
            "confirm_password": victim_pw,
            "claim": str(attacker_seed_biz_id),
            "biz": "0",
        },
        cookie_jar=victim_cookies,
    )
    assert st_ver == 302, f"Expected 302 after verified OTP setup, got {st_ver}"
    assert f"id={attacker_seed_biz_id}" not in (ver_hdrs.get("Location") or "")
    owner_cookie_val = next((v for k, v in victim_cookies.items() if "owner_session" in k), "")
    assert owner_cookie_val.startswith("v2."), f"Expected v2 server-backed owner cookie (#165), got {owner_cookie_val}"
    if pre_login_phpsessid:
        assert victim_cookies.get("PHPSESSID") != pre_login_phpsessid, "Expected session_regenerate_id on login (#165)"

    # 2. CRIT-01 Negative Test: Unauthenticated attacker submits /submit.php with Victim's email + attacker password
    attacker_sub_cookies: dict[str, str] = {}
    st_sf, sub_html, _, _ = _http_raw("/submit.php", cookie_jar=attacker_sub_cookies)
    assert st_sf == 200
    sub_csrf = _extract_hidden(sub_html, "csrf_token")
    sub_ts = _extract_hidden(sub_html, "form_ts")
    sub_nonce = _extract_hidden(sub_html, "form_nonce")
    sub_sig = _extract_hidden(sub_html, "form_sig")

    _, cat_body, _ = _request("/api/categories.php")
    sectors = json.loads(cat_body)["sectors"]
    chosen_sec_id, chosen_spec_id = "1", "2"
    for sec in sectors:
        for sp in sec.get("specialties", []):
            if not sp.get("is_regulated"):
                chosen_sec_id = str(sec["id"])
                chosen_spec_id = str(sp["id"])
                break
        if chosen_spec_id != "2":
            break

    time.sleep(4.2)
    st_attack_sub, attack_sub_body, _, attack_sub_set_cookies = _http_raw(
        "/submit.php",
        method="POST",
        form={
            "csrf_token": sub_csrf,
            "form_ts": sub_ts,
            "form_nonce": sub_nonce,
            "form_sig": sub_sig,
            "js_interaction_token": "human_verified_1700000000000",
            "website": "",
            "school_code": "AHS",
            "house_name": "Livingstone",
            "year_left": "2008",
            "school_number": "",
            "submitter_name": "Impersonator Name",
            "submitter_email": victim_email,
            "submitter_phone": "+254711999888",
            "owner_password": "HackedPassword#999",
            "owner_password_confirm": "HackedPassword#999",
            "name": "Impersonation Attempt Bistro",
            "service_mode": "physical",
            "tagline": "Testing unauthenticated owner impersonation block",
            "description": "Negative security regression submission ensuring victim account and session stay untouched.",
            "sector_id": chosen_sec_id,
            "specialty_id": chosen_spec_id,
            "country": "Kenya",
            "city_county": "Nairobi",
            "phone_primary": "+254711999888",
            "age_attestation_18": "1",
            "dpa_consent_directory": "1",
        },
        cookie_jar=attacker_sub_cookies,
    )
    assert st_attack_sub == 200 and "Submission Received" in attack_sub_body
    assert not any("owner_session" in k for k in attacker_sub_cookies), (
        f"CRIT-01 regression: unauthenticated submit.php issued owner session cookie: {attack_sub_set_cookies}"
    )
    st_idx_chk, _, idx_hdrs, _ = _http_raw("/owner/index.php", cookie_jar=attacker_sub_cookies)
    assert st_idx_chk == 302 and "/owner/login.php" in (idx_hdrs.get("Location") or "")

    # Victim's password MUST NOT have been overwritten
    pw_probe_cookies: dict[str, str] = {}
    _, lp_html, _, _ = _http_raw("/owner/login.php", cookie_jar=pw_probe_cookies)
    lp_csrf = _extract_hidden(lp_html, "csrf_token")
    st_bad_pw, bad_pw_html, _, _ = _http_raw(
        "/owner/login.php",
        method="POST",
        form={
            "csrf_token": lp_csrf,
            "form_step": "password_login",
            "email": victim_email,
            "password": "HackedPassword#999",
            "biz": "0",
        },
        cookie_jar=pw_probe_cookies,
    )
    assert st_bad_pw == 200 and "Incorrect email or password" in bad_pw_html

    lp_csrf2 = _extract_hidden(bad_pw_html, "csrf_token")
    st_good_pw, _, _, _ = _http_raw(
        "/owner/login.php",
        method="POST",
        form={
            "csrf_token": lp_csrf2,
            "form_step": "password_login",
            "email": victim_email,
            "password": victim_pw,
            "biz": "0",
        },
        cookie_jar=pw_probe_cookies,
    )
    assert st_good_pw == 302 and any("owner_session" in k for k in pw_probe_cookies)

    # 3. CRIT-02 Negative Test: Logging into a valid owner account via password_login with ?claim=<other_biz_id>
    #    MUST NOT grant edit access to <attacker_seed_biz_id> or <other_biz_id>!
    forged_claim_cookies: dict[str, str] = {}
    _, al_html, _, _ = _http_raw(f"/owner/login.php?claim={attacker_seed_biz_id}", cookie_jar=forged_claim_cookies)
    al_csrf = _extract_hidden(al_html, "csrf_token")
    st_aver, _, aver_hdrs, _ = _http_raw(
        f"/owner/login.php?claim={attacker_seed_biz_id}",
        method="POST",
        form={
            "csrf_token": al_csrf,
            "form_step": "password_login",
            "email": victim_email,
            "password": victim_pw,
            "claim": str(attacker_seed_biz_id),
            "biz": "0",
        },
        cookie_jar=forged_claim_cookies,
    )
    assert st_aver == 302 and f"id={attacker_seed_biz_id}" not in (aver_hdrs.get("Location") or "")

    st_edit_v, _, edit_v_hdrs, _ = _http_raw(
        f"/owner/edit-business.php?id={attacker_seed_biz_id}",
        cookie_jar=forged_claim_cookies,
    )
    assert st_edit_v == 302 and "/owner/index.php" in (edit_v_hdrs.get("Location") or "")

    # 4. Signed claim invite single-use replay check when APP_HMAC_SECRET is set
    hmac_secret = os.environ.get("APP_HMAC_SECRET", "").strip()
    if hmac_secret:
        exp = int(time.time()) + 1800
        sig = hmac.new(
            hmac_secret.encode("utf-8"),
            f"claim_invite|{other_biz_id}|{exp}".encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        claim_path = f"/owner/claim.php?biz_id={other_biz_id}&exp={exp}&sig={sig}"

        legit_cookies: dict[str, str] = {}
        legit_claim_email = f"verified.claimant.{int(time.time())}@gmail.com"
        _, lcl_html, _, _ = _http_raw(claim_path, cookie_jar=legit_cookies)
        lcl_csrf = _extract_hidden(lcl_html, "csrf_token")
        lcl_ts = _extract_hidden(lcl_html, "form_ts")
        lcl_nonce = _extract_hidden(lcl_html, "form_nonce")
        lcl_sig = _extract_hidden(lcl_html, "form_sig")
        time.sleep(4.2)
        st_lotp, lotp_html, _, _ = _http_raw(
            "/owner/claim.php",
            method="POST",
            form={
                "csrf_token": lcl_csrf,
                "form_ts": lcl_ts,
                "form_nonce": lcl_nonce,
                "form_sig": lcl_sig,
                "js_interaction_token": "human_verified_1700000000001",
                "website": "",
                "claim_step": "send_otp",
                "biz_id": str(other_biz_id),
                "exp": str(exp),
                "sig": sig,
                "owner_email": legit_claim_email,
            },
            cookie_jar=legit_cookies,
        )
        assert st_lotp == 200 and "Set Your Owner Password" in lotp_html
        lotp_code = re.search(r"Preview Verification Code:.*?<strong[^>]*>(\d{6})</strong>", lotp_html, re.S).group(1)
        lcl_csrf2 = _extract_hidden(lotp_html, "csrf_token")
        st_lver, _, lver_hdrs, _ = _http_raw(
            "/owner/claim.php",
            method="POST",
            form={
                "csrf_token": lcl_csrf2,
                "claim_step": "verify_otp",
                "biz_id": str(other_biz_id),
                "exp": str(exp),
                "sig": sig,
                "owner_email": legit_claim_email,
                "otp_code": lotp_code,
                "full_name": "Verified Claimant",
                "phone": "+254700112233",
                "new_password": "ClaimantSafe#Pass2026",
                "confirm_password": "ClaimantSafe#Pass2026",
            },
            cookie_jar=legit_cookies,
        )
        assert st_lver == 302 and f"/owner/edit-business.php?id={other_biz_id}&claimed=1" in (lver_hdrs.get("Location") or "")

        replay_cookies: dict[str, str] = {}
        st_rep, _, rep_hdrs, _ = _http_raw(claim_path, cookie_jar=replay_cookies)
        assert st_rep == 302 and "err=invalid_link" in (rep_hdrs.get("Location") or "")


def test_playwright_high_01_02_05_browser_security_regressions():
    """Playwright Chromium negative regressions for HIGH-01 (#164), HIGH-02 (#165), and HIGH-05 (#168)."""
    if os.environ.get("SIMULATE_EMAILS", "yes").lower() != "yes":
        return

    all_biz = _fetch_api_businesses()
    # Pick a listing with a corporate website domain dynamically for HIGH-01 (#164)
    corp_biz = None
    corp_domain = ""
    for b in all_biz:
        w = (b.get("website_url") or "").strip()
        em_pub = (b.get("email_public") or "").strip().lower()
        if w and em_pub and int(b["id"]) != int(all_biz[0]["id"]):
            host = (urllib.parse.urlparse(w).hostname or "").lower().removeprefix("www.")
            if host and "." in host and host not in ("gmail.com", "yahoo.com", "instagram.com", "facebook.com"):
                corp_biz = b
                corp_domain = host
                break
    assert corp_biz is not None and corp_domain != ""
    corp_biz_id = int(corp_biz["id"])
    # R2-HIGH-03 (#207) + HIGH-01 (#164): Test the exact email_public on corp_biz to prove neither corporate domain NOR email_public grants ownership!
    corp_staff_email = (corp_biz.get("email_public") or f"unverified.staff.{int(time.time()) % 100000}@{corp_domain}").strip().lower()
    corp_pw = "CorpStaff#Pass164"

    # 1. HIGH-01 (#164): Verify corporate-domain email is NOT recognized on /owner/login.php and never auto-linkscorp_biz,
    #    plus HIGH-02 (#165): Multi-device revocation ("Sign Out All Devices")
    ctx_dev1 = _new_browser_context()
    ctx_dev2 = _new_browser_context()
    try:
        p1 = ctx_dev1.new_page()
        # Gate A: Unrecognized corporate-domain email cannot even request an owner OTP without a pending/claimed listing
        t_gate_a = time.monotonic()
        p1.goto(f"{BASE_URL}/owner/login.php?mode=reset", wait_until="networkidle")
        p1.locator("input[name='email']").first.fill(corp_staff_email)
        rem_a = 4.3 - (time.monotonic() - t_gate_a)
        if rem_a > 0:
            time.sleep(rem_a)
        p1.locator("form button[type='submit']").first.click()
        p1.wait_for_load_state("networkidle")
        assert "could not find a business listing matching that exact email address" in p1.content()

        # Gate B: Create an account legitimately via a signed claim invite on a DIFFERENT listing, or verify multi-device session
        # Using an existing business with email_public to verify Device 1 + Device 2 Sign Out All Devices (#165)
        # AND verify that signing up with corp_staff_email via a signed invite on another listing still does NOT link corp_biz_id!
        non_corp_biz = next(b for b in reversed(all_biz) if int(b["id"]) != corp_biz_id)
        non_corp_id = int(non_corp_biz["id"])
        hmac_secret = os.environ.get("APP_HMAC_SECRET", "").strip()
        assert hmac_secret, "APP_HMAC_SECRET required for signed invite E2E check"
        exp = int(time.time()) + 1800
        sig = hmac.new(
            hmac_secret.encode("utf-8"),
            f"claim_invite|{non_corp_id}|{exp}|{corp_staff_email}".encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        invite_url = (
            f"{BASE_URL}/owner/claim.php?biz_id={non_corp_id}&exp={exp}"
            f"&em={urllib.parse.quote(corp_staff_email)}&sig={sig}"
        )

        t_claim = time.monotonic()
        p1.goto(invite_url, wait_until="networkidle")
        if p1.get_by_test_id("cookie-reject-btn").is_visible():
            p1.get_by_test_id("cookie-reject-btn").click()
        owner_email_inp = p1.locator("input[name='owner_email']").first
        if owner_email_inp.is_editable():
            owner_email_inp.fill(corp_staff_email)
        else:
            assert owner_email_inp.input_value().strip().lower() == corp_staff_email
        wait_rem = 4.3 - (time.monotonic() - t_claim)
        if wait_rem > 0:
            time.sleep(wait_rem)
        p1.locator("form button[type='submit']").first.click()
        p1.wait_for_load_state("networkidle")

        m_otp = re.search(r"Preview Verification Code:.*?<strong[^>]*>(\d{6})</strong>", p1.content(), re.S)
        assert m_otp, "Expected simulated OTP code on /owner/claim.php"
        p1.locator("input[name='otp_code']").fill(m_otp.group(1))
        p1.locator("input[name='full_name']").fill("Corp Staff Member")
        p1.locator("input[name='phone']").fill("+254700164164")
        p1.locator("input[name='new_password']").fill(corp_pw)
        p1.locator("input[name='confirm_password']").fill(corp_pw)
        p1.locator("form button[type='submit']").first.click()
        p1.wait_for_load_state("networkidle")

        # Navigate to /owner/index.php: corp_biz MUST NOT be linked despite matching @<corp_domain>!
        p1.goto(f"{BASE_URL}/owner/index.php", wait_until="networkidle")
        p1.get_by_test_id("owner-domain-claim-suggestions").wait_for(state="visible", timeout=5000)
        assert corp_domain in p1.get_by_test_id("owner-domain-claim-suggestions").inner_text()

        p1.goto(f"{BASE_URL}/owner/edit-business.php?id={corp_biz_id}", wait_until="networkidle")
        assert "/owner/index.php" in p1.url and "edit-business.php" not in p1.url

        # R2-HIGH-02 (#206): Stored XSS check on /owner/edit-business.php inline <script> CURRENT_REGULATOR + /submit.php fuzzy suggestion box
        p1.goto(f"{BASE_URL}/owner/edit-business.php?id={non_corp_id}", wait_until="networkidle")
        # Pick a regulated specialty if available or submit custom regulator payload and reload
        xss_reg_payload = "</script><script>window.__bbizXssReg=1</script>"
        p1.evaluate(
            """(payload) => {
                const sel = document.getElementById('regulator_name');
                const cust = document.getElementById('regulator_name_custom');
                if (sel && cust) {
                    sel.disabled = false;
                    let opt = Array.from(sel.options).find(o => o.value === 'Other Statutory Regulator');
                    if (!opt) {
                        opt = document.createElement('option');
                        opt.value = 'Other Statutory Regulator';
                        sel.appendChild(opt);
                    }
                    sel.value = 'Other Statutory Regulator';
                    cust.disabled = false;
                    cust.value = payload;
                }
            }""",
            xss_reg_payload,
        )
        p1.locator("form button[type='submit']").first.click()
        p1.wait_for_load_state("networkidle")
        p1.goto(f"{BASE_URL}/owner/edit-business.php?id={non_corp_id}", wait_until="networkidle")
        assert p1.evaluate("() => window.__bbizXssReg") is None, "R2-HIGH-02 (#206) XSS executed on /owner/edit-business.php!"

        # Also test /submit.php fuzzy category suggestion box with malicious category name via route mock (#206)
        xss_cat_payload = '"><img src=x onerror="window.__bbizXssCat=1">'
        p1.goto(f"{BASE_URL}/submit.php", wait_until="networkidle")
        p1.route(
            "**/api/categories.php?check_custom=*",
            lambda route: route.fulfill(
                status=200,
                content_type="application/json",
                body=json.dumps({
                    "status": "ok",
                    "fuzzy_matches": [{"id": 2, "parent_id": 1, "name": xss_cat_payload, "similarity": 0.91}],
                }),
            ),
        )
        p1.evaluate("() => { const w = document.getElementById('custom-specialty-wrap'); if (w) w.style.display = 'block'; }")
        p1.locator("#custom_specialty_name").fill("Malicious Specialty Probe")
        p1.wait_for_function(
            "() => (document.getElementById('fuzzy-suggestion-box')?.textContent || '').includes('Similar existing specialty found')",
            timeout=5000,
        )
        assert p1.evaluate("() => window.__bbizXssCat") is None, "R2-HIGH-02 (#206) XSS executed in submit.php fuzzyBox!"
        assert p1.locator("#fuzzy-suggestion-box img").count() == 0
        p1.unroute("**/api/categories.php?check_custom=*")
        p1.goto(f"{BASE_URL}/owner/index.php", wait_until="networkidle")

        # HIGH-02 (#165) assertions: Sign into Device 2 with the same owner account, then click "Sign Out All Devices" on Device 1
        p2 = ctx_dev2.new_page()
        p2.goto(f"{BASE_URL}/owner/login.php", wait_until="networkidle")
        p2.locator("#owner-email").fill(corp_staff_email)
        p2.locator("#owner-password").fill(corp_pw)
        p2.locator("form button[type='submit']").first.click()
        p2.wait_for_url("**/owner/index.php**", timeout=8000)
        p2.get_by_test_id("owner-logout-all-btn").wait_for(state="visible", timeout=5000)

        # R2-LOW-03 (#203): GET /owner/logout.php MUST NOT sign out Device 2 (renders confirmation button while session stays active)
        p2.goto(f"{BASE_URL}/owner/logout.php", wait_until="networkidle")
        p2.get_by_test_id("owner-confirm-logout-btn").wait_for(state="visible", timeout=5000)
        p2.goto(f"{BASE_URL}/owner/index.php", wait_until="networkidle")
        p2.get_by_test_id("owner-signed-in-email").wait_for(state="visible", timeout=5000)

        # Click Sign Out All Devices on Device 1 -> Device 2 must immediately be invalidated on reload!
        p1.get_by_test_id("owner-logout-all-btn").click()
        p1.wait_for_url("**/owner/login.php**", timeout=8000)

        p2.goto(f"{BASE_URL}/owner/index.php", wait_until="networkidle")
        assert "/owner/login.php" in p2.url, f"HIGH-02 regression: Device 2 stayed signed in after Sign Out All Devices! URL={p2.url}"
    finally:
        ctx_dev1.close()
        ctx_dev2.close()

    # 2. HIGH-05 (#168): Unauthenticated third-party takedown request MUST NOT hide live listing!
    target_td_biz = all_biz[0]
    target_td_id = int(target_td_biz["id"])
    ctx_3p = _new_browser_context()
    try:
        p3 = ctx_3p.new_page()
        t0 = time.monotonic()
        p3.goto(f"{BASE_URL}/takedown.php", wait_until="networkidle")
        if p3.get_by_test_id("cookie-reject-btn").is_visible():
            p3.get_by_test_id("cookie-reject-btn").click()

        p3.locator("#business_id").select_option(str(target_td_id))
        p3.locator("#requester_name").fill("Unverified Third Party")
        p3.locator("#requester_email").fill("thirdparty.unverified@example.org")
        p3.locator("#reason").fill("Testing HIGH-05 unauthenticated takedown protection in Playwright.")

        elapsed = time.monotonic() - t0
        if elapsed < 4.3:
            time.sleep(4.3 - elapsed)

        p3.get_by_test_id("takedown-submit").click()
        p3.get_by_test_id("takedown-success").wait_for(state="visible", timeout=8000)
        assert "Queued for Priority Admin Review" in p3.get_by_test_id("takedown-success").inner_text()

        # Verify target listing is STILL live in /api/businesses.php!
        live_ids_after_3p = {int(b["id"]) for b in _fetch_api_businesses()}
        assert target_td_id in live_ids_after_3p, "HIGH-05 regression: unauthenticated takedown hid live business!"

        # Dismiss the queued takedown request from Admin Console
        if ADMIN_ACCESS_TOKEN:
            ctx_adm = _new_browser_context()
            try:
                pa = ctx_adm.new_page()
                pa.goto(f"{BASE_URL}/admin/login.php", wait_until="networkidle")
                pa.locator("#admin_token").fill(ADMIN_ACCESS_TOKEN)
                pa.locator("form button[type='submit']").click()
                pa.wait_for_url("**/admin/index.php**", timeout=8000)

                td_queue = pa.get_by_test_id("admin-takedown-queue")
                td_queue.wait_for(state="visible", timeout=5000)
                assert "thirdparty.unverified@example.org" in td_queue.inner_text()
                td_queue.get_by_test_id("admin-takedown-dismiss-btn").first.click()
                pa.wait_for_load_state("networkidle")
            finally:
                ctx_adm.close()
    finally:
        ctx_3p.close()


def test_playwright_med_04_08_09_12_14_15_regressions():
    """Browser & API E2E regressions for MED-04 (#175), MED-08 (#179), MED-09 (#180), MED-12 (#183), MED-14 (#185), and MED-15 (#186)."""
    # 1. MED-09 (#180): Server-side pagination metadata + omission of owner_email_hints for anonymous callers
    all_biz = _fetch_api_businesses()
    total_count = len(all_biz)
    paged = _fetch_api_payload("page=1&per_page=5")
    assert "pagination" in paged
    assert paged["pagination"]["page"] == 1
    assert paged["pagination"]["per_page"] == 5
    assert paged["pagination"]["total"] == total_count
    assert len(paged["businesses"]) == min(5, total_count)
    for b in paged["businesses"]:
        assert "owner_email_hints" not in b, f"Anonymous /api/businesses.php leaked owner_email_hints (#180): {b.get('id')}"

    _, home_html, _ = _request("/")
    assert "window.INITIAL_PAGINATION" in home_html
    assert '"owner_email_hints"' not in home_html

    # 2. MED-14 (#185) & MED-15 (#186): Directory fetch error + retry recovery AND XSS scheme rejection in Chromium modal
    ctx = _new_browser_context()
    try:
        page = ctx.new_page()
        page.goto(f"{BASE_URL}/", wait_until="networkidle")
        if page.get_by_test_id("cookie-reject-btn").is_visible():
            page.get_by_test_id("cookie-reject-btn").click()

        fail_next = {"active": True}

        def _intercept_api(route):
            if fail_next["active"]:
                fail_next["active"] = False
                route.fulfill(status=503, content_type="application/json", body='{"status":"error","message":"Simulated outage"}')
            else:
                route.continue_()

        page.route("**/api/businesses.php*", _intercept_api)
        page.get_by_test_id("search-input").fill("wakili")
        err_banner = page.get_by_test_id("directory-fetch-error")
        err_banner.wait_for(state="visible", timeout=6000)

        # Click Retry -> fetch succeeds and error banner hides (#185)
        page.get_by_test_id("directory-fetch-retry").click()
        err_banner.wait_for(state="hidden", timeout=6000)
        page.unroute("**/api/businesses.php*", _intercept_api)

        # Inject a mocked API listing with malicious javascript:/data: URLs and verify modal strips them (#186)
        sample_item = dict(all_biz[0])
        sample_item["id"] = 999186
        sample_item["name"] = "MED-15 Safe Scheme Check Co"
        sample_item["website_url"] = "javascript:alert('xss_website')"
        sample_item["google_maps_url"] = "javascript:alert('xss_maps')"
        sample_item["social_instagram"] = "data:text/html,<script>alert(1)</script>"

        page.route(
            "**/api/businesses.php*",
            lambda route: route.fulfill(
                status=200,
                content_type="application/json",
                body=json.dumps({
                    "status": "ok",
                    "count": 1,
                    "pagination": {"page": 1, "per_page": 10, "total": 1, "total_pages": 1, "has_more": False},
                    "businesses": [sample_item],
                }),
            ),
        )
        page.get_by_test_id("search-input").fill("MED-15 Safe Scheme")
        page.wait_for_function(
            "() => document.body.innerText.includes('MED-15 Safe Scheme Check Co')",
            timeout=6000,
        )
        page.get_by_test_id("biz-card-title").first.click()
        modal = page.get_by_test_id("biz-modal-backdrop")
        modal.wait_for(state="visible", timeout=5000)
        assert page.locator("a[href^='javascript:'], a[href^='data:'], a[href^='vbscript:']").count() == 0, (
            "MED-15 (#186) regression: unsafe URL scheme rendered inside directory DOM!"
        )
    finally:
        ctx.close()

    # 3. MED-04 (#175), MED-08 (#179) & MED-12 (#183): POST-only signed review-action + Admin curation lawful-basis + stable business_number
    hmac_secret = os.environ.get("APP_HMAC_SECRET", "").strip()
    if ADMIN_ACCESS_TOKEN and hmac_secret:
        ctx_adm = _new_browser_context()
        try:
            adm = ctx_adm.new_page()
            adm.goto(f"{BASE_URL}/admin/login.php", wait_until="networkidle")
            adm.locator("#admin_token").fill(ADMIN_ACCESS_TOKEN)
            adm.locator("form button[type='submit']").click()
            adm.wait_for_url("**/admin/index.php**", timeout=8000)

            # MED-08 (#179) & MED-12 (#183): Curate a listing via /admin/add-business.php with required DPA provenance basis,
            # then delete it and verify existing businesses retain their exact business_number!
            nums_before_map = {int(b["id"]): int(b["business_number"]) for b in _fetch_api_businesses()}
            _, cat_body, _ = _request("/api/categories.php")
            sectors = json.loads(cat_body)["sectors"]
            sec_id = str(sectors[0]["id"])
            spec_id = str(sectors[0]["specialties"][0]["id"])

            adm.goto(f"{BASE_URL}/admin/add-business.php", wait_until="networkidle")
            curated_name = f"E2E Curated Provenance {int(time.time()) % 100000}"
            adm.locator("input[name='name']").fill(curated_name)
            adm.locator("input[name='tagline']").fill("Curated E2E listing with explicit founder authorization")
            adm.locator("textarea[name='description']").fill("Verifying MED-08 lawful basis and MED-12 monotonic business_number.")
            adm.locator("#sector_id").select_option(sec_id)
            adm.locator("#specialty_id").select_option(spec_id)
            adm.locator("input[name='phone_primary']").fill("+254722179179")
            adm.locator("#admin_provenance_basis").select_option("alumni_direct_authorization")
            adm.locator("#admin_provenance_note").fill("Confirmed directly with alumni founder during E2E test")
            adm.locator("form button[type='submit']").click()
            adm.wait_for_url("**/admin/index.php**", timeout=8000)

            after_curate = _fetch_api_businesses()
            curated_row = next((b for b in after_curate if b["name"] == curated_name), None)
            assert curated_row is not None

            adm.goto(f"{BASE_URL}/admin/edit-business.php?id={curated_row['id']}", wait_until="networkidle")
            adm.locator("button[data-open-delete-modal='1']").click()
            adm.locator("#btn-delete-step1-yes").click()
            adm.locator("#delete-confirm-name-input").fill(curated_name)
            adm.locator("#btn-delete-final-submit").click()
            adm.wait_for_load_state("networkidle")

            nums_after_map = {int(b["id"]): int(b["business_number"]) for b in _fetch_api_businesses()}
            assert nums_after_map == nums_before_map, "MED-12 (#183) regression: deleting a listing mutated existing business_number values!"

            # MED-04 (#175): Test POST-only signed /admin/review-action.php against any pending submission (e.g. Impersonation Attempt Bistro)
            adm.goto(f"{BASE_URL}/admin/index.php", wait_until="networkidle")
            pend_cards = adm.locator("[data-testid='admin-pending-card']")
            if pend_cards.count() >= 1:
                id_inp = pend_cards.first.locator("input[name='business_id']").first
                pend_id = int(id_inp.input_value())
                exp = int(time.time()) + 1800
                jti = f"e2ejti{int(time.time() * 1000)}abcdef123456"
                sig = hmac.new(
                    hmac_secret.encode("utf-8"),
                    f"{pend_id}|reject|{exp}|0|{jti}".encode("utf-8"),
                    hashlib.sha256,
                ).hexdigest()
                signed_review_url = (
                    f"{BASE_URL}/admin/review-action.php?id={pend_id}&action=reject&exp={exp}&merge=0&jti={jti}&sig={sig}"
                )

                # GET MUST NOT mutate state — renders confirmation button while business stays pending!
                resp_get = adm.goto(signed_review_url, wait_until="networkidle")
                assert resp_get is not None and resp_get.status == 200
                adm.get_by_test_id("confirm-review-action-btn").wait_for(state="visible", timeout=5000)

                # Clicking confirm submits POST + CSRF + single-use jti -> completes rejection
                adm.get_by_test_id("confirm-review-action-btn").click()
                adm.get_by_test_id("review-action-completed").wait_for(state="visible", timeout=6000)

                # Re-visiting the exact same single-use signed URL MUST return 409 nonce_replayed (#175)
                resp_replay = adm.goto(signed_review_url, wait_until="networkidle")
                assert resp_replay is not None and resp_replay.status == 409
                adm.get_by_test_id("review-action-error").wait_for(state="visible", timeout=5000)

                # R2-LOW-01 (#201): Anonymous /owner/login.php?biz=<rejected_id> MUST NOT leak non-approved listing name or hints!
                ctx_anon_hint = _new_browser_context()
                try:
                    anon_p = ctx_anon_hint.new_page()
                    anon_p.goto(f"{BASE_URL}/owner/login.php?biz={pend_id}", wait_until="networkidle")
                    anon_body = anon_p.content()
                    assert "Linked owner email on file:" not in anon_body
                    assert "Sign In to Edit Business Listing" not in anon_body
                finally:
                    ctx_anon_hint.close()

            # R2-LOW-03 (#203): GET /admin/login.php?logout=1 MUST NOT terminate Admin session; POST Sign Out button MUST!
            adm.goto(f"{BASE_URL}/admin/login.php?logout=1", wait_until="networkidle")
            adm.get_by_test_id("admin-confirm-logout-btn").wait_for(state="visible", timeout=5000)
            adm.goto(f"{BASE_URL}/admin/index.php", wait_until="networkidle")
            adm.get_by_test_id("admin-logout-btn").wait_for(state="visible", timeout=5000)
            adm.get_by_test_id("admin-logout-btn").click()
            adm.wait_for_url("**/admin/login.php?logged_out=1**", timeout=8000)
            adm.get_by_test_id("admin-logout-notice").wait_for(state="visible", timeout=5000)
        finally:
            ctx_adm.close()


TESTS = [
    test_health_endpoint,
    test_playwright_pre_consent_network_and_cookie_gate,
    test_playwright_directory_filters_pagination_and_modal,
    test_directory_homepage_and_zero_vetting_exposure,
    test_website_autofill_api_and_ssrf_protection,
    test_privacy_and_dpa_takedown_pages,
    test_playwright_submission_photo_upload_admin_approval_and_outbox_privacy,
    test_adversarial_crit_01_and_crit_02_regressions,
    test_playwright_high_01_02_05_browser_security_regressions,
    test_playwright_med_04_08_09_12_14_15_regressions,
]
