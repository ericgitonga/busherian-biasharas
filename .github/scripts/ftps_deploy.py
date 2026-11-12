#!/usr/bin/env python3
"""Hardened Explicit FTPS deployment helper (#170, #192).

Enforces strict X.509 CA chain validation and hostname verification (TLS 1.2+)
on BOTH the FTPS control channel (`AUTH TLS`) and passive data transfer sockets (`PROT P`),
aborting immediately on any certificate/hostname error before credentials or files are exposed.
"""

from __future__ import annotations

import argparse
import ftplib
import os
import pathlib
import posixpath
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
    if norm in ("public/uploads/.htaccess", "uploads/.htaccess"):
        return norm
    base = posixpath.basename(norm)
    if base == ".env" or any(norm == p.rstrip("/") or norm.startswith(p) for p in FORBIDDEN_PREFIXES):
        raise ValueError(f"Forbidden runtime/secret path rejected from FTPS deploy: {norm}")
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


def deploy_items(
    items: Iterable[tuple[pathlib.Path, str]],
    *,
    host: str,
    user: str,
    password: str,
    app_root: str,
    mode: str,
    port: int = 21,
    cafile: str | None = None,
    tls_servername: str | None = None,
) -> int:
    materialized = [(lp, map_remote_path(rel, app_root, mode), rel) for lp, rel in items]
    if not materialized:
        print("No pertinent files changed; skipping FTPS upload.")
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
    try:
        for local_path, remote_full, rel_label in materialized:
            remote_dir = posixpath.dirname(remote_full)
            remote_filename = posixpath.basename(remote_full)
            ensure_remote_dir(remote_dir)
            with local_path.open("rb") as lf:
                ftp.storbinary(f"STOR {remote_filename}", lf)
            print(f"Uploaded: {rel_label} -> {remote_full}")
            uploaded += 1
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

    print(f"Successfully uploaded {uploaded} file(s) via certificate-verified Explicit FTPS ({mode}).")
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


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Deploy files over certificate-verified Explicit FTPS.")
    parser.add_argument("--mode", choices=("prod", "preprod"), required=True)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--files-list", help="Newline-delimited file of repo-relative paths to upload")
    group.add_argument("--stage-dir", help="Directory containing staged files to upload")
    parser.add_argument("--port", type=int, default=int(os.environ.get("FTP_PORT", "21")))
    parser.add_argument(
        "--tls-servername",
        default=os.environ.get("FTP_TLS_SERVERNAME", "").strip() or None,
        help="Expected X.509 SAN hostname when connecting to shared-hosting FTPS via vanity domain/IP",
    )
    args = parser.parse_args(argv)

    items = collect_items_from_args(args)
    if not items:
        print("No pertinent files changed; skipping FTPS upload.")
        return 0

    host = os.environ["FTP_SERVER"]
    user = os.environ["FTP_USERNAME"]
    password = os.environ["FTP_PASSWORD"]
    app_root = (os.environ.get("FTP_APP_ROOT") or "/busherianbiasharas.com/www").rstrip("/")

    try:
        deploy_items(
            items,
            host=host,
            user=user,
            password=password,
            app_root=app_root,
            mode=args.mode,
            port=args.port,
            tls_servername=args.tls_servername,
        )
    except (ssl.SSLError, socket.error, *ftplib.all_errors, ValueError) as exc:
        print(f"CRITICAL FTPS DEPLOY ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
