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
            return resp.status, resp.read().decode("utf-8"), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8"), dict(e.headers)


def test_health_endpoint():
    status, body, _ = _request("/api/health.php")
    assert status == 200, f"Expected 200 from /api/health.php, got {status}: {body}"
    payload = json.loads(body)
    assert payload["status"] == "ok"
    assert payload["categories_count"] >= 114
    assert payload["approved_businesses_count"] >= 6


def test_directory_homepage_and_zero_vetting_exposure():
    status, html, _ = _request("/")
    assert status == 200
    assert "Busherian Biasharas" in html
    # Verify zero external CDN leaks on load (Kenya DPA 2019 L2)
    for cdn in ["fonts.googleapis.com", "cdn.jsdelivr.net", "unpkg.com", "cdnjs.cloudflare.com"]:
        assert cdn not in html, f"Found forbidden CDN {cdn} on homepage"
    # Verify houses never appear on the public directory homepage
    for house in ["Livingstone", "Aggrey", "Githaiga House", "Dorcas Luseno", "Joan Waithaka"]:
        assert f"House: {house}" not in html


def test_businesses_api_synonym_and_spatial_search():
    # Swahili synonym search: 'wakili' -> Advocates & Law Firm
    status, body, _ = _request("/api/businesses.php?q=wakili")
    assert status == 200
    data = json.loads(body)
    assert data["status"] == "ok"
    assert data["count"] >= 1
    first = data["businesses"][0]
    assert "Advocates" in first["name"] or "Advocates" in first["specialty_name"]
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


TESTS = [
    test_health_endpoint,
    test_directory_homepage_and_zero_vetting_exposure,
    test_businesses_api_synonym_and_spatial_search,
    test_website_autofill_api_and_ssrf_protection,
    test_privacy_and_dpa_takedown_pages,
]
