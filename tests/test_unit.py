import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def test_php_unit_suite():
    res = subprocess.run(
        ["php", str(ROOT / "tests" / "run_unit_tests.php")],
        capture_output=True,
        text=True,
        check=False,
    )
    assert res.returncode == 0, f"PHP unit tests failed:\nSTDOUT:\n{res.stdout}\nSTDERR:\n{res.stderr}"
    assert "All " in res.stdout and "passed!" in res.stdout


def test_schema_has_no_vetting_fields_on_businesses_table():
    schema_sql = (ROOT / "src" / "schema.sql").read_text()
    businesses_block = schema_sql.split("CREATE TABLE IF NOT EXISTS businesses")[1].split("CREATE TABLE IF NOT EXISTS business_vetting_queue")[0]
    assert "house_name" not in businesses_block
    assert "year_left" not in businesses_block
    assert "school_number" not in businesses_block
    assert "admission_number" not in schema_sql.lower()


def test_zero_external_cdn_links_in_public_pages():
    forbidden_cdns = [
        "fonts.googleapis.com",
        "fonts.gstatic.com",
        "cdn.jsdelivr.net",
        "unpkg.com",
        "cdnjs.cloudflare.com",
        "cdn.tailwindcss.com",
    ]
    for php_file in (ROOT / "public").rglob("*.php"):
        content = php_file.read_text()
        for cdn in forbidden_cdns:
            assert cdn not in content, f"External CDN {cdn} found in {php_file} (violates L2 DPA pre-consent IP leak rule)"
