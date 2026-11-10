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
    assert "DELETE FROM business_vetting_queue" in html_priv

    st_td, html_td, _ = _request("/takedown.php")
    assert st_td == 200
    assert "Section 40" in html_td
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
    assert len(rt["steps"]) <= 4, f"Expected loose route overview (<=4 steps), got {len(rt['steps'])}"
    assert "corridors" in rt


def test_dpa_cookie_consent_and_usage_metrics():
    # 1. Verify consent-metrics.js is served and strictly defines 'Reject All' and 'Accept' buttons
    st_js, js_body, _ = _request("/assets/js/consent-metrics.js")
    assert st_js == 200
    assert "Reject All" in js_body
    assert "Accept" in js_body
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


TESTS = [
    test_health_endpoint,
    test_directory_homepage_and_zero_vetting_exposure,
    test_businesses_api_synonym_and_spatial_search,
    test_website_autofill_api_and_ssrf_protection,
    test_privacy_and_dpa_takedown_pages,
    test_school_filters_and_route_directions_api,
    test_dpa_cookie_consent_and_usage_metrics,
]
