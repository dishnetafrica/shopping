"""
Offline license verification. A license is a JSON payload signed (detached Ed25519) by ShopBot's
PRIVATE key; the box carries only ShopBot's PUBLIC key and verifies here — no server round-trip, so a
temporary outage never stops operation (grace, see grace.py).

Verification is layered, matching the approved identity model:
  1. signature valid against ShopBot's public key            → else INVALID_SIG (forged/tampered)
  2. license.device_pubkey == this box's device public key   → else WRONG_DEVICE (bound to another box)
  3. fingerprint quorum matches this box's hardware          → else WRONG_DEVICE (storage copied to new HW)
  4. time window: valid / grace / expired

Clone protection falls out of 2+3 together: a fresh box with a new keypair fails (2); a box that COPIED
storage (same private key) fails (3) because the hardware fingerprint differs. Neither passes both.
"""
from __future__ import annotations

import base64
import json

from . import fingerprint as fp

VALID = "VALID"
GRACE = "GRACE"
EXPIRED = "EXPIRED"
INVALID_SIG = "INVALID_SIG"
WRONG_DEVICE = "WRONG_DEVICE"


def canonical(payload: dict) -> bytes:
    """Deterministic bytes to sign/verify — sorted keys, no whitespace. Both sides must agree; PHP uses
    the same (ksort + JSON_UNESCAPED_SLASHES compact)."""
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


class LicenseResult:
    __slots__ = ("status", "detail", "payload")

    def __init__(self, status, detail="", payload=None):
        self.status = status
        self.detail = detail
        self.payload = payload or {}

    @property
    def ok(self):
        return self.status in (VALID, GRACE)


def verify(payload: dict, signature_b64: str, shopbot_pubkey_b64: str,
           device_pubkey_b64: str, current_fingerprint: list, now: float,
           fp_need: int = 2) -> LicenseResult:
    from nacl.signing import VerifyKey
    from nacl.exceptions import BadSignatureError

    # 1. signature
    try:
        VerifyKey(base64.b64decode(shopbot_pubkey_b64)).verify(
            canonical(payload), base64.b64decode(signature_b64))
    except (BadSignatureError, ValueError, Exception):
        return LicenseResult(INVALID_SIG, "license signature does not verify against ShopBot key")

    # 2. bound to THIS device's keypair
    if payload.get("device_pubkey") != device_pubkey_b64:
        return LicenseResult(WRONG_DEVICE, "license is bound to a different device keypair", payload)

    # 3. hardware fingerprint quorum (additional signal)
    licensed_fp = list(payload.get("fingerprint_quorum") or [])
    if licensed_fp and not fp.quorum_ok(licensed_fp, current_fingerprint, fp_need):
        return LicenseResult(WRONG_DEVICE,
                             "hardware fingerprint quorum failed — storage likely copied to another box",
                             payload)

    # 4. time window
    exp = float(payload.get("expires_at", 0))
    grace = float(payload.get("grace_seconds", 0))
    if now < exp:
        return LicenseResult(VALID, "license valid", payload)
    if now < exp + grace:
        left = int((exp + grace - now))
        return LicenseResult(GRACE, f"license expired; in grace ({left}s left) — reconnect to renew", payload)
    return LicenseResult(EXPIRED, "license expired past grace", payload)
