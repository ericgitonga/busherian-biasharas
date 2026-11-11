import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("BASE_URL", "http://127.0.0.1:8080").rstrip("/")
BASE_PATH = urllib.parse.urlparse(BASE_URL).path.rstrip("/")
PREPROD_ACCESS_TOKEN = os.environ.get("PREPROD_ACCESS_TOKEN", "").strip()


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


def test_health_endpoint():
    status, body, _ = _request("/api/health.php")
    assert status == 200, f"Expected 200 from /api/health.php, got {status}: {body}"
    payload = json.loads(body)
    assert payload["status"] == "ok"
    assert payload["categories_count"] >= 114
    assert payload["approved_businesses_count"] == 12


def test_directory_homepage_and_zero_vetting_exposure():
    status, html, _ = _request("/")
    assert status == 200
    assert "Busherian Biasharas" in html
    # Verify Ticket #34: Favicon & WhatsApp/Search OpenGraph preview tags present in <head>
    assert f'href="{BASE_PATH}/favicon.svg"' in html
    assert f'href="{BASE_PATH}/apple-touch-icon.png"' in html
    assert '/assets/img/og-preview.png' in html
    assert '/assets/img/bb-icon-512.png' in html
    assert 'application/ld+json' in html

    st_svg, svg_body, _ = _request("/favicon.svg")
    assert st_svg == 200 and "#114B3E" in svg_body and "#7B1113" in svg_body
    st_og, _, _ = _request("/assets/img/og-preview.png")
    assert st_og == 200
    st_css, css_body, _ = _request("/assets/css/app.css")
    assert st_css == 200 and "border: none;" in css_body
    # Verify zero external CDN leaks on load (Kenya DPA 2019 L2)
    for cdn in ["fonts.googleapis.com", "cdn.jsdelivr.net", "unpkg.com", "cdnjs.cloudflare.com"]:
        assert cdn not in html, f"Found forbidden CDN {cdn} on homepage"
    # Verify houses never appear on the public directory homepage
    for house in ["Livingstone", "Aggrey", "Githaiga House", "Dorcas Luseno", "Joan Waithaka"]:
        assert f"House: {house}" not in html


def test_businesses_api_synonym_and_spatial_search():
    # Swahili synonym search: 'mjengo' -> Building / Hardware / Construction (Mjenzi & Prescott Engineering)
    status, body, _ = _request("/api/businesses.php?q=mjengo")
    assert status == 200
    data = json.loads(body)
    assert data["status"] == "ok"
    assert data["count"] >= 1
    first = data["businesses"][0]
    assert any(b["name"] == "Prescott Engineering Solutions" for b in data["businesses"])
    assert all(b["name"] != "Mjenzi" for b in data["businesses"])
    # Verify zero vetting keys in API payload
    for forbidden_key in ["house_name", "year_left", "school_number", "submitter_email"]:
        assert forbidden_key not in first

    # Spatial Haversine search near Westlands (-1.2647, 36.8021)
    status2, body2, _ = _request("/api/businesses.php?lat=-1.2647&lng=36.8021&radius_km=5")
    assert status2 == 200
    data2 = json.loads(body2)
    assert data2["count"] >= 1
    assert data2["businesses"][0]["distance_km"] is not None
    assert data2["businesses"][0]["distance_km"] <= 5.0


def test_website_autofill_api_and_ssrf_protection():
    # Fetch CSRF token from /submit.php
    status, submit_html, headers = _request("/submit.php")
    assert status == 200
    assert "Extract &amp; Auto-Fill from Website" in submit_html
    assert "School Number" in submit_html
    assert "Admission Number" not in submit_html
    # Verify Ticket #33: Regulator box is hidden by default, required when regulated specialty picked, and license number box is omitted
    assert 'id="regulated-fields-wrap" hidden' in submit_html
    assert "Professional Regulator (Optional)" not in submit_html
    assert 'name="regulator_license"' not in submit_html
    assert '"is_regulated":true' in submit_html
    assert "LSK (Law Society of Kenya)" in submit_html

    m = re.search(r'name="csrf_token"[^>]+value="([^"]+)"', submit_html)
    assert m, "Could not find csrf_token in /submit.php"
    csrf_token = m.group(1)

    cookie = headers.get("Set-Cookie") or headers.get("set-cookie") or ""
    req_headers = {"Content-Type": "application/json", "X-CSRF-Token": csrf_token}
    if cookie:
        req_headers["Cookie"] = cookie.split(";")[0]

    # 1. Test valid website URL auto-fill extraction
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
    auto_data = json.loads(body_ok)
    assert auto_data["status"] == "ok"
    extracted = auto_data["data"]
    assert extracted["name"] == "Wakili Peak Advocates LLP"
    assert extracted["phone_primary"] == "+254722998877"
    assert extracted["area_neighborhood"] == "Upper Hill"
    assert extracted["suggested_category"] is not None
    assert "Advocates" in extracted["suggested_category"]["specialty_name"]
    assert "field_provenance" in extracted and "name" in extracted["field_provenance"]

    # 2. Test SSRF protection blocks internal/metadata URLs
    for bad_url in ["http://127.0.0.1/admin", "http://169.254.169.254/latest/meta-data/"]:
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
    assert "DELETE FROM" not in html_priv
    assert "business_vetting_queue" not in html_priv
    assert "<code>" not in html_priv

    st_td, html_td, _ = _request("/takedown.php")
    assert st_td == 200
    assert "Request Immediate Listing Removal or Correction" in html_td
    assert "<code>" not in html_td
    assert 'name="form_sig"' in html_td

    # Verify Ticket #10: /admin/index.php returns 401 when unauthenticated and does not leak pending vetting queue
    st_adm, html_adm, _ = _request("/admin/index.php")
    assert st_adm == 401, f"Expected 401 on unauthenticated /admin/index.php, got {st_adm}"
    st_add, _, _ = _request("/admin/add-business.php")
    assert st_add == 401, f"Expected 401 on unauthenticated /admin/add-business.php, got {st_add}"
    assert "Admin Sign In" in html_adm
    assert "Temporary Vetting Credentials" not in html_adm




def test_school_filters_and_route_directions_api():
    # Verify AGHS returns exactly Bustani Bistro, Harriet's Botanicals, and Mambo Pambo (3)
    st_aghs, body_aghs, _ = _request("/api/businesses.php?school=AGHS")
    assert st_aghs == 200
    aghs_data = json.loads(body_aghs)
    aghs_names = sorted([b["name"] for b in aghs_data["businesses"]])
    assert aghs_names == ["Bustani Bistro", "Harriet's Botanicals", "Mambo Pambo"], f"Unexpected AGHS list: {aghs_names}"

    # Verify AHS returns the remaining 9 businesses
    st_ahs, body_ahs, _ = _request("/api/businesses.php?school=AHS")
    assert st_ahs == 200
    ahs_data = json.loads(body_ahs)
    assert ahs_data["count"] == 9, f"Expected 9 AHS businesses, got {ahs_data['count']}"

    # Verify sequential 1..12 business_number and distinct BBIZ-* business_id across all 12 records
    st_all, body_all, _ = _request("/api/businesses.php")
    assert st_all == 200
    all_biz = json.loads(body_all)["businesses"]
    nums = sorted([b["business_number"] for b in all_biz])
    uids = {b["business_id"] for b in all_biz}
    assert nums == list(range(1, 13)), f"Expected gapless 1..12 business_number, got {nums}"
    assert len(uids) == 12 and all(u.startswith("BBIZ-") for u in uids), f"Expected 12 unique BBIZ-* IDs, got {uids}"

    # Verify /api/route-directions.php returns distance, duration, polyline & steps
    st_rt, body_rt, _ = _request("/api/route-directions.php?from_lat=-1.286389&from_lng=36.817223&to_lat=-1.278800&to_lng=36.771200")
    assert st_rt == 200, f"Expected 200 from route-directions.php, got {st_rt}: {body_rt}"
    rt = json.loads(body_rt)["route"]
    assert rt["straight_km"] > 4.0
    assert rt["road_km"] > 4.0
    assert rt["duration_min"] >= 1
    assert len(rt["polyline"]) >= 2
    assert len(rt["steps"]) >= 1
    assert "corridors" in rt
    steps_joined = " ".join(s.get("instruction", "") for s in rt["steps"]).lower()
    for compass_word in ("northwest", "northeast", "southwest", "southeast", "head north", "head south", "head east", "head west"):
        assert compass_word not in steps_joined, f"Unexpected compass word {compass_word} in steps: {steps_joined}"

    # Verify Ticket #39: /api/geocode.php resolves Kenyan streets/estates (Marist Lane, Karen)
    st_geo, geo_body, _ = _request("/api/geocode.php?q=Marist+Lane,+Karen")
    assert st_geo == 200, f"Expected 200 from /api/geocode.php, got {st_geo}: {geo_body}"
    geo_payload = json.loads(geo_body)
    assert geo_payload["status"] == "ok"
    assert abs(geo_payload["location"]["latitude"] - (-1.34447)) < 0.02
    assert abs(geo_payload["location"]["longitude"] - 36.72865) < 0.02

    # Verify Tickets #37 & #39: directory.js uses watchPosition, custom origin search, and isolates selected business on map
    st_djs, djs_body, _ = _request("/assets/js/directory.js")
    assert st_djs == 200
    assert "captureBrowserLocation" in djs_body
    assert "watchPosition" in djs_body
    assert "data-origin-search-form" in djs_body
    assert "ORIGIN_PRESETS" not in djs_body
    assert "Showing only:" in djs_body
    assert "visiblePinned" in djs_body


def test_dpa_cookie_consent_and_usage_metrics():
    # 1. Verify consent-metrics.js is served and strictly defines 'Reject All' and 'Accept' buttons
    st_js, js_body, _ = _request("/assets/js/consent-metrics.js")
    assert st_js == 200
    assert "Allow only Essential" in js_body
    assert "Allow for Analysis" in js_body
    assert "bbiz_cookie_consent" in js_body

    # 2. Verify /api/track-metric.php ignores requests when consent cookie is absent or 'rejected'
    st_no, body_no, _ = _request(
        "/api/track-metric.php",
        method="POST",
        data=json.dumps({"event_type": "page_view", "page_path": "/"}).encode("utf-8"),
        headers={"Content-Type": "application/json"},
    )
    assert st_no == 200
    assert json.loads(body_no)["status"] == "ignored_no_consent"

    st_rej, body_rej, _ = _request(
        "/api/track-metric.php",
        method="POST",
        data=json.dumps({"event_type": "page_view", "page_path": "/"}).encode("utf-8"),
        headers={"Content-Type": "application/json", "Cookie": "bbiz_consent=rejected"},
    )
    assert st_rej == 200
    assert json.loads(body_rej)["status"] == "ignored_no_consent"

    # 3. Verify /api/track-metric.php records metric when consent cookie is 'accepted'
    st_acc, body_acc, _ = _request(
        "/api/track-metric.php",
        method="POST",
        data=json.dumps({"event_type": "page_view", "page_path": "/"}).encode("utf-8"),
        headers={"Content-Type": "application/json", "Cookie": "bbiz_consent=accepted; bbiz_vid=e2econsentedvisitor1234"},
    )
    assert st_acc == 200, f"Expected 200 from consented track-metric, got {st_acc}: {body_acc}"
    assert json.loads(body_acc)["status"] == "ok"

    # 4. Verify /admin/metrics.php enforces 401 when unauthenticated
    st_m, _, _ = _request("/admin/metrics.php")
    assert st_m == 401

    # 5. Verify Tickets #42, #44 & #151-#154: Owner login portal never renders broken unconfigured Google error & serves Email + Password Sign-In
    st_ol, body_ol, _ = _request("/owner/login.php")
    assert st_ol == 200
    assert "google_unconfigured" not in body_ol
    assert "Sign In with Email &amp; Password" in body_ol

    st_home, body_home, _ = _request("/")
    assert st_home == 200
    assert "/owner/login.php" in body_home

    # 6. Verify Tickets #46 (#88, #89) & #90 (#91, #92, #93): Sunday hours inputs + Photo upload & gallery carousel
    st_sub, body_sub, _ = _request("/submit.php")
    assert st_sub == 200
    assert 'name="hours_sun_open"' in body_sub
    assert 'name="hours_sun_close"' in body_sub
    assert 'name="hours_sun_closed"' in body_sub
    assert 'name="business_photos[]"' in body_sub
    assert 'name="business_photos_data_json"' in body_sub

    st_api, body_api, _ = _request("/api/businesses.php")
    assert st_api == 200
    api_data = json.loads(body_api)
    assert len(api_data["businesses"]) >= 1
    first_biz = api_data["businesses"][0]
    assert "photos" in first_biz and isinstance(first_biz["photos"], list)
    assert "sun" in first_biz["hours_status"]["schedule_table"]

    # 7. Verify Ticket #94: Outbound referral attribution in directory.js
    st_js, body_js, _ = _request("/assets/js/directory.js")
    assert st_js == 200
    assert "withOutboundAttribution" in body_js
    assert "busherianbiasharas.com" in body_js
    assert 'rel="noopener" data-track="website"' in body_js

    # 8. Verify Ticket #95: Automatic localStorage & sticky POST draft persistence on /submit.php
    assert 'id="draft-restored-banner"' in body_sub
    assert 'id="btn-clear-draft"' in body_sub
    assert 'window.BBIZ_SUBMIT_POST_DRAFT' in body_sub
    st_fe, body_fe, _ = _request("/assets/js/form-enhancements.js")
    assert st_fe == 200
    assert "bbiz_submit_draft_v1" in body_fe
    assert "applyDraftToSubmitForm" in body_fe

    # 9. Verify Tickets #96 (#97, #98): 2-level Place Filter (Country -> City/County/State)
    assert 'id="filter-country"' in body_home
    assert 'id="filter-city"' in body_home
    assert 'window.PLACE_HIERARCHY' in body_home
    assert 'name="country"' in body_sub
    assert 'name="city_county"' in body_sub
    st_place, body_place, _ = _request("/api/businesses.php?country=Kenya&city_county=Nairobi")
    assert st_place == 200
    place_data = json.loads(body_place)
    assert len(place_data["businesses"]) >= 1
    assert all(b.get("country") == "Kenya" and b.get("city_county") == "Nairobi" for b in place_data["businesses"])

    st_none_place, body_none_place, _ = _request("/api/businesses.php?country=Switzerland&city_county=Geneva")
    assert st_none_place == 200
    assert len(json.loads(body_none_place)["businesses"]) == 0

    # 10. Verify Ticket #99: AGHS houses Joan Mjomba & Rebecca Karanja present on /submit.php
    assert "Joan Mjomba" in body_sub
    assert "Rebecca Karanja" in body_sub

    # 11. Verify Tickets #105 (#106, #107): Direct Edit My Business button in directory.js/API & Owner Sign-In clarity
    assert "can_edit" in first_biz
    assert "edit_url" in first_biz
    assert "Edit My Business" in body_js
    assert "Own this business? Sign in to edit" in body_js
    assert 'id="owner-signin-guide"' in body_ol
    assert "How Owner Sign-In &amp; Business Linking Works" in body_ol
    st_ol_biz, body_ol_biz, _ = _request(f"/owner/login.php?biz={first_biz['id']}")
    assert st_ol_biz == 200
    assert "Sign In to Edit Business Listing" in body_ol_biz
    assert first_biz["name"] in body_ol_biz

    # 12. Verify Tickets #109, #110, #111: Zero displayed code/SQL/filenames across public & owner pages
    assert "<code>" not in body_home
    assert "<code>" not in body_sub
    assert "DELETE FROM" not in body_sub
    assert "<code>" not in body_ol
    assert "/submit.php</code>" not in body_ol

    # 13. Verify Tickets #112, #113, #114: Arts, Visual & Performing Arts sector & specialties
    assert "Arts, Visual &amp; Performing Arts" in body_home
    assert "Arts, Visual &amp; Performing Arts" in body_sub
    assert "Visual Arts, Fine Art & Galleries" in body_sub
    assert "Performing Arts, Theatre & Dance" in body_sub

    # 14. Verify Ticket #115: "Same as Direct Phone above" checkboxes on /submit.php
    assert 'id="same_as_direct_phone_primary"' in body_sub
    assert 'id="same_as_direct_phone_whatsapp"' in body_sub
    assert "Same as Direct Phone above" in body_sub
    assert "bindSameAsDirectPhone" in body_fe

    # 15. Verify Ticket #116: All world countries rendered in country dropdown
    for c_name in ("Afghanistan", "Brazil", "Japan", "Switzerland", "Zambia", "Zimbabwe"):
        assert f'<option value="{c_name}"' in body_home, f"Missing country {c_name} in homepage filter"
        assert f'<option value="{c_name}"' in body_sub, f"Missing country {c_name} in submit.php dropdown"

    # 16. Verify Tickets #118, #119, #120 & #151-#155: Password-First Owner Sign-In, 6-Digit Reset Code & Masked Email Hints
    assert 'id="owner-password"' in body_ol
    assert 'Sign In with Email &amp; Password' in body_ol
    assert 'Set or Reset Password with a 6-Digit Email Code' in body_ol
    assert 'owner_email_hints' in first_biz
    assert 'Owner Sign In / Edit Listing' in body_home
    assert 'id="owner_password"' not in body_sub
    assert "Verified Owner Account &amp; Password Setup" in body_sub

    # 17. Verify Ticket #122: AHS solid green & AGHS green-maroon blend map markers
    assert "bbiz-pin-ahs" in body_js
    assert "bbiz-pin-aghs" in body_js
    assert "linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%)" in body_js
    assert "Green = AHS" in body_home
    assert "Green &amp; Maroon = AGHS" in body_home
    assert "Green = Open Now" not in body_home

    # 18. Verify Tickets #132, #133, #134: Alphabetical countries & standard administrative units (County for Kenya, State for US, District for Tanzania)
    assert 'window.PLACE_UNIT_LABELS' in body_home
    assert 'data-place-unit-labels=' in body_sub
    assert 'id="city_county_label"' in body_sub
    assert 'County *</label>' in body_sub
    assert '<option value="Kenya" selected>Kenya</option>' in body_sub
    assert '<option value="Nairobi" selected>Nairobi</option>' in body_sub
    assert body_sub.index('<option value="Afghanistan"') < body_sub.index('<option value="Kenya"') < body_sub.index('<option value="Zimbabwe"')
    assert body_sub.index('<option value="Baringo"') < body_sub.index('<option value="Nairobi"') < body_sub.index('<option value="West Pokot"')

    # 19. Verify Tickets #138, #139, #140: Maps link -> latitude/longitude auto-inference in /api/geocode.php & form-enhancements.js
    gmaps_place_url = (
        "https://www.google.com/maps/place/Circular+Economy+Hub/@-2.7093033,36.9857735,8z/"
        "data=!4m7!3m6!1s0x184073cc97d95e6f:0xd08f849ff152e4a2!8m2!3d-1.3766863!4d36.9280114"
    )
    st_gpin, body_gpin, _ = _request("/api/geocode.php?q=" + urllib.parse.quote(gmaps_place_url, safe=""))
    assert st_gpin == 200, f"Expected 200 from /api/geocode.php for Google Maps place URL, got {st_gpin}: {body_gpin}"
    gpin_loc = json.loads(body_gpin)["location"]
    assert abs(gpin_loc["latitude"] - (-1.376686)) < 0.0001
    assert abs(gpin_loc["longitude"] - 36.928011) < 0.0001
    assert gpin_loc.get("plus_code")
    assert "bindMapsPinAutoFill" in body_fe
    assert 'id="google_maps_url"' in body_sub

    # 20. Verify Tickets #142, #143, #144: 10 / 25 / 50 / 100 pagination (default 10) on Main Public Directory & directory.js
    assert 'id="per-page-select"' in body_home
    assert '<option value="10" selected>10 per page</option>' in body_home
    for opt_sz in (25, 50, 100):
        assert f'<option value="{opt_sz}">{opt_sz} per page</option>' in body_home
    assert 'id="directory-pagination"' in body_home
    assert "ALLOWED_PAGE_SIZES = [10, 25, 50, 100]" in body_js
    assert "renderPaginationControls" in body_js


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

    # Dynamically select two distinct listings with public contact emails from /api/businesses.php
    _, api_body, _ = _request("/api/businesses.php")
    all_biz = json.loads(api_body)["businesses"]
    biz_with_email = [b for b in all_biz if (b.get("email_public") or "").strip()]
    assert len(biz_with_email) >= 2, "Expected at least 2 businesses with email_public in test DB"
    victim_biz = biz_with_email[0]
    victim_biz_id = int(victim_biz["id"])
    victim_email = victim_biz["email_public"].strip().lower()
    attacker_seed_biz = next(
        b for b in biz_with_email if int(b["id"]) != victim_biz_id and b["email_public"].strip().lower() != victim_email
    )
    attacker_seed_email = attacker_seed_biz["email_public"].strip().lower()
    other_biz = next(
        b for b in all_biz
        if int(b["id"]) not in (victim_biz_id, int(attacker_seed_biz["id"]))
    )
    other_biz_id = int(other_biz["id"])

    # 1. Establish a real password-protected Victim Owner via verified 6-digit OTP
    victim_cookies: dict[str, str] = {}
    victim_pw = "VictimSafe#Pass2026"

    st, login_html, _, _ = _http_raw("/owner/login.php?mode=reset", cookie_jar=victim_cookies)
    assert st == 200
    csrf_v = _extract_hidden(login_html, "csrf_token")
    st_otp, otp_html, _, _ = _http_raw(
        "/owner/login.php",
        method="POST",
        form={"csrf_token": csrf_v, "form_step": "send_otp", "email": victim_email, "biz": "0"},
        cookie_jar=victim_cookies,
    )
    assert st_otp == 200
    otp_match = re.search(r"Preview Verification Code:.*?<strong[^>]*>(\d{6})</strong>", otp_html, re.S)
    assert otp_match, "Expected simulated 6-digit OTP preview in testing mode"
    otp_code = otp_match.group(1)
    csrf_v2 = _extract_hidden(otp_html, "csrf_token")

    st_ver, _, ver_hdrs, _ = _http_raw(
        "/owner/login.php",
        method="POST",
        form={
            "csrf_token": csrf_v2,
            "form_step": "verify_otp",
            "email": victim_email,
            "otp_code": otp_code,
            "new_password": victim_pw,
            "confirm_password": victim_pw,
            "biz": "0",
        },
        cookie_jar=victim_cookies,
    )
    assert st_ver == 302, f"Expected 302 after verified OTP setup, got {st_ver}"
    assert any("owner_session" in k for k in victim_cookies), "Expected victim session cookie after OTP verification"

    # 2. CRIT-01 Negative Test: Unauthenticated attacker submits /submit.php with Victim's email + attacker password
    attacker_sub_cookies: dict[str, str] = {}
    st_sf, sub_html, _, _ = _http_raw("/submit.php", cookie_jar=attacker_sub_cookies)
    assert st_sf == 200
    sub_csrf = _extract_hidden(sub_html, "csrf_token")
    sub_ts = _extract_hidden(sub_html, "form_ts")
    sub_nonce = _extract_hidden(sub_html, "form_nonce")
    sub_sig = _extract_hidden(sub_html, "form_sig")

    # Pick an unregulated (sector_id, specialty_id) directly from /api/categories.php
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

    # Wait >= 4.1s so AntiSpamGate Layer 2 time-trap passes genuinely
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
    assert st_attack_sub == 200
    assert "Submission Received" in attack_sub_body, f"Unexpected submit.php response: {attack_sub_body[:1200]}"
    # Attacker MUST NOT receive an owner session cookie or dashboard access!
    assert not any("owner_session" in k for k in attacker_sub_cookies), (
        f"CRIT-01 regression: unauthenticated submit.php issued owner session cookie: {attack_sub_set_cookies}"
    )
    st_idx_chk, _, idx_hdrs, _ = _http_raw("/owner/index.php", cookie_jar=attacker_sub_cookies)
    assert st_idx_chk == 302 and "/owner/login.php" in (idx_hdrs.get("Location") or "")

    # Victim's password MUST NOT have been overwritten by 'HackedPassword#999'
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
    assert not any("owner_session" in k for k in pw_probe_cookies)

    # Victim's real password STILL works!
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

    # 3. CRIT-02 Negative Test: Attacker logs into their own account via /owner/login.php?claim=<victim_biz_id>
    # First create a distinct Attacker Owner account via verified OTP on a separate pre-populated listing (director@branfoot.com)
    attacker_cookies: dict[str, str] = {}
    attacker_email = attacker_seed_email
    attacker_pw = "AttackerOwn#Pass2026"

    _, al_html, _, _ = _http_raw(f"/owner/login.php?mode=reset&claim={victim_biz_id}", cookie_jar=attacker_cookies)
    al_csrf = _extract_hidden(al_html, "csrf_token")
    st_aotp, aotp_html, _, _ = _http_raw(
        f"/owner/login.php?claim={victim_biz_id}",
        method="POST",
        form={
            "csrf_token": al_csrf,
            "form_step": "send_otp",
            "email": attacker_email,
            "claim": str(victim_biz_id),
            "biz": "0",
        },
        cookie_jar=attacker_cookies,
    )
    assert st_aotp == 200
    aotp_code = re.search(r"Preview Verification Code:.*?<strong[^>]*>(\d{6})</strong>", aotp_html, re.S).group(1)
    al_csrf2 = _extract_hidden(aotp_html, "csrf_token")
    st_aver, _, aver_hdrs, _ = _http_raw(
        f"/owner/login.php?claim={victim_biz_id}",
        method="POST",
        form={
            "csrf_token": al_csrf2,
            "form_step": "verify_otp",
            "email": attacker_email,
            "otp_code": aotp_code,
            "new_password": attacker_pw,
            "confirm_password": attacker_pw,
            "claim": str(victim_biz_id),
            "biz": "0",
        },
        cookie_jar=attacker_cookies,
    )
    assert st_aver == 302
    assert f"id={victim_biz_id}" not in (aver_hdrs.get("Location") or ""), (
        f"CRIT-02 regression: OTP login honored unsigned claim parameter! Redirected to {aver_hdrs.get('Location')}"
    )

    # Also test password_login with ?claim=<victim_biz_id>
    attacker_pw_cookies: dict[str, str] = {}
    _, apl_html, _, _ = _http_raw(f"/owner/login.php?claim={victim_biz_id}", cookie_jar=attacker_pw_cookies)
    apl_csrf = _extract_hidden(apl_html, "csrf_token")
    st_apw, _, apw_hdrs, _ = _http_raw(
        f"/owner/login.php?claim={victim_biz_id}",
        method="POST",
        form={
            "csrf_token": apl_csrf,
            "form_step": "password_login",
            "email": attacker_email,
            "password": attacker_pw,
            "claim": str(victim_biz_id),
            "biz": "0",
        },
        cookie_jar=attacker_pw_cookies,
    )
    assert st_apw == 302
    assert f"id={victim_biz_id}" not in (apw_hdrs.get("Location") or "")

    # Verify Attacker cannot edit Victim's business via /owner/edit-business.php or /api/businesses.php
    st_edit_v, _, edit_v_hdrs, _ = _http_raw(
        f"/owner/edit-business.php?id={victim_biz_id}",
        cookie_jar=attacker_pw_cookies,
    )
    assert st_edit_v == 302 and "/owner/index.php" in (edit_v_hdrs.get("Location") or "")

    # 4. Also verify /owner/claim.php impersonation block + legitimate OTP claim + single-use replay lock when APP_HMAC_SECRET is set
    hmac_secret = os.environ.get("APP_HMAC_SECRET", "").strip()
    if hmac_secret:
        import hashlib
        import hmac

        exp = int(time.time()) + 1800
        sig = hmac.new(
            hmac_secret.encode("utf-8"),
            f"claim_invite|{other_biz_id}|{exp}".encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        claim_path = f"/owner/claim.php?biz_id={other_biz_id}&exp={exp}&sig={sig}"

        # Attempt cross-account impersonation of victim_email without victim's password/OTP
        imp_cookies: dict[str, str] = {}
        st_cl, cl_html, _, _ = _http_raw(claim_path, cookie_jar=imp_cookies)
        assert st_cl == 200
        cl_csrf = _extract_hidden(cl_html, "csrf_token")
        st_imp, imp_html, _, _ = _http_raw(
            "/owner/claim.php",
            method="POST",
            form={
                "csrf_token": cl_csrf,
                "claim_step": "password_claim",
                "biz_id": str(other_biz_id),
                "exp": str(exp),
                "sig": sig,
                "owner_email": victim_email,
                "current_password": "",
            },
            cookie_jar=imp_cookies,
        )
        assert st_imp == 200
        assert not any("owner_session" in k for k in imp_cookies)

        # Legitimate 2-step OTP claim on claim.php + single-use consumption check
        legit_cookies: dict[str, str] = {}
        legit_claim_email = "verified.claimant.e2e@gmail.com"
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
        assert any("owner_session" in k for k in legit_cookies)

        # Replaying the exact same signed claim URL in a new session MUST now be rejected!
        replay_cookies: dict[str, str] = {}
        st_rep, _, rep_hdrs, _ = _http_raw(claim_path, cookie_jar=replay_cookies)
        assert st_rep == 302 and "err=invalid_link" in (rep_hdrs.get("Location") or "")


TESTS = [
    test_health_endpoint,
    test_directory_homepage_and_zero_vetting_exposure,
    test_businesses_api_synonym_and_spatial_search,
    test_website_autofill_api_and_ssrf_protection,
    test_privacy_and_dpa_takedown_pages,
    test_school_filters_and_route_directions_api,
    test_dpa_cookie_consent_and_usage_metrics,
    test_adversarial_crit_01_and_crit_02_regressions,
]
