"""
Edge licensing tests — REAL Ed25519 (PyNaCl, which interoperates with the cloud's PHP libsodium).
Proves the security core in the sandbox: device keypair identity, offline signed-license verification,
grace, tamper rejection, and BOTH clone scenarios (fresh keypair, and storage-copied to new hardware).

The cloud round-trip (activate/heartbeat/revoke against Laravel) and the on-box copy test are only
provable on the sample box + staging — see docs/vision-device-licensing.md.
"""
from __future__ import annotations

import base64
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

pytest.importorskip("nacl", reason="PyNaCl not installed")
from nacl.signing import SigningKey

from app.licensing import fingerprint as fp
from app.licensing import identity, license as lic

NOW = 1_700_000_000        # integer seconds — the signed payload uses ints (cross-lang canonical-safe)
FP = ["machine_id:aaaaaaaaaaaaaaaa", "disk_serial:bbbbbbbbbbbbbbbb", "cpu_serial:cccccccccccccccc"]


def _shopbot():
    sk = SigningKey.generate()
    return sk, base64.b64encode(bytes(sk.verify_key)).decode()


def _sign(sk, payload):
    return base64.b64encode(sk.sign(lic.canonical(payload)).signature).decode()


def _payload(device_pub, fingerprint=FP, ttl=3600, grace=1000, ltype="production"):
    return {
        "device_id": "SB-UG-TEST", "box_uid": "mid", "device_pubkey": device_pub,
        "fingerprint_quorum": fingerprint, "license_type": ltype,
        "runtime_authorized": "v0.1", "model_authorized": "stock_object_v0_1", "tenant": "dev",
        "issued_at": NOW, "expires_at": NOW + ttl, "grace_seconds": grace, "nonce": "abc",
    }


# ── device identity (the primary identity) ────────────────────────────────────────────────────

def test_device_keypair_persists_and_is_0600(tmp_path):
    kp = str(tmp_path / "device_key")
    a = identity.DeviceIdentity(kp).load_or_create()
    b = identity.DeviceIdentity(kp).load_or_create()          # reload
    assert a.public_key_b64() == b.public_key_b64()           # same identity across boots
    assert oct(os.stat(kp).st_mode & 0o777) == "0o600"        # private key locked down


def test_device_signature_proves_possession(tmp_path):
    dev = identity.DeviceIdentity(str(tmp_path / "device_key")).load_or_create()
    challenge = b"activate:SB-UG-TEST:nonce123"
    sig = dev.sign(challenge)
    assert identity.verify_device_signature(dev.public_key_b64(), challenge, sig)
    assert not identity.verify_device_signature(dev.public_key_b64(), b"tampered", sig)


# ── offline license verification ──────────────────────────────────────────────────────────────

def test_valid_license(tmp_path):
    dev = identity.DeviceIdentity(str(tmp_path / "k")).load_or_create()
    sk, pub = _shopbot()
    p = _payload(dev.public_key_b64())
    r = lic.verify(p, _sign(sk, p), pub, dev.public_key_b64(), FP, NOW)
    assert r.status == lic.VALID and r.ok


def test_grace_then_expired(tmp_path):
    dev = identity.DeviceIdentity(str(tmp_path / "k")).load_or_create()
    sk, pub = _shopbot()
    p = _payload(dev.public_key_b64(), ttl=-10, grace=1000)   # already expired, 1000s grace
    r = lic.verify(p, _sign(sk, p), pub, dev.public_key_b64(), FP, NOW)
    assert r.status == lic.GRACE and r.ok                     # keeps operating during an outage
    r2 = lic.verify(p, _sign(sk, p), pub, dev.public_key_b64(), FP, NOW + 2000)
    assert r2.status == lic.EXPIRED and not r2.ok             # past grace → stop


def test_tampered_payload_is_invalid(tmp_path):
    dev = identity.DeviceIdentity(str(tmp_path / "k")).load_or_create()
    sk, pub = _shopbot()
    p = _payload(dev.public_key_b64())
    sig = _sign(sk, p)
    p["license_type"] = "production"  # (unchanged) ; now tamper a real field:
    p["expires_at"] = NOW + 999999    # attacker extends validity after signing
    r = lic.verify(p, sig, pub, dev.public_key_b64(), FP, NOW)
    assert r.status == lic.INVALID_SIG and not r.ok


def test_forged_signer_rejected(tmp_path):
    dev = identity.DeviceIdentity(str(tmp_path / "k")).load_or_create()
    _real_sk, real_pub = _shopbot()
    attacker_sk, _ = _shopbot()                               # not ShopBot's key
    p = _payload(dev.public_key_b64())
    r = lic.verify(p, _sign(attacker_sk, p), real_pub, dev.public_key_b64(), FP, NOW)
    assert r.status == lic.INVALID_SIG and not r.ok


# ── clone protection (the physical acceptance test, in software) ───────────────────────────────

def test_clone_fresh_keypair_rejected(tmp_path):
    # BOX B activates fresh → its device pubkey differs from the license's bound pubkey
    box_a = identity.DeviceIdentity(str(tmp_path / "a")).load_or_create()
    box_b = identity.DeviceIdentity(str(tmp_path / "b")).load_or_create()
    sk, pub = _shopbot()
    p = _payload(box_a.public_key_b64())
    r = lic.verify(p, _sign(sk, p), pub, box_b.public_key_b64(), FP, NOW)  # B verifies A's license
    assert r.status == lic.WRONG_DEVICE and "keypair" in r.detail


def test_clone_copied_storage_different_hardware_rejected(tmp_path):
    # BOX B copies A's whole /data (SAME private key) but runs on DIFFERENT hardware (fingerprint differs)
    box_a = identity.DeviceIdentity(str(tmp_path / "a")).load_or_create()
    sk, pub = _shopbot()
    p = _payload(box_a.public_key_b64(), fingerprint=FP)      # license bound to A's hardware
    box_b_fingerprint = ["machine_id:dddddddddddddddd", "disk_serial:eeeeeeeeeeeeeeee", "cpu_serial:cccccccccccccccc"]
    r = lic.verify(p, _sign(sk, p), pub, box_a.public_key_b64(), box_b_fingerprint, NOW, fp_need=2)
    assert r.status == lic.WRONG_DEVICE and "fingerprint" in r.detail


# ── fingerprint quorum ─────────────────────────────────────────────────────────────────────────

def test_fingerprint_quorum():
    assert fp.quorum_ok(FP, FP, need=2)
    one_swapped = ["machine_id:aaaaaaaaaaaaaaaa", "disk_serial:XXXX", "cpu_serial:cccccccccccccccc"]
    assert fp.quorum_ok(FP, one_swapped, need=2)              # a disk swap alone does not brick the box
    two_swapped = ["machine_id:ZZZZ", "disk_serial:XXXX", "cpu_serial:cccccccccccccccc"]
    assert not fp.quorum_ok(FP, two_swapped, need=2)          # a copy to different HW is rejected
