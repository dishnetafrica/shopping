"""Who is allowed to talk to the box.

The gateway sits on the store LAN holding the recorder's admin password and a live view
of every aisle. Until now every endpoint was open: anyone on the shop's WiFi could list
the cameras, read their snapshots, delete them, and ask `/api/cameras/{id}/test` for the
NVR password in clear text.

HTTP Basic, applied to the whole application in one place. The reason for app-level
rather than per-route is not brevity — it is that a route added next month is protected
without anyone remembering to protect it. tests/test_security.py walks the live route
table and fails if any route answers without credentials.

Basic over plain HTTP on a LAN is not strong; it is proportionate. The box has no TLS
and an installer needs a browser prompt, not a token flow. It closes the opportunistic
attacker on the shop WiFi, which is the actual threat here. Anything better needs
certificates the fleet cannot yet manage.
"""
from __future__ import annotations

import logging
import os
import secrets

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPBasic, HTTPBasicCredentials

log = logging.getLogger("auth")

_scheme = HTTPBasic(auto_error=False)


def admin_user() -> str:
    return os.environ.get("ADMIN_USER", "admin").strip() or "admin"


def admin_password() -> str:
    return os.environ.get("ADMIN_PASSWORD", "").strip()


def auth_disabled() -> bool:
    return os.environ.get("ALLOW_NO_AUTH", "0").strip() in ("1", "true", "TRUE", "yes")


def config_problems() -> tuple[list[str], list[str]]:
    """(fatal, warnings) — is this box safe to put on a shop's network?"""
    fatal: list[str] = []
    warn: list[str] = []

    if auth_disabled():
        warn.append(
            "ALLOW_NO_AUTH=1 — EVERY endpoint is open. Anyone on this network can view "
            "the shop's cameras and read the recorder password. Development only."
        )
        return fatal, warn

    pw = admin_password()
    if not pw:
        fatal.append(
            "ADMIN_PASSWORD is empty. The dashboard and API would be open to everyone on "
            "the store network, including the recorder credentials. install.sh generates "
            "one; set ALLOW_NO_AUTH=1 only for local development."
        )
    elif len(pw) < 12:
        warn.append(f"ADMIN_PASSWORD is only {len(pw)} characters — this is on a shared network.")

    return fatal, warn


def require_auth(creds: HTTPBasicCredentials | None = Depends(_scheme)) -> str:
    """Applied to every route. Returns the authenticated username."""
    if auth_disabled():
        return "anonymous"

    expected_pw = admin_password()
    if not expected_pw:
        # Startup refuses this state; if it is somehow reached, fail CLOSED rather than
        # treating "no password configured" as "no password required".
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "gateway not configured")

    if creds is None:
        raise HTTPException(
            status.HTTP_401_UNAUTHORIZED, "authentication required",
            headers={"WWW-Authenticate": 'Basic realm="CloudBSS Vision"'},
        )

    # compare_digest on both halves, always both, so the reply time says nothing about
    # which half was wrong.
    user_ok = secrets.compare_digest(creds.username or "", admin_user())
    pw_ok = secrets.compare_digest(creds.password or "", expected_pw)
    if not (user_ok and pw_ok):
        log.warning("rejected a request with bad credentials (user %r)", creds.username)
        raise HTTPException(
            status.HTTP_401_UNAUTHORIZED, "invalid credentials",
            headers={"WWW-Authenticate": 'Basic realm="CloudBSS Vision"'},
        )
    return creds.username
