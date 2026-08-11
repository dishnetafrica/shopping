"""Event emission — the second frozen platform contract.

Every zone state change becomes an event in the schema below and is POSTed to
EVENT_WEBHOOK_URL (CloudBSS endpoint, n8n flow, or Evolution API bridge).
Store-and-forward: undelivered events stay flagged in SQLite and are retried,
so a dead uplink never loses an alert.
"""
from __future__ import annotations

import base64
import hashlib
import json
import logging
import os
import time

import requests

from . import db, detector

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

# P0-3. The vocabulary the audit found missing: the box could see nothing, or see
# rubbish, and say nothing at all about either. Both are EDGE-TRIGGERED — a dirty lens
# at a ten-minute cadence would otherwise post 144 identical events a day.
IMAGE_BAD = "image.bad"
IMAGE_OK = "image.ok"
CAMERA_OFFLINE = "camera.offline"
CAMERA_ONLINE = "camera.online"
REFERENCE_SUSPECT = "zone.reference_suspect"
REFERENCE_OK = "zone.reference_ok"


def build_reference_payload(camera_name: str, zone_name: str, suspect: bool,
                            ratio: float, enrolled_edge: float, zone_row=None) -> dict:
    """This zone is probably not watching anything, and here is the arithmetic.

    Sent when a zone has repeatedly looked FULLER than every view it was enrolled
    against — the signature of a reference captured while the shelf was not full.
    Such a zone reports STOCKED permanently and would otherwise never be questioned.

    `to_state` says what to do rather than what was measured, because the person
    reading it on WhatsApp needs an instruction, not a statistic. The numbers ride
    along underneath for anyone who wants to check the claim.
    """
    return {
        "event": REFERENCE_OK if not suspect else REFERENCE_SUSPECT,
        "store": store_id(),
        "camera": camera_name,
        "zone": zone_name,
        **(zone_identity(zone_row) if zone_row is not None else {}),
        "from_state": "",
        "to_state": "re-enrol this zone" if suspect else "reference ok",
        "detail": (
            f"this shelf has looked {ratio:.2f}x fuller than its enrolled reference "
            f"on several checks in a row — the reference was probably captured while "
            f"the shelf was not full, so this zone cannot report it empty"
            if suspect else
            "this zone no longer looks fuller than its reference"
        ),
        "ratio": round(float(ratio), 3),
        "enrolled_edge_density": round(float(enrolled_edge), 5),
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    }


def build_quality_payload(camera_name: str, zone_name: str | None, ok: bool,
                          reason: str, detail: str, metrics: dict | None,
                          zone_row=None) -> dict:
    """A frame we refused to read, or the recovery from one.

    `to_state` carries the reason rather than a shelf state, because that is what a
    person needs: "image.bad / blurred" is actionable — someone wipes a lens — whereas
    a bare "bad" is not. `fill` and `confidence` are deliberately absent: this event is
    the system saying it does NOT know, and putting a number on that would be the same
    dishonesty as the fabricated confidence the audit found.
    """
    return {
        "event": IMAGE_OK if ok else IMAGE_BAD,
        "store": store_id(),
        "camera": camera_name,
        "zone": zone_name or "",
        **(zone_identity(zone_row) if zone_row is not None else {}),
        "from_state": "",
        "to_state": reason or "ok",
        "detail": detail,
        "metrics": metrics or {},
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    }


def build_camera_payload(camera_name: str, online: bool, detail: str,
                         failures: int = 0) -> dict:
    return {
        "event": CAMERA_ONLINE if online else CAMERA_OFFLINE,
        "store": store_id(),
        "camera": camera_name,
        "zone": "",
        "from_state": "offline" if online else "online",
        "to_state": "online" if online else "offline",
        "detail": detail,
        "failures": failures,
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
    }


def send(payload: dict) -> bool:
    """Deliver a payload that has no row in the local events table.

    Health and quality events are about the box, not about a shelf, so they are not
    stored as zone transitions. They still go through post_payload — the one place
    anything crosses the wire — so they carry the same token and the same timeout, and
    a self-test proving delivery proves these too.
    """
    if not webhook_url():
        log.info("event %s (%s) [no webhook configured]", payload.get("event"), payload.get("camera"))
        return False
    ok, detail = post_payload(payload)
    if not ok:
        log.warning("could not deliver %s for %s: %s", payload.get("event"),
                    payload.get("camera"), detail)
    return ok


STILL_OUT = "shelf.still_out_of_stock"


def event_uid(zone_id: int, ts: float, to_state: str) -> str:
    """A stable id for one state change, so CloudBSS can deduplicate.

    Deterministic rather than random: derived from what the event IS, so a retry, a
    replayed row, or a restored database all produce the same id. A delivery that
    actually landed but whose local `delivered` flag was lost would otherwise be
    counted twice, and double-alerting a shop about the same empty shelf is how an
    operator learns to ignore the alerts.
    """
    raw = f"{store_id()}|{zone_id}|{ts:.3f}|{to_state}"
    return hashlib.sha256(raw.encode()).hexdigest()[:32]


def _iso(ts: float | None) -> str:
    """The event's OWN time, not the time we happen to be formatting it.

    retry_undelivered used to call time.strftime() at retry, so an event delayed by an
    hour of failed webhooks arrived claiming to have just happened. The shop would have
    been told a shelf emptied at 4pm when it emptied at 3.
    """
    return time.strftime("%Y-%m-%dT%H:%M:%S%z", time.localtime(ts or time.time()))


def build_payload(zone_row, camera_row, to_state: str, from_state: str,
                  fill: float, thumb_jpeg: bytes | None, ts: float | None = None,
                  duration_s: float | None = None, escalation: bool = False) -> dict:
    """One shelf state change, with a clock attached.

    P0-5 adds four things the audit found missing: a stable `uid`, the event's real
    timestamp, how long the PREVIOUS state had lasted, and a confidence that means
    something. `duration_s` is the age of the state being left behind — "this shelf had
    been stocked for six hours and has just gone empty" — which is what tells a
    restocker whether this is a fast mover or a forgotten corner.
    """
    ts = time.time() if ts is None else ts
    suspect = bool(_row_get(zone_row, "ref_suspect", 0))
    conf = detector.confidence(
        fill, to_state,
        _row_get(zone_row, "empty_below", 0.35) or 0.35,
        _row_get(zone_row, "low_below", 0.65) or 0.65,
        ref_suspect=suspect)
    return {
        "event": STILL_OUT if escalation else EVENT_NAMES.get(to_state, "shelf.state_changed"),
        "uid": event_uid(zone_row["id"], ts, ("still:" if escalation else "") + to_state),
        "store": store_id(),
        "camera": camera_row["name"],
        "zone": zone_row["name"],
        **zone_identity(zone_row),
        "from_state": from_state,
        "to_state": to_state,
        "confidence": conf,
        "confidence_basis": "margin from threshold; not a calibrated probability",
        "reference_suspect": suspect,
        "fill": fill,
        "empty_below": _row_get(zone_row, "empty_below", 0.35),
        "low_below": _row_get(zone_row, "low_below", 0.65),
        "duration_s": None if duration_s is None else round(float(duration_s), 1),
        "duration_human": _human(duration_s),
        "thumbnail": base64.b64encode(thumb_jpeg).decode() if thumb_jpeg else None,
        "timestamp": _iso(ts),
    }


def _row_get(row, name: str, default=None):
    try:
        v = row[name]
    except (IndexError, KeyError, TypeError):
        return default
    return default if v is None else v


def zone_identity(zone_row) -> dict:
    """Who this shelf is, in terms that survive being renamed.

    Every event used to travel keyed on `zone: <name>` and nothing else. Rename a zone
    — which, before P0-6, you could only do by deleting and redrawing it — and CloudBSS
    saw a brand-new shelf, with every past event stranded under a name nothing pointed
    at any more.

    `zone_uid` is the join key. `aisle`/`shelf`/`face` are for humans: "Aisle 3, bay 2,
    left face" is a place someone can walk to, which is what an alert has to be. `skus`
    is the map CloudBSS's own ingest controller says it is waiting for before shelf
    events can be joined to stock.
    """
    skus = _row_get(zone_row, "skus", "[]")
    try:
        skus = json.loads(skus) if isinstance(skus, str) else list(skus or [])
    except ValueError:
        skus = []
    return {
        "zone_uid": _row_get(zone_row, "zone_uid", "") or "",
        "aisle": _row_get(zone_row, "aisle", "") or "",
        "shelf": _row_get(zone_row, "shelf", "") or "",
        "face": _row_get(zone_row, "face", "") or "",
        "skus": skus,
        "sku": skus[0] if skus else "",     # the one an alert names
    }


def _human(seconds: float | None) -> str:
    """Plain words, because the person reading this is holding a phone in an aisle."""
    if seconds is None:
        return ""
    s = int(seconds)
    if s < 90:
        return f"{s} seconds"
    if s < 5400:
        return f"{round(s / 60)} minutes"
    if s < 172800:
        return f"{round(s / 3600)} hours"
    return f"{round(s / 86400)} days"


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
    """Re-send exactly what was built, not a reconstruction of it.

    This function used to rebuild the payload from columns, and got it wrong twice: it
    sent `confidence = fill` where the first attempt had sent `1 - fill`, and it stamped
    the payload with time.strftime() at RETRY time, so an event delayed by an hour of
    failed webhooks arrived claiming to have just happened. Same event, two different
    stories, depending on whether the network was up.

    The payload as sent is now stored on the row, so a retry is a re-send rather than a
    re-derivation. Only the thumbnail is absent — it is deliberately not stored, because
    base64 images would bloat the database for a field nothing downstream requires.
    """
    if not webhook_url():
        return
    rows = db.db().execute(
        "SELECT e.id, e.ts, e.to_state, e.from_state, e.fill, e.payload, e.zone_id, "
        "z.name AS zone_name, c.name AS cam_name FROM events e "
        "JOIN zones z ON z.id=e.zone_id JOIN cameras c ON c.id=z.camera_id "
        "WHERE e.delivered=0 ORDER BY e.ts LIMIT ?", (limit,)).fetchall()
    for r in rows:
        stored = _row_get(r, "payload")
        if stored:
            try:
                payload = json.loads(stored)
                payload["thumbnail"] = None
                deliver(r["id"], payload)
                continue
            except ValueError:
                log.warning("event %s has an unreadable stored payload; rebuilding", r["id"])
        # Pre-P0-5 rows, or a corrupt one. Rebuilt with the SAME formulas as
        # build_payload rather than the old divergent ones, and stamped with the
        # event's own ts so a late delivery still tells the truth about when.
        payload = {
            "event": EVENT_NAMES.get(r["to_state"], "shelf.state_changed"),
            "uid": event_uid(r["zone_id"], r["ts"], r["to_state"]),
            "store": store_id(), "camera": r["cam_name"], "zone": r["zone_name"],
            "from_state": r["from_state"], "to_state": r["to_state"],
            "confidence": None, "confidence_basis": "unknown (pre-P0-5 event)",
            "fill": r["fill"], "duration_s": None, "duration_human": "",
            "thumbnail": None, "timestamp": _iso(r["ts"]),
        }
        deliver(r["id"], payload)
