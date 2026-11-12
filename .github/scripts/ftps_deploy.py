#!/usr/bin/env python3
"""Hardened Explicit FTPS deployment helper (#170, #181, #192).

Enforces:
1. Strict X.509 CA chain validation and hostname verification (TLS 1.2+)
   on BOTH the FTPS control channel (`AUTH TLS`) and passive data sockets (`PROT P`).
2. Atomic per-file staged uploads (`.bbiz_stage_<pid>_<rand>_<name>`) with remote byte-size
   verification (`SIZE`) and atomic `RNFR`/`RNTO` promotion so live PHP files are never
   served half-written during deploys (#181).
3. Safe retirement of deleted tracked files (`--deleted-files-list`) while strictly
   protecting `.env`, `storage/`, and `public/uploads/` (including `.htaccess`) (#181).
4. Post-deploy `.deploy_manifest.json` release stamp (#181).
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import ftplib
import io
import json
import os
import pathlib
import posixpath
import secrets
import socket
import ssl
import sys
from typing import Iterable

FORBIDDEN_PREFIXES = (
    ".env",
    "storage/",
    "uploads/",
    "public/uploads/",
    ".git/",
)

PROTECTED_FROM_DELETE = (
    "public/uploads/.htaccess",
    "uploads/.htaccess",
)

DEFAULT_INTERMEDIATES_PEM = pathlib.Path(__file__).with_name("letsencrypt_geny_intermediates.pem")


def build_verified_tls_context(cafile: str | None = None) -> ssl.SSLContext:
    """Create a strict TLS client context with CA chain + hostname verification required."""
    resolved_ca = cafile or os.environ.get("FTP_CA_BUNDLE") or None
    ctx = ssl.create_default_context(ssl.Purpose.SERVER_AUTH, cafile=resolved_ca)
    if resolved_ca is None and DEFAULT_INTERMEDIATES_PEM.is_file():
        # Supply non-root Let's Encrypt Gen-Y intermediates for FTPS daemons that omit chain stapling;
        # trust anchors remain exclusively the OS root CAs (ISRG Root X1 / X2).
        ctx.load_verify_locations(cafile=str(DEFAULT_INTERMEDIATES_PEM))
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.check_hostname = True
    ctx.verify_mode = ssl.CERT_REQUIRED
    return ctx


class VerifiedFTPTLS(ftplib.FTP_TLS):
    """Explicit FTPS client enforcing certificate & hostname verification on control + data sockets."""

    def __init__(
        self,
        *args,
        cafile: str | None = None,
        tls_servername: str | None = None,
        **kwargs,
    ):
        kwargs.setdefault("context", build_verified_tls_context(cafile))
        super().__init__(*args, **kwargs)
        self.tls_servername = (tls_servername or "").strip() or None

    def _effective_tls_servername(self) -> str:
        target = self.tls_servername or self.host
        if not target:
            raise ValueError("FTPS hostname is required for TLS hostname verification.")
        return target

    def auth(self) -> str:
        servername = self._effective_tls_servername()
        if isinstance(self.sock, ssl.SSLSocket):
            return "234 Already TLS"
        if self.context.verify_mode != ssl.CERT_REQUIRED or not self.context.check_hostname:
            raise ssl.SSLError("Refusing FTPS connection without CERT_REQUIRED and check_hostname=True.")
        resp = self.voidcmd("AUTH TLS")
        self.sock = self.context.wrap_socket(self.sock, server_hostname=servername)
        self.file = self.sock.makefile(mode="r", encoding=self.encoding)
        return resp

    def ntransfercmd(self, cmd: str, rest: int | None = None):
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            try:
                servername = self._effective_tls_servername()
            except ValueError:
                conn.close()
                raise
            conn = self.context.wrap_socket(conn, server_hostname=servername)
        return conn, size


def validate_relative_deploy_path(rel_path: str) -> str:
    norm = rel_path.strip().replace("\\", "/").lstrip("/")
    if not norm or norm.startswith("../") or "/../" in f"/{norm}/":
        raise ValueError(f"Unsafe relative path rejected: {rel_path!r}")
    if norm in PROTECTED_FROM_DELETE:
        return norm
    base = posixpath.basename(norm)
    if base == ".env" or base.startswith(".env.") or any(norm == p.rstrip("/") or norm.startswith(p) for p in FORBIDDEN_PREFIXES):
        raise ValueError(f"Forbidden runtime/secret path rejected from FTPS deploy: {norm}")
    return norm


def validate_deletable_deploy_path(rel_path: str) -> str:
    norm = validate_relative_deploy_path(rel_path)
    if norm in PROTECTED_FROM_DELETE:
        raise ValueError(f"Protected security file cannot be deleted via FTPS deploy: {norm}")
    return norm


def map_remote_path(rel_path: str, app_root: str, mode: str) -> str:
    norm = validate_relative_deploy_path(rel_path)
    clean_root = (app_root or "/busherianbiasharas.com/www").rstrip("/")
    if mode == "prod":
        if norm.startswith("public/"):
            sub = norm[len("public/"):]
            return f"{clean_root}/html/{sub}"
        return f"{clean_root}/{norm}"
    if mode == "preprod":
        if norm.startswith("public/"):
            sub = norm[len("public/"):]
            return f"{clean_root}/html/admin/preprod/{sub}"
        return f"{clean_root}/preprod_app/{norm}"
    raise ValueError(f"Unsupported deploy mode: {mode!r}")


def map_remote_delete_path(rel_path: str, app_root: str, mode: str) -> str:
    validate_deletable_deploy_path(rel_path)
    return map_remote_path(rel_path, app_root, mode)


def manifest_remote_path(app_root: str, mode: str) -> str:
    clean_root = (app_root or "/busherianbiasharas.com/www").rstrip("/")
    if mode == "prod":
        return f"{clean_root}/.deploy_manifest.json"
    if mode == "preprod":
        return f"{clean_root}/preprod_app/.deploy_manifest.json"
    raise ValueError(f"Unsupported deploy mode: {mode!r}")


def connect_verified_ftps(
    host: str,
    user: str,
    password: str,
    port: int = 21,
    timeout: float = 30.0,
    cafile: str | None = None,
    tls_servername: str | None = None,
) -> VerifiedFTPTLS:
    clean_host = (host or "").strip()
    if not clean_host:
        raise ValueError("FTP_SERVER is empty.")
    ftp = VerifiedFTPTLS(cafile=cafile, tls_servername=tls_servername)
    ftp.connect(clean_host, port, timeout=timeout)
    # Explicitly negotiate & verify TLS on control socket BEFORE sending USER/PASS credentials!
    ftp.auth()
    ftp.login(user, password)
    ftp.prot_p()
    return ftp


def _atomic_upload_bytes(
    ftp: ftplib.FTP,
    ensure_remote_dir_fn,
    remote_full: str,
    payload_bytes: bytes,
    label: str,
) -> None:
    remote_dir = posixpath.dirname(remote_full)
    remote_filename = posixpath.basename(remote_full)
    ensure_remote_dir_fn(remote_dir)

    stage_name = f".bbiz_stage_{os.getpid()}_{secrets.token_hex(4)}_{remote_filename}"
    expected_size = len(payload_bytes)
    try:
        ftp.storbinary(f"STOR {stage_name}", io.BytesIO(payload_bytes))
        try:
            remote_size = ftp.size(stage_name)
        except ftplib.error_perm as sz_err:
            # 500/502 when SIZE is unsupported by a minimal daemon; any other perm error is raised
            msg = str(sz_err)
            if not (msg.startswith("500") or msg.startswith("502")):
                raise
            remote_size = None

        if remote_size is not None and int(remote_size) != expected_size:
            raise ValueError(
                f"Staged FTPS upload size mismatch for {label}: expected {expected_size} bytes, got {remote_size}"
            )

        ftp.rename(stage_name, remote_filename)
    except Exception:
        try:
            ftp.delete(stage_name)
        except Exception:
            pass
        raise


def deploy_items(
    items: Iterable[tuple[pathlib.Path, str]],
    *,
    deleted_rel_paths: Iterable[str] = (),
    host: str,
    user: str,
    password: str,
    app_root: str,
    mode: str,
    port: int = 21,
    cafile: str | None = None,
    tls_servername: str | None = None,
    release_sha: str | None = None,
    write_manifest: bool = True,
) -> int:
    materialized = [(lp, map_remote_path(rel, app_root, mode), rel) for lp, rel in items]
    deleted_targets = [(map_remote_delete_path(rel, app_root, mode), rel) for rel in deleted_rel_paths]

    if not materialized and not deleted_targets:
        print("No pertinent files changed or retired; skipping FTPS session.")
        return 0

    ftp = connect_verified_ftps(
        host,
        user,
        password,
        port=port,
        cafile=cafile,
        tls_servername=tls_servername,
    )
    ensured_dirs: set[str] = set()

    def ensure_remote_dir(remote_dir: str) -> None:
        if remote_dir in ensured_dirs:
            ftp.cwd(remote_dir)
            return
        parts = [p for p in remote_dir.split("/") if p]
        curr = ""
        for part in parts:
            curr = f"{curr}/{part}"
            if curr not in ensured_dirs:
                try:
                    ftp.cwd(curr)
                except ftplib.error_perm:
                    ftp.mkd(curr)
                    ftp.cwd(curr)
                ensured_dirs.add(curr)
        ftp.cwd(remote_dir)

    uploaded = 0
    deleted = 0
    try:
        try:
            ftp.voidcmd("TYPE I")
        except Exception:
            pass

        for local_path, remote_full, rel_label in materialized:
            data = local_path.read_bytes()
            _atomic_upload_bytes(ftp, ensure_remote_dir, remote_full, data, rel_label)
            print(f"Uploaded (atomic stage->rename): {rel_label} -> {remote_full} ({len(data)}B)")
            uploaded += 1

        for remote_del, rel_del in deleted_targets:
            try:
                ftp.delete(remote_del)
                print(f"Retired deleted file: {rel_del} -> {remote_del}")
                deleted += 1
            except ftplib.error_perm as exc:
                if str(exc).startswith("550"):
                    print(f"Retired file already absent (idempotent): {rel_del} -> {remote_del}")
                else:
                    raise

        if write_manifest:
            manifest_payload = json.dumps(
                {
                    "mode": mode,
                    "release_sha": (release_sha or os.environ.get("GITHUB_SHA") or "unknown").strip(),
                    "deployed_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                    "uploaded_count": uploaded,
                    "deleted_count": deleted,
                },
                indent=2,
            ).encode("utf-8")
            m_path = manifest_remote_path(app_root, mode)
            _atomic_upload_bytes(ftp, ensure_remote_dir, m_path, manifest_payload, ".deploy_manifest.json")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

    print(
        f"Successfully promoted {uploaded} file(s) and retired {deleted} deleted file(s) "
        f"via atomic Explicit FTPS ({mode})."
    )
    return uploaded


def collect_items_from_args(args: argparse.Namespace) -> list[tuple[pathlib.Path, str]]:
    if args.files_list:
        list_path = pathlib.Path(args.files_list)
        if not list_path.is_file():
            return []
        items: list[tuple[pathlib.Path, str]] = []
        for raw_line in list_path.read_text(encoding="utf-8").splitlines():
            rel = raw_line.strip()
            if not rel:
                continue
            lp = pathlib.Path(rel)
            if lp.is_file():
                items.append((lp, rel))
        return items

    if args.stage_dir:
        stage = pathlib.Path(args.stage_dir)
        if not stage.is_dir():
            raise FileNotFoundError(f"Stage directory does not exist: {stage}")
        return [
            (p, p.relative_to(stage).as_posix())
            for p in sorted(stage.rglob("*"))
            if p.is_file()
        ]

    raise ValueError("Either --files-list or --stage-dir must be provided.")


def collect_deleted_from_args(args: argparse.Namespace) -> list[str]:
    if not getattr(args, "deleted_files_list", None):
        return []
    del_path = pathlib.Path(args.deleted_files_list)
    if not del_path.is_file():
        return []
    seen: set[str] = set()
    deleted: list[str] = []
    for raw_line in del_path.read_text(encoding="utf-8").splitlines():
        rel = raw_line.strip()
        if not rel or rel in seen:
            continue
        seen.add(rel)
        deleted.append(rel)
    return deleted


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Deploy files over certificate-verified Explicit FTPS.")
    parser.add_argument("--mode", choices=("prod", "preprod"), required=True)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--files-list", help="Newline-delimited file of repo-relative paths to upload")
    group.add_argument("--stage-dir", help="Directory containing staged files to upload")
    parser.add_argument(
        "--deleted-files-list",
        help="Optional newline-delimited file of retired repo-relative paths to delete remotely",
    )
    parser.add_argument(
        "--release-sha",
        default=os.environ.get("GITHUB_SHA", "").strip() or None,
        help="Git commit SHA recorded in .deploy_manifest.json",
    )
    parser.add_argument("--port", type=int, default=int(os.environ.get("FTP_PORT", "21")))
    parser.add_argument(
        "--tls-servername",
        default=os.environ.get("FTP_TLS_SERVERNAME", "").strip() or None,
        help="Expected X.509 SAN hostname when connecting to shared-hosting FTPS via vanity domain/IP",
    )
    args = parser.parse_args(argv)

    items = collect_items_from_args(args)
    deleted_rels = collect_deleted_from_args(args)
    if not items and not deleted_rels:
        print("No pertinent files changed or retired; skipping FTPS upload.")
        return 0

    host = os.environ["FTP_SERVER"]
    user = os.environ["FTP_USERNAME"]
    password = os.environ["FTP_PASSWORD"]
    app_root = (os.environ.get("FTP_APP_ROOT") or "/busherianbiasharas.com/www").rstrip("/")

    try:
        deploy_items(
            items,
            deleted_rel_paths=deleted_rels,
            host=host,
            user=user,
            password=password,
            app_root=app_root,
            mode=args.mode,
            port=args.port,
            tls_servername=args.tls_servername,
            release_sha=args.release_sha,
        )
    except (ssl.SSLError, socket.error, *ftplib.all_errors, ValueError) as exc:
        print(f"CRITICAL FTPS DEPLOY ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
