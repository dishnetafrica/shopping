"""
Layered hardware fingerprint — an ADDITIONAL binding signal, never the sole identity (per approval:
machine-id/DMI/serials can change or be cloned). Used together with the device keypair: a storage copy
to another box keeps the private key but changes the hardware, so the fingerprint quorum fails.

Collects several stable signals, hashes each (salted, so raw serials never leave the box), and compares
by QUORUM — a threshold of signals must match — so a single disk swap doesn't brick a box but a copy to
different hardware is rejected. Pure stdlib; every read is best-effort and never raises.
"""
from __future__ import annotations

import hashlib
import os
import subprocess

_SALT = b"shopbot-vision-fingerprint-v1"


def _h(name: str, value: str) -> str:
    return name + ":" + hashlib.sha256(_SALT + value.strip().encode("utf-8", "replace")).hexdigest()[:16]


def _read(path: str) -> str | None:
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            v = fh.read().strip()
            return v or None
    except OSError:
        return None


def _cpu_serial() -> str | None:
    v = _read("/proc/cpuinfo")
    if not v:
        return None
    for line in v.splitlines():
        if line.lower().startswith("serial"):
            return line.split(":", 1)[-1].strip() or None
    return None


def _disk_serial() -> str | None:
    try:
        out = subprocess.run(["lsblk", "-dno", "SERIAL"], capture_output=True, timeout=4, text=True)
        for s in (out.stdout or "").split():
            if s.strip():
                return s.strip()
    except Exception:
        pass
    return None


def signals() -> dict:
    """Best-effort map of {signal_name: raw_value} for whatever this host exposes."""
    out = {}
    for name, val in (
        ("machine_id", _read("/etc/machine-id") or _read("/var/lib/dbus/machine-id")),
        ("dmi_uuid", _read("/sys/class/dmi/id/product_uuid")),
        ("board_serial", _read("/sys/class/dmi/id/board_serial")),
        ("cpu_serial", _cpu_serial()),
        ("disk_serial", _disk_serial()),
    ):
        if val:
            out[name] = val
    return out


def fingerprint() -> list[str]:
    """A sorted list of salted signal hashes — the opaque fingerprint stored in the license."""
    return sorted(_h(n, v) for n, v in signals().items())


def match_count(licensed: list[str], current: list[str]) -> int:
    return len(set(licensed) & set(current))


def quorum_ok(licensed: list[str], current: list[str], need: int = 2) -> bool:
    """At least `need` signals match. With few signals available, `need` is clamped so a box that only
    exposes one signal still works (but is weaker — logged/flagged server-side)."""
    need = max(1, min(need, len(licensed) or 1))
    return match_count(licensed, current) >= need
