"""
First-boot enrollment client (edge side) — wires the device keypair + fingerprint into the EXISTING
`/api/vision/box/hello` + `/poll` flow. No new protocol; this is the box half of what
VisionBoxController already serves.

On first boot it:
  1. generates the device Ed25519 keypair (private key → /data/device_key, 0600) if absent,
  2. invents a box secret (→ /data/box_secret, 0600) if absent,
  3. computes the layered hardware fingerprint,
  4. POST /hello {box_uid, secret, device_pubkey, fingerprint, hw_model, fw_version} → prints the claim
     code for the operator to run `php artisan vision:claim CODE --tenant=<dev> --type=development`,
  5. polls /poll until claimed → stores the signed license (→ /data/license.json) and the per-box
     webhook_token (→ /data/credential, 0600), then verifies the license OFFLINE and reports.

Contains NO secret: only the single-use claim code (entered by the operator on the cloud) authorises the
box. `requests` is imported lazily so importing this module never needs the network.
"""
from __future__ import annotations

import json
import os
import time

from . import fingerprint, gate
from . import license as lic
from .identity import DeviceIdentity


def _data() -> str:
    return os.environ.get("VISION_DATA", "/data")


def _write_600(path: str, data: bytes) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "wb") as fh:
        fh.write(data)


def _box_uid() -> str:
    for p in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
        try:
            with open(p) as fh:
                v = fh.read().strip()
                if v:
                    return v
        except OSError:
            continue
    return "box-" + os.urandom(8).hex()


def _box_secret() -> str:
    path = os.path.join(_data(), "box_secret")
    if os.path.exists(path):
        with open(path) as fh:
            return fh.read().strip()
    secret = os.urandom(24).hex()
    os.makedirs(_data(), exist_ok=True)
    _write_600(path, secret.encode())
    return secret


def enroll(cloud_url: str, hw_model: str = "", fw_version: str = "", poll_timeout_s: float = 600.0) -> dict:
    import requests  # lazy

    base = cloud_url.rstrip("/")
    dev = DeviceIdentity(os.path.join(_data(), "device_key")).load_or_create()
    payload = {
        "box_uid": _box_uid(),
        "secret": _box_secret(),
        "device_pubkey": dev.public_key_b64(),
        "fingerprint": fingerprint.fingerprint(),
        "hw_model": hw_model,
        "fw_version": fw_version,
    }
    r = requests.post(base + "/api/vision/box/hello", json=payload, timeout=15)
    r.raise_for_status()
    hello = r.json()
    if hello.get("status") == "pending":
        print(f"CLAIM CODE: {hello.get('claim_code')}  — on ShopBot Cloud run: "
              f"php artisan vision:claim {hello.get('claim_code')} --tenant=<DEV> --type=development")

    started = time.time()
    while time.time() - started < poll_timeout_s:
        r = requests.post(base + "/api/vision/box/poll",
                          json={"box_uid": payload["box_uid"], "secret": payload["secret"]}, timeout=15)
        r.raise_for_status()
        doc = r.json()
        if doc.get("status") == "claimed":
            _store_claim(doc)
            return _verify_and_report()
        time.sleep(float(doc.get("poll_seconds", 10)))
    raise TimeoutError("box was not claimed in time — run vision:claim on ShopBot Cloud, then retry")


def _store_claim(doc: dict) -> None:
    os.makedirs(_data(), exist_ok=True)
    if doc.get("license"):
        with open(os.path.join(_data(), "license.json"), "w", encoding="utf-8") as fh:
            json.dump(doc["license"], fh)
    if doc.get("webhook_token"):
        _write_600(os.path.join(_data(), "credential"), doc["webhook_token"].encode())


def _verify_and_report() -> dict:
    res = gate.evaluate(time.time())
    print(f"LICENSE: {res.status} ({res.payload.get('license_type', '?')}) — {res.detail}")
    return {"status": res.status, "ok": res.ok, "license_type": res.payload.get("license_type")}


def main(argv):
    import argparse
    ap = argparse.ArgumentParser(description="ShopBot Vision first-boot enrollment")
    ap.add_argument("--cloud", default=os.environ.get("SHOPBOT_CLOUD_URL", ""), help="ShopBot Cloud base URL")
    ap.add_argument("--hw-model", default=os.environ.get("HW_MODEL", ""))
    ap.add_argument("--fw-version", default=os.environ.get("SHOPBOT_VISION_VERSION", ""))
    args = ap.parse_args(argv)
    if not args.cloud:
        ap.error("--cloud (or SHOPBOT_CLOUD_URL) is required")
    out = enroll(args.cloud, args.hw_model, args.fw_version)
    return 0 if out["ok"] else 1


if __name__ == "__main__":
    import sys
    raise SystemExit(main(sys.argv[1:]))
