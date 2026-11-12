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


def test_ftps_deploy_atomic_staging_manifest_and_deleted_files(tmp_path: Path, monkeypatch):
    ftps_mod = _load_ftps_deploy_module()
    import ftplib
    import json

    # 1. Protected paths MUST be blocked from deletion even if passed to validate_deletable_deploy_path
    for protected_rel in [".env", ".env.production", "storage/app.sqlite", "public/uploads/photo.jpg", "public/uploads/.htaccess"]:
        with pytest.raises(ValueError):
            ftps_mod.validate_deletable_deploy_path(protected_rel)
    assert ftps_mod.validate_deletable_deploy_path("public/retired-endpoint.php") == "public/retired-endpoint.php"

    # 2. Mock VerifiedFTPTLS session verifying STOR .bbiz_stage_* -> SIZE -> RNFR/RNTO + retired file deletion + .deploy_manifest.json
    class _FakeFtpClient:
        def __init__(self, fail_size_mismatch: bool = False):
            self.cwd_dir = "/"
            self.files: dict[str, bytes] = {
                "/busherianbiasharas.com/www/html/index.php": b"OLD_LIVE_INDEX",
                "/busherianbiasharas.com/www/html/old-legacy.php": b"LEGACY_TO_DELETE",
            }
            self.dirs: set[str] = {"/", "/busherianbiasharas.com", "/busherianbiasharas.com/www", "/busherianbiasharas.com/www/html"}
            self.ops: list[tuple[str, str]] = []
            self.fail_size_mismatch = fail_size_mismatch

        def voidcmd(self, cmd: str) -> str:
            self.ops.append(("VOID", cmd))
            return "200 OK"

        def cwd(self, d: str) -> None:
            if d not in self.dirs:
                raise ftplib.error_perm("550 Directory not found")
            self.cwd_dir = d

        def mkd(self, d: str) -> str:
            self.dirs.add(d)
            return d

        def storbinary(self, cmd: str, fp) -> str:
            assert cmd.startswith("STOR ")
            fname = cmd[5:]
            assert fname.startswith(".bbiz_stage_"), f"Direct non-staged overwrite attempted: {fname}"
            full = f"{self.cwd_dir.rstrip('/')}/{fname}"
            self.files[full] = fp.read()
            self.ops.append(("STOR", full))
            return "226 Transfer complete"

        def size(self, fname: str) -> int:
            full = f"{self.cwd_dir.rstrip('/')}/{fname}"
            actual = len(self.files[full])
            return actual - 1 if self.fail_size_mismatch else actual

        def rename(self, src_name: str, dst_name: str) -> str:
            src_full = f"{self.cwd_dir.rstrip('/')}/{src_name}"
            dst_full = f"{self.cwd_dir.rstrip('/')}/{dst_name}"
            self.files[dst_full] = self.files.pop(src_full)
            self.ops.append(("RENAME", f"{src_full} -> {dst_full}"))
            return "250 Renamed"

        def delete(self, path_or_name: str) -> str:
            full = path_or_name if path_or_name.startswith("/") else f"{self.cwd_dir.rstrip('/')}/{path_or_name}"
            if full not in self.files:
                raise ftplib.error_perm("550 No such file")
            del self.files[full]
            self.ops.append(("DELE", full))
            return "250 Deleted"

        def quit(self) -> str:
            return "221 Goodbye"

        def close(self) -> None:
            pass

    local_index = tmp_path / "index.php"
    local_index.write_bytes(b"<?php echo 'NEW_ATOMIC_V15';\n")

    # 2a. Size mismatch during upload MUST delete staged temp file and leave live index.php untouched!
    bad_ftp = _FakeFtpClient(fail_size_mismatch=True)
    monkeypatch.setattr(ftps_mod, "connect_verified_ftps", lambda *a, **kw: bad_ftp)
    with pytest.raises(ValueError, match="size mismatch"):
        ftps_mod.deploy_items(
            [(local_index, "public/index.php")],
            host="ftp.example.test",
            user="u",
            password="p",
            app_root="/busherianbiasharas.com/www",
            mode="prod",
            release_sha="deadbeef181",
        )
    assert bad_ftp.files["/busherianbiasharas.com/www/html/index.php"] == b"OLD_LIVE_INDEX"
    assert not any(".bbiz_stage_" in k for k in bad_ftp.files)

    # 2b. Healthy atomic promotion + retired file deletion (including idempotent missing file) + .deploy_manifest.json
    good_ftp = _FakeFtpClient(fail_size_mismatch=False)
    monkeypatch.setattr(ftps_mod, "connect_verified_ftps", lambda *a, **kw: good_ftp)
    promoted = ftps_mod.deploy_items(
        [(local_index, "public/index.php")],
        deleted_rel_paths=["public/old-legacy.php", "public/already-gone.php"],
        host="ftp.example.test",
        user="u",
        password="p",
        app_root="/busherianbiasharas.com/www",
        mode="prod",
        release_sha="cafebabe181",
    )
    assert promoted == 1
    assert good_ftp.files["/busherianbiasharas.com/www/html/index.php"] == b"<?php echo 'NEW_ATOMIC_V15';\n"
    assert "/busherianbiasharas.com/www/html/old-legacy.php" not in good_ftp.files
    manifest_bytes = good_ftp.files.get("/busherianbiasharas.com/www/.deploy_manifest.json")
    assert manifest_bytes is not None
    manifest = json.loads(manifest_bytes.decode("utf-8"))
    assert manifest["release_sha"] == "cafebabe181"
    assert manifest["uploaded_count"] == 1
    assert manifest["deleted_count"] == 1



def test_ftps_deploy_two_phase_staging_rename_retire_and_strict_size(tmp_path: Path, monkeypatch):
    """R2-MED-11 (#219): Whole-release 2-phase staging, .maintenance_deploy.json gate, R* rename retirement, and strict SIZE check."""
    ftps_mod = _load_ftps_deploy_module()
    import ftplib

    # 1. git diff -M --name-status parser retires both D and R* source paths while ignoring uploads/
    diff_sample = (
        "M\tpublic/index.php\n"
        "D\tpublic/legacy-old.php\n"
        "R100\tpublic/old-handler.php\tpublic/new-handler.php\n"
        "R092\tpublic/uploads/biz1/a.jpg\tpublic/uploads/biz1/b.jpg\n"
    )
    retired = ftps_mod.extract_retired_paths_from_name_status(diff_sample)
    assert retired == ["public/legacy-old.php", "public/old-handler.php"]

    # 2. Multi-file Phase 1 failure on file #2 MUST leave live file #1 untouched and remove file #1's .bbiz_stage_*
    class _PhaseFtpClient:
        def __init__(self, fail_second_size: bool = False, size_unsupported: bool = False):
            self.cwd_dir = "/"
            self.files: dict[str, bytes] = {
                "/busherianbiasharas.com/www/html/first.php": b"LIVE_FIRST_V1",
                "/busherianbiasharas.com/www/html/second.php": b"LIVE_SECOND_V1",
            }
            self.dirs: set[str] = {
                "/",
                "/busherianbiasharas.com",
                "/busherianbiasharas.com/www",
                "/busherianbiasharas.com/www/html",
            }
            self.saw_maintenance_gate = False
            self.fail_second_size = fail_second_size
            self.size_unsupported = size_unsupported

        def voidcmd(self, cmd: str) -> str:
            return "200 OK"

        def cwd(self, d: str) -> None:
            if d not in self.dirs:
                raise ftplib.error_perm("550 Directory not found")
            self.cwd_dir = d

        def mkd(self, d: str) -> str:
            self.dirs.add(d)
            return d

        def storbinary(self, cmd: str, fp) -> str:
            fname = cmd[5:]
            full = f"{self.cwd_dir.rstrip('/')}/{fname}"
            self.files[full] = fp.read()
            return "226 OK"

        def size(self, fname: str) -> int:
            if self.size_unsupported:
                raise ftplib.error_perm("502 Command SIZE not implemented")
            full = f"{self.cwd_dir.rstrip('/')}/{fname}"
            actual = len(self.files[full])
            if self.fail_second_size and fname.endswith("_second.php"):
                return actual + 99
            return actual

        def rename(self, src_name: str, dst_name: str) -> str:
            src_full = f"{self.cwd_dir.rstrip('/')}/{src_name}"
            dst_full = f"{self.cwd_dir.rstrip('/')}/{dst_name}"
            if src_full not in self.files:
                raise ftplib.error_perm("550 Missing source")
            if dst_name == ".maintenance_deploy.json":
                self.saw_maintenance_gate = True
            self.files[dst_full] = self.files.pop(src_full)
            return "250 Renamed"

        def delete(self, path_or_name: str) -> str:
            full = path_or_name if path_or_name.startswith("/") else f"{self.cwd_dir.rstrip('/')}/{path_or_name}"
            if full not in self.files:
                raise ftplib.error_perm("550 No such file")
            del self.files[full]
            return "250 Deleted"

        def quit(self) -> str:
            return "221 Bye"

        def close(self) -> None:
            pass

    f1 = tmp_path / "first.php"
    f2 = tmp_path / "second.php"
    f1.write_bytes(b"<?php echo 'FIRST_V2';\n")
    f2.write_bytes(b"<?php echo 'SECOND_V2';\n")

    ftp_phase1_fail = _PhaseFtpClient(fail_second_size=True)
    monkeypatch.setattr(ftps_mod, "connect_verified_ftps", lambda *a, **kw: ftp_phase1_fail)
    with pytest.raises(ValueError, match="size mismatch"):
        ftps_mod.deploy_items(
            [(f1, "public/first.php"), (f2, "public/second.php")],
            host="ftp.example.test",
            user="u",
            password="p",
            app_root="/busherianbiasharas.com/www",
            mode="prod",
            release_sha="sha219fail",
        )
    assert ftp_phase1_fail.files["/busherianbiasharas.com/www/html/first.php"] == b"LIVE_FIRST_V1"
    assert ftp_phase1_fail.files["/busherianbiasharas.com/www/html/second.php"] == b"LIVE_SECOND_V1"
    assert not any(".bbiz_stage_" in k for k in ftp_phase1_fail.files)

    # 3. Unverified SIZE (502) refuses promotion by default, and succeeds only with allow_unverified_size=True
    ftp_nosize_strict = _PhaseFtpClient(size_unsupported=True)
    monkeypatch.setattr(ftps_mod, "connect_verified_ftps", lambda *a, **kw: ftp_nosize_strict)
    with pytest.raises(ValueError, match="refusing unverified promotion"):
        ftps_mod.deploy_items(
            [(f1, "public/first.php")],
            host="ftp.example.test",
            user="u",
            password="p",
            app_root="/busherianbiasharas.com/www",
            mode="prod",
            release_sha="sha219strict",
        )

    ftp_good = _PhaseFtpClient(size_unsupported=True)
    monkeypatch.setattr(ftps_mod, "connect_verified_ftps", lambda *a, **kw: ftp_good)
    count = ftps_mod.deploy_items(
        [(f1, "public/first.php"), (f2, "public/second.php")],
        host="ftp.example.test",
        user="u",
        password="p",
        app_root="/busherianbiasharas.com/www",
        mode="prod",
        release_sha="sha219ok",
        allow_unverified_size=True,
    )
    assert count == 2
    assert ftp_good.saw_maintenance_gate is True
    assert "/busherianbiasharas.com/www/.maintenance_deploy.json" not in ftp_good.files
    assert not any(".bbiz_prev_" in k for k in ftp_good.files)
