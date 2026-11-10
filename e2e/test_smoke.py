import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("BASE_URL", "http://127.0.0.1:8080").rstrip("/")


def _request(path: str, method: str = "GET", data: bytes | None = None, headers: dict | None = None):
    url = f"{BASE_URL}{path}"
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
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
    assert 'href="/favicon.svg"' in html
    assert 'href="/apple-touch-icon.png"' in html
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

    # 5. Verify Tickets #42 & #44: Owner login portal never renders broken unconfigured Google error & serves Email Sign-In Link
    st_ol, body_ol, _ = _request("/owner/login.php")
    assert st_ol == 200
    assert "google_unconfigured" not in body_ol
    assert "Send 1-Click Sign-In Link" in body_ol

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

    # 16. Verify Tickets #118, #119, #120: Multi-device Email + Password option alongside 1-Click Email Link on /owner/login.php
    assert 'id="owner-password"' in body_ol
    assert 'Sign In Immediately with Password' in body_ol
    assert 'Send 1-Click Sign-In Link' in body_ol

    # 17. Verify Ticket #122: AHS solid green & AGHS green-maroon blend map markers
    assert "bbiz-pin-ahs" in body_js
    assert "bbiz-pin-aghs" in body_js
    assert "linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%)" in body_js
    assert "Green = AHS" in body_home
    assert "Green &amp; Maroon = AGHS" in body_home
    assert "Green = Open Now" not in body_home


TESTS = [
    test_health_endpoint,
    test_directory_homepage_and_zero_vetting_exposure,
    test_businesses_api_synonym_and_spatial_search,
    test_website_autofill_api_and_ssrf_protection,
    test_privacy_and_dpa_takedown_pages,
    test_school_filters_and_route_directions_api,
    test_dpa_cookie_consent_and_usage_metrics,
]
