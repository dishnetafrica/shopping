"""Event emission — the second frozen platform contract.

Every zone state change becomes an event in the schema below and is POSTed to
EVENT_WEBHOOK_URL (CloudBSS endpoint, n8n flow, or Evolution API bridge).
Store-and-forward: undelivered events stay flagged in SQLite and are retried,
so a dead uplink never loses an alert.
"""
from __future__ import annotations

import base64
import logging
import os
import time

import requests

from . import db

log = logging.getLogger("events")

TIMEOUT = 10

# Configuration is read from the environment AT CALL TIME, not at import.
#
# These used to be module constants. That made the delivery settings impossible to
# assert in a test without reimporting the module, and it meant there was no single
# place that could answer "is this box actually able to deliver anything?" — which is
# how a whole fleet came to run with an empty webhook without one line of code
# noticing. config_problems() below is now that single place, and main.py refuses to
# start when it reports something fatal.
#
# There is deliberately NO default store id. The old default was a real customer's
# name, so a misconfigured box did not fail — it silently filed its events under
# somebody else's shop.


def webhook_url() -> str:
    return os.environ.get("EVENT_WEBHOOK_URL", "").strip()


def token() -> str:
    return os.environ.get("VISION_TOKEN", "").strip()


def store_id() -> str:
    return os.environ.get("STORE_ID", "").strip()


def _flag(name: str) -> bool:
    return os.environ.get(name, "0").strip() in ("1", "true", "TRUE", "yes")


def config_problems() -> tuple[list[str], list[str]]:
    """Can this box deliver what it detects? Returns (fatal, warnings).

    Fatal problems stop the gateway from starting. The reasoning is that a shelf
    sensor which cannot report is not a degraded sensor, it is a silent one — and a
    silent one is worse than an absent one, because the store believes it is covered.
    """
    fatal: list[str] = []
    warn: list[str] = []

    if _flag("DEMO"):
        warn.append("DEMO=1 — synthetic cameras, delivery checks skipped. Never ship a box like this.")
        return fatal, warn

    if not store_id():
        fatal.append(
            "STORE_ID is empty. Events could not be attributed to a store. "
            "Set it in .env (install.sh writes it)."
        )

    if not webhook_url():
        if _flag("ALLOW_NO_WEBHOOK"):
            warn.append(
                "ALLOW_NO_WEBHOOK=1 — running offline on purpose. Shelf events will be "
                "detected and stored locally, and NOBODY will be told about them."
            )
        else:
            fatal.append(
                "EVENT_WEBHOOK_URL is empty. The gateway would watch every shelf and "
                "deliver the result nowhere. Set it in .env (install.sh writes it), or "
                "set ALLOW_NO_WEBHOOK=1 to run offline deliberately."
            )
    else:
        if not webhook_url().lower().startswith(("http://", "https://")):
            fatal.append(f"EVENT_WEBHOOK_URL is not an http(s) URL: {webhook_url()!r}")
        if not token():
            warn.append(
                "EVENT_WEBHOOK_URL is set but VISION_TOKEN is empty. CloudBSS answers 401 "
                "to an untokened event, so every event will fail and retry forever."
            )
        if webhook_url().lower().startswith("http://"):
            warn.append("EVENT_WEBHOOK_URL is plain http:// — the token travels in clear.")

    return fatal, warn

EVENT_NAMES = {"empty": "shelf.out_of_stock", "low": "shelf.low_stock",
               "stocked": "shelf.restocked", "unknown": "shelf.unknown"}


def build_payload(zone_row, camera_row, to_state: str, from_state: str,
                  fill: float, thumb_jpeg: bytes | None) -> dict:
    return {
        "event": EVENT_NAMES.get(to_state, "shelf.state_changed"),
        "store": store_id(),
        "camera": camera_row["name"],
        "zone": zone_row["name"],
        "from_state": from_state,
        "to_state": to_state,
        "confidence": fill if to_state == "stocked" else round(1.0 - fill, 4),
        "fill": fill,
        "thumbnail": base64.b64encode(thumb_jpeg).decode() if thumb_jpeg else None,
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    }


def post_payload(payload: dict) -> tuple[bool, str]:
    """The one place an event crosses the wire. Returns (delivered, detail).

    Extracted so the self-test below can prove the REAL delivery path — same URL, same
    token header, same timeout — without inventing a fake zone and a fake event row to
    carry it. If this and the self-test drift apart, the self-test stops being evidence.
    """
    try:
        headers = {"X-Vision-Token": token()} if token() else {}
        r = requests.post(webhook_url(), json=payload, timeout=TIMEOUT, headers=headers)
        r.raise_for_status()
        return True, f"HTTP {r.status_code}"
    except requests.RequestException as e:
        detail = str(e)
        resp = getattr(e, "response", None)
        if resp is not None:
            body = (resp.text or "")[:200]
            detail = f"HTTP {resp.status_code}: {body}"
            if resp.status_code == 401:
                detail += "  → CloudBSS rejected the token. Check VISION_TOKEN here matches "\
                          "VISION_WEBHOOK_TOKEN there, or claim this box."
        return False, detail


def selftest() -> dict:
    """Deliver one clearly-marked event and say plainly whether it arrived.

    This exists because a box can be installed, configured and running with every light
    green while delivering nothing — which is exactly what happened. It needs no camera,
    no zone and no shelf, so it is the one check that can be run the moment a box is
    plugged in, before anyone has hung a lens.

    It writes a real row in CloudBSS. That is the point: something you can go and look at.
    """
    if not webhook_url():
        return {"ok": False, "detail": "no EVENT_WEBHOOK_URL configured — nothing to test"}
    payload = {
        "event": "shelf.selftest",
        "store": store_id(),
        "camera": "selftest",
        "zone": "selftest",
        "from_state": "unknown",
        "to_state": "unknown",
        "confidence": None,
        "fill": None,
        "thumbnail": None,
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    }
    ok, detail = post_payload(payload)
    log.info("selftest: %s (%s)", "DELIVERED" if ok else "FAILED", detail)
    return {"ok": ok, "detail": detail, "store": store_id(), "url": webhook_url()}


def deliver(event_id: int, payload: dict) -> None:
    if not webhook_url():
        log.info("event %s (%s/%s -> %s) [no webhook configured]",
                 event_id, payload["camera"], payload["zone"], payload["to_state"])
        return
    ok, detail = post_payload(payload)
    if ok:
        with db.tx() as con:
            con.execute("UPDATE events SET delivered=1 WHERE id=?", (event_id,))
    else:
        log.warning("webhook delivery failed for event %s: %s (will retry)", event_id, detail)


def retry_undelivered(limit: int = 20) -> None:
    if not webhook_url():
        return
    rows = db.db().execute(
        "SELECT e.id, e.to_state, e.from_state, e.fill, z.name AS zone_name, "
        "c.name AS cam_name FROM events e "
        "JOIN zones z ON z.id=e.zone_id JOIN cameras c ON c.id=z.camera_id "
        "WHERE e.delivered=0 ORDER BY e.ts LIMIT ?", (limit,)).fetchall()
    for r in rows:
        payload = {
            "event": EVENT_NAMES.get(r["to_state"], "shelf.state_changed"),
            "store": store_id(), "camera": r["cam_name"], "zone": r["zone_name"],
            "from_state": r["from_state"], "to_state": r["to_state"],
            "confidence": r["fill"], "fill": r["fill"], "thumbnail": None,
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        }
        deliver(r["id"], payload)
