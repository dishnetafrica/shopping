"""
The startup license gate — plugs into main.py's existing refuse-to-start seam (beside
check_security_config / check_delivery_config), so a box with no valid license does not begin commercial
inference. Additive and OFF by default: enforcement only when VISION_LICENSE_ENFORCE=1, so the existing
shelf/dev paths are unchanged until licensing is switched on. A development license (dev tenant) is a
first-class allowed state — the supplier's box must run.

Reads the cached signed license from /data (written by activation/heartbeat) and verifies it OFFLINE.
"""
from __future__ import annotations

import json
import os

from . import fingerprint, license as lic
from .identity import DeviceIdentity

def _data() -> str:
    return os.environ.get("VISION_DATA", "/data")


def _license_file() -> str:
    return os.path.join(_data(), "license.json")


def _device_key() -> str:
    return os.path.join(_data(), "device_key")


def _enforce() -> bool:
    return os.environ.get("VISION_LICENSE_ENFORCE", "0").strip() in ("1", "true", "TRUE", "yes")


def _shopbot_pubkey() -> str:
    # Bundled in the image (public key only — never a signing secret). Env override for tests/staging.
    return os.environ.get("SHOPBOT_LICENSE_PUBKEY", "").strip()


def evaluate(now: float) -> lic.LicenseResult:
    """Verify the cached license for this box. Pure-ish (reads /data); returns a LicenseResult."""
    if not os.path.exists(_license_file()):
        return lic.LicenseResult(lic.EXPIRED, "no license cached — activate this box")
    try:
        with open(_license_file(), "r", encoding="utf-8") as fh:
            doc = json.load(fh)
        payload, sig = doc["payload"], doc["signature"]
    except (OSError, ValueError, KeyError) as e:
        return lic.LicenseResult(lic.INVALID_SIG, f"license file unreadable: {e}")

    dev = DeviceIdentity(_device_key()).load_or_create()
    return lic.verify(payload, sig, _shopbot_pubkey(), dev.public_key_b64(),
                      fingerprint.fingerprint(), now)


def check_license() -> None:
    """Called at startup. Raises RuntimeError (refuse to start) only when enforcement is ON and the
    license is not valid/grace. Logs the state either way."""
    import logging
    import time

    log = logging.getLogger("licensing")
    if not _enforce():
        log.info("LICENSE: enforcement off (VISION_LICENSE_ENFORCE unset) — not gating inference")
        return
    if not _shopbot_pubkey():
        raise RuntimeError("refusing to start — VISION_LICENSE_ENFORCE=1 but no SHOPBOT_LICENSE_PUBKEY bundled")

    res = evaluate(time.time())
    lt = res.payload.get("license_type", "?")
    if res.status == lic.GRACE:
        log.warning("LICENSE: %s (%s) — operating in GRACE: %s", res.status, lt, res.detail)
        return
    if res.ok:
        log.info("LICENSE: %s (%s) — %s", res.status, lt, res.detail)
        return
    raise RuntimeError(f"refusing to start commercial inference — license {res.status}: {res.detail}")
