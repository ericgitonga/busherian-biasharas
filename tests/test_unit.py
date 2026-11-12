import importlib.util
import socket
import ssl
import subprocess
import threading
from pathlib import Path
import pytest

ROOT = Path(__file__).resolve().parent.parent


def _load_ftps_deploy_module():
    script_path = ROOT / ".github" / "scripts" / "ftps_deploy.py"
    spec = importlib.util.spec_from_file_location("ftps_deploy", script_path)
    assert spec is not None and spec.loader is not None
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


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


def _generate_self_signed_cert(tmp_path: Path, cn: str, san_dns: str) -> tuple[Path, Path]:
    cert_pem = tmp_path / f"{cn}.crt"
    key_pem = tmp_path / f"{cn}.key"
    subprocess.run(
        [
            "openssl",
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            str(key_pem),
            "-out",
            str(cert_pem),
            "-days",
            "2",
            "-subj",
            f"/CN={cn}",
            "-addext",
            f"subjectAltName=DNS:{san_dns}",
        ],
        check=True,
        capture_output=True,
    )
    return cert_pem, key_pem


class _MockExplicitFtpsServer:
    """Minimal Explicit FTPS server speaking 220 -> AUTH TLS -> 234 -> TLS wrap to test client verification (#170)."""

    def __init__(self, cert_pem: Path, key_pem: Path):
        self.server_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self.server_ctx.load_cert_chain(certfile=str(cert_pem), keyfile=str(key_pem))
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("127.0.0.1", 0))
        self.sock.listen(5)
        self.port = self.sock.getsockname()[1]
        self.received_cleartext: list[bytes] = []
        self.received_tls_post_handshake: list[bytes] = []
        self._thread = threading.Thread(target=self._serve_once, daemon=True)

    def start(self):
        self._thread.start()

    def _serve_once(self):
        self.sock.settimeout(5.0)
        try:
            conn, _ = self.sock.accept()
        except OSError:
            return
        with conn:
            conn.settimeout(5.0)
            try:
                conn.sendall(b"220 Mock FTPS Server Ready\r\n")
                line = conn.recv(1024)
                self.received_cleartext.append(line)
                if line.upper().startswith(b"AUTH TLS"):
                    conn.sendall(b"234 Proceed with negotiation\r\n")
                    with self.server_ctx.wrap_socket(conn, server_side=True) as tls_conn:
                        tls_conn.settimeout(2.0)
                        try:
                            post = tls_conn.recv(1024)
                            if post:
                                self.received_tls_post_handshake.append(post)
                        except OSError:
                            pass
            except OSError:
                pass
            finally:
                try:
                    self.sock.close()
                except OSError:
                    pass

    def join(self):
        self._thread.join(timeout=5.0)


def test_ftps_deploy_tls_context_and_path_safety():
    ftps_mod = _load_ftps_deploy_module()
    ctx = ftps_mod.build_verified_tls_context()
    assert ctx.verify_mode == ssl.CERT_REQUIRED
    assert ctx.check_hostname is True
    assert ctx.minimum_version >= ssl.TLSVersion.TLSv1_2

    assert (
        ftps_mod.map_remote_path("public/index.php", "/busherianbiasharas.com/www", "prod")
        == "/busherianbiasharas.com/www/html/index.php"
    )
    assert (
        ftps_mod.map_remote_path("src/bootstrap.php", "/busherianbiasharas.com/www", "prod")
        == "/busherianbiasharas.com/www/src/bootstrap.php"
    )
    assert (
        ftps_mod.map_remote_path("public/index.php", "/busherianbiasharas.com/www", "preprod")
        == "/busherianbiasharas.com/www/html/admin/preprod/index.php"
    )
    assert (
        ftps_mod.map_remote_path("src/bootstrap.php", "/busherianbiasharas.com/www", "preprod")
        == "/busherianbiasharas.com/www/preprod_app/src/bootstrap.php"
    )

    for forbidden in (".env", ".env.production", "storage/busherian_biasharas.sqlite", "public/uploads/a.jpg", "../etc/passwd"):
        with pytest.raises(ValueError):
            ftps_mod.validate_relative_deploy_path(forbidden)

    for wf_name in ("deploy-ftp.yml", "deploy-preprod.yml"):
        wf_src = (ROOT / ".github" / "workflows" / wf_name).read_text()
        assert "ftps_deploy.py" in wf_src
        assert "ftplib.FTP_TLS()" not in wf_src


def test_ftps_deploy_aborts_before_login_on_untrusted_or_mismatched_tls_cert(tmp_path: Path):
    ftps_mod = _load_ftps_deploy_module()
    secret_pw = "SuperSecretHostingPass#170"

    # 1. Self-signed untrusted CA certificate MUST abort during AUTH TLS before USER/PASS
    cert_untrusted, key_untrusted = _generate_self_signed_cert(tmp_path, "localhost", "localhost")
    srv1 = _MockExplicitFtpsServer(cert_untrusted, key_untrusted)
    srv1.start()
    with pytest.raises(ssl.SSLError):
        ftps_mod.connect_verified_ftps(
            host="localhost",
            user="deploy_user",
            password=secret_pw,
            port=srv1.port,
            timeout=5,
        )
    srv1.join()
    combined_1 = b"".join(srv1.received_cleartext + srv1.received_tls_post_handshake)
    assert secret_pw.encode("utf-8") not in combined_1
    assert b"PASS " not in combined_1

    # 2. Trusted CA certificate with mismatched hostname MUST abort during AUTH TLS before USER/PASS
    cert_wrong_host, key_wrong_host = _generate_self_signed_cert(
        tmp_path, "wrong-host.example.invalid", "wrong-host.example.invalid"
    )
    srv2 = _MockExplicitFtpsServer(cert_wrong_host, key_wrong_host)
    srv2.start()
    with pytest.raises(ssl.SSLCertVerificationError):
        ftps_mod.connect_verified_ftps(
            host="localhost",
            user="deploy_user",
            password=secret_pw,
            port=srv2.port,
            timeout=5,
            cafile=str(cert_wrong_host),
        )
    srv2.join()
    combined_2 = b"".join(srv2.received_cleartext + srv2.received_tls_post_handshake)
    assert secret_pw.encode("utf-8") not in combined_2
    assert b"PASS " not in combined_2


def test_ftps_deploy_cli_clean_error_and_geny_intermediates_non_root(tmp_path: Path, monkeypatch, capsys):
    ftps_mod = _load_ftps_deploy_module()
    assert ftps_mod.DEFAULT_INTERMEDIATES_PEM.is_file()

    # Verify bundled Let's Encrypt Gen-Y intermediates contain ZERO self-signed root certificates
    pem_text = ftps_mod.DEFAULT_INTERMEDIATES_PEM.read_text(encoding="utf-8")
    import re
    certs = re.findall(r"-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----", pem_text, re.S)
    assert len(certs) >= 6
    for cert_pem in certs:
        res = subprocess.run(
            ["openssl", "x509", "-noout", "-subject", "-issuer"],
            input=cert_pem,
            capture_output=True,
            text=True,
            check=True,
        )
        lines = [ln.strip() for ln in res.stdout.strip().splitlines() if ln.strip()]
        assert len(lines) == 2
        subj = lines[0].removeprefix("subject=")
        iss = lines[1].removeprefix("issuer=")
        assert subj != iss, f"Bundled intermediate must not be a self-signed root: {subj}"

    # Verify main() catches SSL/FTPS failures cleanly without TypeError on ftplib.all_errors (#192)
    cert_untrusted, key_untrusted = _generate_self_signed_cert(tmp_path, "localhost", "localhost")
    srv = _MockExplicitFtpsServer(cert_untrusted, key_untrusted)
    srv.start()

    monkeypatch.chdir(tmp_path)
    staged_file = tmp_path / "VERSION"
    staged_file.write_text("0.14.1\n", encoding="utf-8")
    files_list = tmp_path / "changed.txt"
    files_list.write_text("VERSION\n", encoding="utf-8")

    monkeypatch.setenv("FTP_SERVER", "localhost")
    monkeypatch.setenv("FTP_USERNAME", "deploy_user")
    monkeypatch.setenv("FTP_PASSWORD", "SecretPass#192")
    monkeypatch.setenv("FTP_APP_ROOT", "/busherianbiasharas.com/www")

    rc = ftps_mod.main(["--mode", "prod", "--files-list", str(files_list), "--port", str(srv.port)])
    srv.join()
    captured = capsys.readouterr()
    assert rc == 1
    assert "CRITICAL FTPS DEPLOY ERROR:" in captured.err

    # Verify --tls-servername still rejects certificates whose SAN does not match tls_servername
    cert_shared, key_shared = _generate_self_signed_cert(
        tmp_path, "angryhosting.com", "angryhosting.com"
    )
    srv_mismatch = _MockExplicitFtpsServer(cert_shared, key_shared)
    srv_mismatch.start()
    with pytest.raises(ssl.SSLCertVerificationError):
        ftps_mod.connect_verified_ftps(
            host="127.0.0.1",
            user="deploy_user",
            password="SecretPass#192",
            port=srv_mismatch.port,
            timeout=5,
            cafile=str(cert_shared),
            tls_servername="attacker-host.example.invalid",
        )
    srv_mismatch.join()
