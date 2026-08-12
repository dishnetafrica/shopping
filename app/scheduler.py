"""Capture scheduler — one background thread, snapshot-first, no streaming.

Every LOOP_S it snapshots the cameras that are due, evaluates their zones,
steps the hysteresis state machine, records events, and retries undelivered
webhooks. 40 cameras at a 10-minute cadence is ~0.07 inferences/second —
which is why this is a thread and not a cluster.
"""
from __future__ import annotations

import json
import logging
import os
import threading
import time

import cv2
import numpy as np

from . import alerting, db, detector, events, quality, redact
from .cal import CameraConfig, CameraError, get_driver

log = logging.getLogger("scheduler")
LOOP_S = int(os.environ.get("SCHED_LOOP_S", "15"))
SNAP_DIR = os.environ.get("SNAP_DIR", "data/snapshots")


def cam_config(row) -> CameraConfig:
    return CameraConfig(
        id=row["id"], name=row["name"], driver=row["driver"], host=row["host"],
        port=row["port"], username=row["username"], password=row["password"],
        channel=row["channel"], rtsp_url=row["rtsp_url"],
        interval_s=row["interval_s"], enabled=bool(row["enabled"]),
    )


def capture_one(row) -> int | None:
    """Snapshot a camera, persist, evaluate zones. Returns snapshot id."""
    cam = cam_config(row)
    try:
        jpeg = get_driver(cam.driver).get_snapshot(cam)
    except CameraError as e:
        # ffmpeg echoes the stream URL on failure, credentials and all. That string was
        # stored, served by /api/cameras and rendered in the dashboard, and logged where
        # `visionctl logs` prints it. Scrub once, here, before any of that can happen.
        msg = redact.scrub_secret(str(e), cam.password)[:300]
        _record_failure(row, msg)
        log.warning("camera %s (%s): %s", cam.id, cam.name, msg)
        return None

    ts = time.time()
    os.makedirs(SNAP_DIR, exist_ok=True)
    path = os.path.join(SNAP_DIR, f"cam{cam.id}_{int(ts)}.jpg")
    with open(path, "wb") as f:
        f.write(jpeg)
    with db.tx() as con:
        cur = con.execute("INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                          (cam.id, ts, path))
        snap_id = cur.lastrowid
        con.execute("UPDATE cameras SET last_ok_ts=?, last_error='', consecutive_failures=0, "
                    "next_attempt_ts=?, health_state='online' WHERE id=?",
                    (ts, ts + max(int(row["interval_s"] or 600), 1), cam.id))
    if (row["health_state"] or "unknown") == "offline":
        events.send(events.build_camera_payload(
            cam.name, True, "the camera is answering again"))
        log.info("camera %s (%s) is back", cam.id, cam.name)
    db.prune_snapshots(cam.id)

    evaluate_zones(row, snap_id, jpeg)
    return snap_id


OFFLINE_AFTER = int(os.environ.get("OFFLINE_AFTER_FAILURES", "2"))
BACKOFF_CAP_S = int(os.environ.get("BACKOFF_CAP_S", "1800"))


def is_due(row, now: float) -> bool:
    """Should this camera be tried right now?

    next_attempt_ts is authoritative: it is written on success AND on failure, so a dead
    camera waits instead of being hammered every loop. The fallback keeps rows written
    before P0-3 — and any row where the column is still NULL — behaving as they did.

    A named function rather than an expression inside the loop, so the backoff can be
    tested against the code the loop actually runs instead of against a copy of it.
    """
    nxt = row["next_attempt_ts"]
    if nxt:
        return nxt <= now
    return (row["last_ok_ts"] or 0) + (row["interval_s"] or 600) <= now


def _record_failure(row, msg: str) -> None:
    """Count the failure, back off, and raise camera.offline exactly once.

    The backoff is the other half of this. `due` used to be computed from last_ok_ts,
    which is only written on SUCCESS — so a dead camera stayed permanently due and was
    retried every 15 seconds for ever, each attempt blocking the single capture thread
    for its full driver timeout. Three dead cameras on 20-second RTSP timeouts starved
    every working camera on the box.
    """
    fails = int(row["consecutive_failures"] or 0) + 1
    delay = min(60 * (2 ** min(fails - 1, 6)), BACKOFF_CAP_S)
    with db.tx() as con:
        con.execute("UPDATE cameras SET last_error=?, consecutive_failures=?, "
                    "next_attempt_ts=? WHERE id=?",
                    (msg, fails, time.time() + delay, row["id"]))
    if fails >= OFFLINE_AFTER and (row["health_state"] or "unknown") != "offline":
        with db.tx() as con:
            con.execute("UPDATE cameras SET health_state='offline' WHERE id=?", (row["id"],))
        events.send(events.build_camera_payload(
            row["name"], False, f"no picture after {fails} attempts: {msg}", fails))


def _set_quality(table: str, row_id: int, ok: bool, verdict) -> None:
    with db.tx() as con:
        if table == "cameras":
            con.execute("UPDATE cameras SET quality_state=?, quality_reason=?, "
                        "quality_detail=?, quality_metrics=? WHERE id=?",
                        ("ok" if ok else "bad", verdict.reason, verdict.detail,
                         json.dumps(verdict.metrics), row_id))
        else:
            con.execute("UPDATE zones SET quality_state=?, quality_reason=?, "
                        "quality_detail=? WHERE id=?",
                        ("ok" if ok else "bad", verdict.reason, verdict.detail, row_id))


def _quality_transition(table: str, row, ok: bool, verdict, cam_name: str,
                        zone_name: str | None) -> None:
    """Emit only on a CHANGE. A dirty lens at a ten-minute cadence would otherwise
    post 144 identical events a day, and the one that mattered would be lost in them."""
    was = (row["quality_state"] or "unknown")
    now = "ok" if ok else "bad"
    _set_quality(table, row["id"], ok, verdict)
    if was == now:
        return
    if was == "unknown" and ok:
        return                      # first good frame is not news
    events.send(events.build_quality_payload(
        cam_name, zone_name, ok, verdict.reason,
        verdict.detail or "the picture can be read again", verdict.metrics,
        # A zone-level quality event is about a specific shelf, so it carries that
        # shelf's identity. A camera-level one has no zone, and must not invent one.
        zone_row=row if table == "zones" else None))
    log.info("quality %s/%s: %s -> %s (%s)", cam_name, zone_name or "-", was, now,
             verdict.reason or "ok")


def _col(row, name: str, default=None):
    """Read a column that may not exist on a box mid-upgrade.

    sqlite3.Row raises IndexError for an unknown key rather than returning None, so a
    plain row[name] here would crash every evaluation on any box whose migration has
    not run yet — turning an additive feature into an outage.
    """
    try:
        return row[name]
    except (IndexError, KeyError):
        return default


def _assess_zone_multi(crop_hsv, refs):
    """Obstructed only if the view is novel against EVERY enrolled reference.

    Judging against one arbitrary reference would undo the point of having several: a
    zone enrolled morning and evening would be called "obstructed" every time the light
    matched the other view. A shopper is novel against all of them; a lighting change
    is explained by one.
    """
    verdict = None
    for r in refs:
        v = quality.assess_zone(crop_hsv, r.hist, detector.HIST_BINS)
        if v.ok:
            return v
        verdict = verdict or v
    return verdict


def _escalate_if_stale(z, cam_row, state: str, fill: float, since: float, now: float,
                       snap_id, img) -> None:
    """Say it again when a shelf has been empty long enough that nobody acted.

    The first event answers "is this shelf empty". Nothing answered "is anyone dealing
    with it", which is the question a shop actually has — and the reason a single alert
    at 7am is worth so much less than a second one at 7.30 saying it is still out.

    Fires ONCE per occurrence, not on a timer. `escalated_at` is cleared whenever the
    state changes, so restocking the shelf arms it again for next time. A repeating
    alarm would be muted within a week, and a muted alarm is worth nothing.
    """
    if state not in detector.ESCALATE_STATES:
        return
    if _col(z, "escalated_at"):
        return
    age = now - since
    if age < detector.ESCALATE_AFTER_S:
        return

    ev_id = db.insert_event(z["id"], state, state, fill, snap_id)
    decision = alerting.should_alert(
        state,
        detector.confidence(fill, state, z["empty_below"], z["low_below"],
                            ref_suspect=bool(_col(z, "ref_suspect", 0))),
        reference_suspect=bool(_col(z, "ref_suspect", 0)),
        zone_quality_ok=(_col(z, "quality_state", "ok") != "bad"),
        escalation=True)
    payload = events.build_payload(
        z, cam_row, state, state, fill,
        thumb_jpeg=_thumb(img, json.loads(z["polygon"])),
        ts=now, duration_s=age, escalation=True, decision=decision)
    db.record_payload(ev_id, payload)
    db.record_decision(ev_id, decision)
    with db.tx() as con:
        con.execute("UPDATE zones SET escalated_at=? WHERE id=?", (now, z["id"]))
    log.warning("STILL OUT %s/%s after %.0fs", cam_row["name"], z["name"], age)


def _reference_check(z, cur_desc, refs, cam_name: str) -> None:
    """Is this zone's enrolled reference still believable?"""
    ratio = detector.suspect_ratio(cur_desc, refs)
    count, flagged, changed = detector.step_suspect(
        int(_col(z, "ref_suspect_count", 0) or 0),
        bool(_col(z, "ref_suspect", 0) or 0),
        ratio)
    with db.tx() as con:
        con.execute("UPDATE zones SET ref_suspect=?, ref_suspect_count=? WHERE id=?",
                    (int(flagged), count, z["id"]))
    if not changed:
        return
    enrolled = refs[0].edge_density if refs else 0.0
    payload = events.build_reference_payload(cam_name, z["name"], flagged, ratio, enrolled)
    events.send(payload)
    log.warning("REFERENCE %s %s/%s ratio %.2f",
                "SUSPECT" if flagged else "OK", cam_name, z["name"], ratio)


def evaluate_zones(cam_row, snap_id: int, jpeg: bytes) -> None:
    zones = db.db().execute(
        "SELECT * FROM zones WHERE camera_id=? AND ref_descriptor IS NOT NULL",
        (cam_row["id"],)).fetchall()
    if not zones:
        return
    img = cv2.imdecode(np.frombuffer(jpeg, np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        # A frame that will not even decode used to return silently: no event, no log,
        # no flag. The shelf simply stopped being watched and nobody was told.
        bad = quality.Verdict(False, "black", "the camera sent something that is not a picture", {})
        _quality_transition("cameras", cam_row, False, bad, cam_row["name"], None)
        return

    # ── THE GATE ──────────────────────────────────────────────────────────────────
    # Whole-frame trust first, once per snapshot. A frame that cannot be trusted is not
    # classified at all: the zone keeps its state, its confirmation count is untouched,
    # and no shelf alert is invented out of a dark or blurred picture.
    frame_q = quality.assess_frame(img, cam_row["baseline_png"])
    _quality_transition("cameras", cam_row, frame_q.ok, frame_q, cam_row["name"], None)
    if not frame_q.ok:
        log.info("skipping %s: %s", cam_row["name"], frame_q.detail)
        return

    for z in zones:
        try:
            polygon = json.loads(z["polygon"])
            cur_desc = detector.describe(img, polygon)
            refs = detector.references_from_json(
                z["ref_descriptor"], _col(z, "ref_descriptors"))
            if not refs:
                log.warning("zone %s has no usable reference", z["id"])
                continue
            # P0-4: BEFORE the obstruction gate, deliberately.
            #
            # A zone enrolled on a half-empty shelf sees the missing facings return as
            # colours its reference never contained, and the obstruction check calls
            # that "something is in front of this shelf" — then skips the zone. So the
            # bad reference hid behind a permanent, confidently wrong obstruction alert
            # that sends staff to inspect a shelf where nothing is wrong.
            #
            # Running the check first is safe because the two conditions push the ratio
            # in OPPOSITE directions. Measured against a correct reference: dark coat
            # 0.81, red jacket 0.77, wide dark object 0.34, brightest narrow object
            # 1.145 — an obstruction is a flat thing that destroys texture. A reference
            # captured under-stocked reads 1.42 at 4 of 6 facings and 1.79 at 3. No
            # obstruction reaches the threshold; that is physics, not luck.
            _reference_check(z, cur_desc, refs, cam_row["name"])

            # Obstruction is per zone: a shopper stands in front of ONE shelf, not the
            # aisle. Checked before the fill score is trusted, never after.
            crop_hsv = cv2.cvtColor(detector._crop_zone(img, polygon), cv2.COLOR_BGR2HSV)
            zone_q = _assess_zone_multi(crop_hsv, refs)
            _quality_transition("zones", z, zone_q.ok, zone_q, cam_row["name"], z["name"])
            if not zone_q.ok:
                log.info("skipping zone %s: %s", z["name"], zone_q.detail)
                continue

            fill = detector.fill_score_multi(cur_desc, refs)
        except Exception as e:  # bad polygon / view shifted — surface, don't crash
            log.warning("zone %s eval failed: %s", z["id"], e)
            continue
        observed = detector.classify(fill, z["empty_below"], z["low_below"])
        new_state, pend, pend_n, changed = detector.step_state(
            z["state"], z["pending_state"], z["pending_count"], observed)
        now = time.time()
        since = _col(z, "state_since") or now
        # The age of the state being LEFT. "Stocked for six hours, now empty" is what
        # tells a restocker whether this is a fast mover or a forgotten corner; the
        # event used to carry no clock at all.
        held_for = now - since if changed else None

        with db.tx() as con:
            if changed:
                con.execute(
                    "UPDATE zones SET state=?, pending_state=?, pending_count=?, "
                    "last_fill=?, last_eval_ts=?, state_since=?, escalated_at=NULL "
                    "WHERE id=?",
                    (new_state, pend, pend_n, fill, now, now, z["id"]))
            else:
                con.execute(
                    "UPDATE zones SET state=?, pending_state=?, pending_count=?, "
                    "last_fill=?, last_eval_ts=? WHERE id=?",
                    (new_state, pend, pend_n, fill, now, z["id"]))

        if changed and new_state != "unknown" and z["state"] != "unknown":
            ev_id = db.insert_event(z["id"], z["state"], new_state, fill, snap_id)
            decision = alerting.should_alert(
                new_state,
                detector.confidence(fill, new_state, z["empty_below"], z["low_below"],
                                    ref_suspect=bool(_col(z, "ref_suspect", 0))),
                reference_suspect=bool(_col(z, "ref_suspect", 0)),
                zone_quality_ok=(_col(z, "quality_state", "ok") != "bad"))
            payload = events.build_payload(
                z, cam_row, new_state, z["state"], fill,
                thumb_jpeg=_thumb(img, json.loads(z["polygon"])),
                ts=now, duration_s=held_for, decision=decision)
            db.record_payload(ev_id, payload)
            db.record_decision(ev_id, decision)
            # P0-10: NOT delivered here. deliver() POSTs, and this runs in the capture
            # thread — five zones changing state while the uplink is slow was fifty
            # seconds of blocked capture inside a fifteen-second loop. The row is
            # written with delivered=0 and the budgeted drain sends it, at most one
            # loop later. Fifteen seconds of latency on a shelf alert is nothing; a
            # box that stops looking at its shelves is everything.
            log.info("EVENT %s/%s: %s -> %s (fill %.2f, held %.0fs)",
                     cam_row["name"], z["name"], z["state"], new_state, fill,
                     held_for or 0)
        elif not changed:
            _escalate_if_stale(z, cam_row, new_state, fill, since, now, snap_id, img)


def _thumb(img, polygon, max_w: int = 320) -> bytes:
    pts = np.array(polygon, np.int32)
    x, y, w, h = cv2.boundingRect(pts)
    crop = img[max(y, 0):y + h, max(x, 0):x + w]
    if crop.size == 0:
        crop = img
    scale = max_w / max(crop.shape[1], 1)
    if scale < 1:
        crop = cv2.resize(crop, (int(crop.shape[1] * scale), int(crop.shape[0] * scale)))
    ok, buf = cv2.imencode(".jpg", crop, [cv2.IMWRITE_JPEG_QUALITY, 70])
    return buf.tobytes()


EVENT_KEEP_DAYS = float(os.environ.get("EVENT_KEEP_DAYS", "90"))
PRUNE_EVERY_S = float(os.environ.get("PRUNE_EVERY_S", "3600"))
_last_prune = 0.0


def _drain(started: float) -> dict:
    """The ONLY place this process talks to the network, and it is on a clock.

    Both queues share one budget. Splitting them would let a stalled outbox spend the
    event queue's time, which is precisely the coupling P0-10 exists to remove — and
    events are drained first because a shelf alert outranks a notice that a lens is
    dirty.
    """
    ev = events.retry_undelivered(budget_s=events.RETRY_BUDGET_S)
    spent = time.time() - started
    left = max(0.0, events.RETRY_BUDGET_S - spent)
    ob = events.drain_outbox(budget_s=left) if left > 0 else {
        "attempted": 0, "delivered": 0, "failed": 0, "stopped": "no budget left"}
    return {"events": ev, "outbox": ob}


def _prune_due(now: float) -> None:
    """Trim the events table, at most hourly.

    snapshots have been pruned since v1 and events never were — and since P0-5 each row
    carries the payload as sent, so they are not small. Hourly rather than per-loop
    because this runs in the capture thread and a DELETE across a large table is not
    something to do every fifteen seconds.
    """
    global _last_prune
    if now - _last_prune < PRUNE_EVERY_S:
        return
    _last_prune = now
    n = db.prune_events(EVENT_KEEP_DAYS)
    db.prune_outbox(EVENT_KEEP_DAYS)
    if n:
        log.info("pruned %s delivered events older than %s days", n, EVENT_KEEP_DAYS)


def loop(stop: threading.Event) -> None:
    log.info("scheduler started (loop %ss)", LOOP_S)
    while not stop.is_set():
        try:
            now = time.time()
            for row in db.list_cameras(include_disabled=False):
                if is_due(row, now):
                    capture_one(row)
            _drain(now)
            _prune_due(now)
        except Exception:
            log.exception("scheduler loop error")
        stop.wait(LOOP_S)


def start() -> threading.Event:
    stop = threading.Event()
    t = threading.Thread(target=loop, args=(stop,), daemon=True, name="capture-loop")
    t.start()
    return stop
