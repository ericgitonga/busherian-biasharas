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
import time
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


def maintenance_gate_remote_path(app_root: str, mode: str) -> str:
    clean_root = (app_root or "/busherianbiasharas.com/www").rstrip("/")
    if mode == "prod":
        return f"{clean_root}/.maintenance_deploy.json"
    if mode == "preprod":
        return f"{clean_root}/preprod_app/.maintenance_deploy.json"
    raise ValueError(f"Unsupported deploy mode: {mode!r}")


def extract_retired_paths_from_name_status(diff_text: str) -> list[str]:
    """Extract deleted (`D`) AND rename-source (`R*`) repo-relative paths from `git diff -M --name-status` (#219 M-11)."""
    seen: set[str] = set()
    retired: list[str] = []
    for raw_line in diff_text.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        parts = line.split("\t")
        status = parts[0].strip().upper()
        candidate: str | None = None
        if status == "D" and len(parts) >= 2:
            candidate = parts[1].strip()
        elif status.startswith("R") and len(parts) >= 3:
            old_p = parts[1].strip()
            new_p = parts[2].strip()
            if old_p and old_p != new_p:
                candidate = old_p
        if not candidate:
            continue
        norm = candidate.replace("\\", "/").lstrip("/")
        if norm.startswith("uploads/") or norm.startswith("public/uploads/"):
            continue
        if norm not in seen:
            seen.add(norm)
            retired.append(norm)
    return retired


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


def _stage_and_verify_bytes(
    ftp: ftplib.FTP,
    ensure_remote_dir_fn,
    remote_full: str,
    payload_bytes: bytes,
    label: str,
    *,
    allow_unverified_size: bool = False,
) -> tuple[str, str, str]:
    """Upload bytes to `.bbiz_stage_*` and verify remote SIZE WITHOUT touching the live target yet (#219 M-11)."""
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
            msg = str(sz_err)
            if not (msg.startswith("500") or msg.startswith("502")):
                raise
            remote_size = None

        if remote_size is None:
            if not allow_unverified_size:
                raise ValueError(
                    f"FTPS daemon did not return a verified SIZE for staged file {label}; "
                    f"refusing unverified promotion (pass --allow-unverified-size only if SIZE is unsupported)."
                )
        elif int(remote_size) != expected_size:
            raise ValueError(
                f"Staged FTPS upload size mismatch for {label}: expected {expected_size} bytes, got {remote_size}"
            )

        return (remote_dir, stage_name, remote_filename)
    except Exception:
        try:
            ftp.delete(stage_name)
        except Exception:
            pass
        raise


def _atomic_upload_bytes(
    ftp: ftplib.FTP,
    ensure_remote_dir_fn,
    remote_full: str,
    payload_bytes: bytes,
    label: str,
    *,
    allow_unverified_size: bool = False,
) -> None:
    remote_dir, stage_name, remote_filename = _stage_and_verify_bytes(
        ftp,
        ensure_remote_dir_fn,
        remote_full,
        payload_bytes,
        label,
        allow_unverified_size=allow_unverified_size,
    )
    try:
        ensure_remote_dir_fn(remote_dir)
        ftp.rename(stage_name, remote_filename)
    except Exception:
        try:
            ensure_remote_dir_fn(remote_dir)
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
    allow_unverified_size: bool = False,
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

        effective_sha = (release_sha or os.environ.get("GITHUB_SHA") or "unknown").strip()

        # Phase 1 (#219 M-11): Stage & SIZE-verify the ENTIRE release BEFORE touching any live file!
        staged_plan: list[tuple[str, str, str, str, int]] = []
        try:
            for local_path, remote_full, rel_label in materialized:
                data = local_path.read_bytes()
                r_dir, stg_name, dst_name = _stage_and_verify_bytes(
                    ftp,
                    ensure_remote_dir,
                    remote_full,
                    data,
                    rel_label,
                    allow_unverified_size=allow_unverified_size,
                )
                staged_plan.append((r_dir, stg_name, dst_name, rel_label, len(data)))
        except Exception:
            for r_dir, stg_name, _, _, _ in staged_plan:
                try:
                    ensure_remote_dir(r_dir)
                    ftp.delete(stg_name)
                except Exception:
                    pass
            raise

        # Phase 2 (#219 M-11): Activate brief maintenance gate, promote staged files with backup/rollback, retire old paths
        gate_full = maintenance_gate_remote_path(app_root, mode)
        gate_payload = json.dumps(
            {
                "active": True,
                "mode": mode,
                "release_sha": effective_sha,
                "started_at_ts": int(time.time()),
            }
        ).encode("utf-8")
        _atomic_upload_bytes(
            ftp,
            ensure_remote_dir,
            gate_full,
            gate_payload,
            ".maintenance_deploy.json",
            allow_unverified_size=allow_unverified_size,
        )

        promoted_backups: list[tuple[str, str | None, str]] = []
        consumed_staging: set[tuple[str, str]] = set()
        rollback_failed = False
        try:
            try:
                for idx_promo, (r_dir, stg_name, dst_name, rel_label, byte_len) in enumerate(staged_plan):
                    if idx_promo > 0 and idx_promo % 25 == 0:
                        refreshed_gate = json.dumps(
                            {
                                "active": True,
                                "mode": mode,
                                "release_sha": effective_sha,
                                "started_at_ts": int(time.time()),
                            }
                        ).encode("utf-8")
                        _atomic_upload_bytes(
                            ftp,
                            ensure_remote_dir,
                            gate_full,
                            refreshed_gate,
                            ".maintenance_deploy.json",
                            allow_unverified_size=allow_unverified_size,
                        )
                    ensure_remote_dir(r_dir)
                    backup_name = f".bbiz_prev_{os.getpid()}_{secrets.token_hex(4)}_{dst_name}"
                    has_backup = False
                    try:
                        ftp.rename(dst_name, backup_name)
                        has_backup = True
                    except (ftplib.error_perm, KeyError):
                        has_backup = False

                    # Journal backup BEFORE stg_name -> dst_name rename so mid-promotion failure restores dst_name (#238 H-03)
                    if has_backup:
                        promoted_backups.append((r_dir, backup_name, dst_name))

                    ftp.rename(stg_name, dst_name)
                    consumed_staging.add((r_dir, stg_name))
                    if not has_backup:
                        promoted_backups.append((r_dir, None, dst_name))
                    print(f"Promoted staged file: {rel_label} -> {r_dir}/{dst_name} ({byte_len}B)")
                    uploaded += 1
            except Exception:
                # Roll back every mutated live path from `.bbiz_prev_*` backups & remove all unconsumed `.bbiz_stage_*`
                for r_dir, bkp_name, dst_name in reversed(promoted_backups):
                    try:
                        ensure_remote_dir(r_dir)
                        if bkp_name is not None:
                            try:
                                ftp.delete(dst_name)
                            except Exception:
                                pass
                            ftp.rename(bkp_name, dst_name)
                        else:
                            ftp.delete(dst_name)
                    except Exception as rb_exc:
                        rollback_failed = True
                        print(f"CRITICAL: Rollback restoration failed for {r_dir}/{dst_name}: {rb_exc}", file=sys.stderr)
                for r_dir, stg_name, _, _, _ in staged_plan:
                    if (r_dir, stg_name) in consumed_staging:
                        continue
                    try:
                        ensure_remote_dir(r_dir)
                        ftp.delete(stg_name)
                    except Exception:
                        pass
                if rollback_failed:
                    try:
                        locked_gate = json.dumps(
                            {
                                "active": True,
                                "rollback_failed": True,
                                "mode": mode,
                                "release_sha": effective_sha,
                                "started_at_ts": int(time.time()),
                            }
                        ).encode("utf-8")
                        _atomic_upload_bytes(
                            ftp,
                            ensure_remote_dir,
                            gate_full,
                            locked_gate,
                            ".maintenance_deploy.json",
                            allow_unverified_size=True,
                        )
                    except Exception:
                        pass
                raise

            # Clean up `.bbiz_prev_*` backups now that all files in the release promoted cleanly
            for r_dir, bkp_name, _ in promoted_backups:
                if bkp_name is not None:
                    try:
                        ensure_remote_dir(r_dir)
                        ftp.delete(bkp_name)
                    except Exception:
                        pass

            for remote_del, rel_del in deleted_targets:
                try:
                    ftp.delete(remote_del)
                    print(f"Retired deleted/renamed file: {rel_del} -> {remote_del}")
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
                        "release_sha": effective_sha,
                        "deployed_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                        "uploaded_count": uploaded,
                        "deleted_count": deleted,
                    },
                    indent=2,
                ).encode("utf-8")
                m_path = manifest_remote_path(app_root, mode)
                _atomic_upload_bytes(
                    ftp,
                    ensure_remote_dir,
                    m_path,
                    manifest_payload,
                    ".deploy_manifest.json",
                    allow_unverified_size=allow_unverified_size,
                )
        finally:
            if not rollback_failed:
                try:
                    ftp.delete(gate_full)
                except Exception:
                    pass
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
    group.add_argument(
        "--extract-retired-from-stdin",
        action="store_true",
        help="Parse `git diff -M --name-status` from stdin and print retired (`D` + `R*` old) paths",
    )
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
    parser.add_argument(
        "--allow-unverified-size",
        action="store_true",
        help="Explicitly permit promotion if an FTPS server does not implement the SIZE command",
    )
    args = parser.parse_args(argv)

    if args.extract_retired_from_stdin:
        for rel in extract_retired_paths_from_name_status(sys.stdin.read()):
            print(rel)
        return 0

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
            allow_unverified_size=args.allow_unverified_size,
        )
    except (ssl.SSLError, socket.error, *ftplib.all_errors, ValueError) as exc:
        print(f"CRITICAL FTPS DEPLOY ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
