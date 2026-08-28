"""
Device identity — a box-generated Ed25519 keypair (the PRIMARY identity, per approval).

The private key is generated once on first boot and never leaves the box (stored in /data, chmod 600;
hardware-backed storage/TPM is the production-hardening step). The public key is registered with ShopBot
at activation. The box proves it is itself by SIGNING activation/heartbeat challenges with its private
key; the server verifies against the registered public key. This is stronger than a fingerprint alone —
identity can't be forged without the private key.

Ed25519 via PyNaCl (libsodium), which interoperates with the cloud's PHP libsodium (sodium_crypto_sign).
"""
from __future__ import annotations

import base64
import os


class DeviceIdentity:
    def __init__(self, key_path: str):
        self.key_path = key_path
        self._sk = None

    # -- key lifecycle ------------------------------------------------------------------------
    def load_or_create(self) -> "DeviceIdentity":
        from nacl.signing import SigningKey  # lazy

        if os.path.exists(self.key_path):
            with open(self.key_path, "rb") as fh:
                self._sk = SigningKey(fh.read())
        else:
            self._sk = SigningKey.generate()
            os.makedirs(os.path.dirname(self.key_path) or ".", exist_ok=True)
            # write 0600 BEFORE the bytes exist on disk
            fd = os.open(self.key_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, "wb") as fh:
                fh.write(bytes(self._sk))
        return self

    @property
    def _key(self):
        if self._sk is None:
            self.load_or_create()
        return self._sk

    # -- public API ---------------------------------------------------------------------------
    def public_key_b64(self) -> str:
        return base64.b64encode(bytes(self._key.verify_key)).decode("ascii")

    def sign(self, message: bytes) -> str:
        """Detached signature (base64) over `message` — proves possession of the private key."""
        return base64.b64encode(self._key.sign(message).signature).decode("ascii")


def verify_device_signature(public_key_b64: str, message: bytes, signature_b64: str) -> bool:
    """Server-side style check (also used in tests): does `signature` over `message` verify against the
    device's registered public key?"""
    from nacl.signing import VerifyKey
    from nacl.exceptions import BadSignatureError

    try:
        vk = VerifyKey(base64.b64decode(public_key_b64))
        vk.verify(message, base64.b64decode(signature_b64))
        return True
    except (BadSignatureError, ValueError, Exception):
        return False
