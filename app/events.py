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

# ── P0-9: the retry queue runs inside the CAPTURE thread ──────────────────────────
#
# retry_undelivered() was called from the scheduler loop with limit=20 and no time
# budget. With the webhook down, each POST waits the full TIMEOUT, so one pass could
# block for 20 x 10 = 200 SECONDS on a 15-second loop — starving every camera on the
# box. That is the same failure P0-3 fixed for dead cameras, in a different disguise,
# and it compounds: the uplink being down is exactly when events pile up.
#
# The budget is the hard stop. The early exit is the useful one: if the FIRST retry
# fails the uplink is down, and grinding through nineteen more proves nothing while
# costing 190 seconds of capture.
RETRY_BUDGET_S = float(os.environ.get("RETRY_BUDGET_S", "8"))
RETRY_BATCH = int(os.environ.get("RETRY_BATCH", "20"))

# A failing event backs off instead of being hammered. Without this the queue is
# ordered oldest-first for ever, so ONE permanently-undeliverable event — a payload
# CloudBSS answers 400 to, say — is retried every loop and blocks every newer event
# behind it. The shop stops receiving alerts and nothing anywhere says why.
RETRY_BACKOFF_BASE_S = float(os.environ.get("RETRY_BACKOFF_BASE_S", "30"))
RETRY_BACKOFF_CAP_S = float(os.environ.get("RETRY_BACKOFF_CAP_S", "3600"))

# After this many attempts an event is quarantined: taken out of the queue so it stops
# blocking, and counted in /api/health so somebody finds out. Silently dropping it
# would be losing an alert; retrying for ever would be losing all the others.
MAX_ATTEMPTS = int(os.environ.get("RETRY_MAX_ATTEMPTS", "12"))


def retry_delay(attempts: int) -> float:
    return min(RETRY_BACKOFF_BASE_S * (2 ** max(0, attempts - 1)), RETRY_BACKOFF_CAP_S)

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
    """Accept a payload that has no row in the local events table. Does NOT post.

    Health and quality events are about the box, not about a shelf, so they are not
    stored as zone transitions. They used to be POSTed synchronously from right here —
    and every caller of this function runs in the capture thread.

    P0-10: that was the third appearance of one bug. P0-3 found dead cameras starving
    the capture thread; P0-9 found the retry queue doing it; this is the first-attempt
    path doing the same. A camera going offline while three zones are obstructed is
    four synchronous POSTs at a ten-second timeout — forty seconds inside a fifteen
    second loop, and no camera on the box is read in the meantime.

    So nothing crosses the wire from the capture thread any more. This enqueues; the
    budgeted drain in the scheduler sends. Returns True meaning ACCEPTED, not
    delivered — the distinction the whole audit was about, so it is stated here.
    """
    if not webhook_url():
        log.info("event %s (%s) [no webhook configured]",
                 payload.get("event"), payload.get("camera"))
        return False
    db.enqueue_outbox(payload)
    return True


def drain_outbox(limit: int | None = None, budget_s: float | None = None,
                 started: float | None = None) -> dict:
    """Send queued notices under the same budget and backoff as the event queue.

    Deliberately the same machinery rather than a second implementation: two retry
    paths would drift, and the one that drifted would be the one nobody was watching.
    """
    out = {"attempted": 0, "delivered": 0, "failed": 0, "stopped": ""}
    if not webhook_url():
        out["stopped"] = "no webhook configured"
        return out
    limit = RETRY_BATCH if limit is None else limit
    budget = RETRY_BUDGET_S if budget_s is None else budget_s
    started = time.time() if started is None else started

    rows = db.db().execute(
        "SELECT id, payload, attempts FROM outbox WHERE delivered=0 AND quarantined=0 "
        "ORDER BY COALESCE(next_retry_ts, ts) LIMIT ?", (limit,)).fetchall()
    for r in rows:
        if time.time() - started >= budget:
            out["stopped"] = "time budget spent"
            break
        try:
            payload = json.loads(r["payload"])
        except ValueError:
            with db.tx() as con:
                con.execute("UPDATE outbox SET quarantined=1 WHERE id=?", (r["id"],))
            log.error("outbox row %s is unreadable; quarantined", r["id"])
            continue
        out["attempted"] += 1
        ok, detail = post_payload(payload)
        if ok:
            with db.tx() as con:
                con.execute("UPDATE outbox SET delivered=1, next_retry_ts=NULL WHERE id=?",
                            (r["id"],))
            out["delivered"] += 1
            continue
        out["failed"] += 1
        attempts = int((r["attempts"] if "attempts" in r.keys() else 0) or 0)
        _fail_outbox(r["id"], attempts, detail)
        out["stopped"] = "uplink appears down — stopping this round"
        break
    return out


def _fail_outbox(row_id: int, attempts: int, detail: str) -> None:
    attempts += 1
    if attempts >= MAX_ATTEMPTS:
        with db.tx() as con:
            con.execute("UPDATE outbox SET attempts=?, quarantined=1, next_retry_ts=NULL "
                        "WHERE id=?", (attempts, row_id))
        log.error("outbox %s GIVEN UP after %s attempts: %s", row_id, attempts, detail)
        return
    with db.tx() as con:
        con.execute("UPDATE outbox SET attempts=?, next_retry_ts=? WHERE id=?",
                    (attempts, time.time() + retry_delay(attempts), row_id))


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
                  duration_s: float | None = None, escalation: bool = False,
                  decision=None) -> dict:
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
        # P0-7. CloudBSS had no way to tell an alert from a log line, so either every
        # state change woke somebody or none did. The gateway now states its own
        # judgement AND why — "why wasn't I warned?" has an answer months later.
        **(decision.as_dict() if decision is not None
           else {"alertable": False, "alert_reason": "undecided", "alert_detail": ""}),
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
        return False
    ok, detail = post_payload(payload)
    if ok:
        with db.tx() as con:
            con.execute("UPDATE events SET delivered=1, next_retry_ts=NULL WHERE id=?",
                        (event_id,))
        return True
    _record_failure(event_id, detail)
    return False


def _record_failure(event_id: int, detail: str) -> None:
    """Count the attempt, back off, and give up loudly rather than jamming the queue."""
    row = db.db().execute("SELECT attempts FROM events WHERE id=?", (event_id,)).fetchone()
    attempts = int((row["attempts"] if row else 0) or 0) + 1
    if attempts >= MAX_ATTEMPTS:
        with db.tx() as con:
            con.execute("UPDATE events SET attempts=?, quarantined=1, next_retry_ts=NULL "
                        "WHERE id=?", (attempts, event_id))
        log.error("event %s GIVEN UP after %s attempts: %s — this alert is lost and is "
                  "counted in /api/health", event_id, attempts, detail)
        return
    with db.tx() as con:
        con.execute("UPDATE events SET attempts=?, next_retry_ts=? WHERE id=?",
                    (attempts, time.time() + retry_delay(attempts), event_id))
    log.warning("webhook delivery failed for event %s (attempt %s): %s (will retry)",
                event_id, attempts, detail)


def retry_undelivered(limit: int | None = None, budget_s: float | None = None) -> dict:
    """Work the store-and-forward queue, inside a hard time budget.

    Three P0-9 changes, each fixing a way this could take the box down:

    **A time budget.** This runs in the capture thread. Twenty POSTs at a 10-second
    timeout is 200 seconds on a 15-second loop — every camera starved, precisely when
    the uplink is down and events are piling up.

    **Stop on the first failure.** If one retry fails the uplink is down; nineteen more
    prove nothing and cost 190 seconds. The next loop tries again in fifteen.

    **Ordered by when each event is next DUE, not by age.** Oldest-first meant one
    permanently-undeliverable event was retried every loop for ever and blocked every
    newer event behind it — the shop silently stops receiving alerts, and the cause is
    a single bad row at the head of a queue nobody can see.

    Returns a summary so /api/health can report a queue that is not draining.
    """
    out = {"attempted": 0, "delivered": 0, "failed": 0, "stopped": ""}
    if not webhook_url():
        out["stopped"] = "no webhook configured"
        return out

    limit = RETRY_BATCH if limit is None else limit
    budget = RETRY_BUDGET_S if budget_s is None else budget_s
    started = time.time()

    rows = db.db().execute(
        "SELECT e.id, e.ts, e.to_state, e.from_state, e.fill, e.payload, e.zone_id, "
        "z.name AS zone_name, c.name AS cam_name FROM events e "
        "JOIN zones z ON z.id=e.zone_id JOIN cameras c ON c.id=z.camera_id "
        "WHERE e.delivered=0 AND e.quarantined=0 "
        # Backoff governs ORDER, not eligibility. Filtering on next_retry_ts looked
        # right and was wrong: after a two-hour outage every queued event carries a
        # long delay, so the queue would sit idle for up to an hour AFTER the link
        # came back. Ordering instead gives both properties — a repeatedly-failing
        # event sorts behind fresher ones so it can no longer block them, and recovery
        # is immediate because nothing is excluded.
        "ORDER BY COALESCE(e.next_retry_ts, e.ts) LIMIT ?",
        (limit,)).fetchall()

    for r in rows:
        if time.time() - started >= budget:
            out["stopped"] = "time budget spent"
            break
        stored = _row_get(r, "payload")
        payload = None
        if stored:
            try:
                payload = json.loads(stored)
                payload["thumbnail"] = None
            except ValueError:
                log.warning("event %s has an unreadable stored payload; rebuilding", r["id"])
        if payload is None:
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
        out["attempted"] += 1
        if deliver(r["id"], payload):
            out["delivered"] += 1
        else:
            out["failed"] += 1
            out["stopped"] = "uplink appears down — stopping this round"
            break
    return out


def queue_health() -> dict:
    """What the retry queue looks like, for /api/health.

    `quarantined` is the number that matters: those are alerts this box gave up on. A
    non-zero value is not a warning, it is a report that the shop was never told
    something it should have been.
    """
    row = db.db().execute(
        "SELECT COUNT(*) FILTER (WHERE delivered=0 AND quarantined=0) AS pending, "
        "       COUNT(*) FILTER (WHERE quarantined=1) AS quarantined, "
        "       MIN(ts) FILTER (WHERE delivered=0 AND quarantined=0) AS oldest "
        "  FROM events").fetchone()
    ob = db.db().execute(
        "SELECT COUNT(*) FILTER (WHERE delivered=0 AND quarantined=0) AS pending, "
        "       COUNT(*) FILTER (WHERE quarantined=1) AS quarantined FROM outbox").fetchone()
    return {"pending": row["pending"] or 0,
            "quarantined": row["quarantined"] or 0,
            "oldest_pending_ts": row["oldest"],
            # The outbox carries camera/quality/reference notices, which have no event
            # row of their own. Reported separately because "no shelf alerts are stuck
            # but every health notice is" is a different fault with a different cause.
            "notices_pending": ob["pending"] or 0,
            "notices_quarantined": ob["quarantined"] or 0}
