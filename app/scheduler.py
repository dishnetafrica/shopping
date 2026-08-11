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

from . import db, detector, events, quality, redact
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
        verdict.detail or "the picture can be read again", verdict.metrics))
    log.info("quality %s/%s: %s -> %s (%s)", cam_name, zone_name or "-", was, now,
             verdict.reason or "ok")


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
            ref = detector.ZoneDescriptor.from_json(z["ref_descriptor"])

            # Obstruction is per zone: a shopper stands in front of ONE shelf, not the
            # aisle. Checked before the fill score is trusted, never after.
            crop_hsv = cv2.cvtColor(detector._crop_zone(img, polygon), cv2.COLOR_BGR2HSV)
            zone_q = quality.assess_zone(crop_hsv, ref.hist, detector.HIST_BINS)
            _quality_transition("zones", z, zone_q.ok, zone_q, cam_row["name"], z["name"])
            if not zone_q.ok:
                log.info("skipping zone %s: %s", z["name"], zone_q.detail)
                continue

            fill = detector.fill_score(cur_desc, ref)
        except Exception as e:  # bad polygon / view shifted — surface, don't crash
            log.warning("zone %s eval failed: %s", z["id"], e)
            continue
        observed = detector.classify(fill, z["empty_below"], z["low_below"])
        new_state, pend, pend_n, changed = detector.step_state(
            z["state"], z["pending_state"], z["pending_count"], observed)
        with db.tx() as con:
            con.execute(
                "UPDATE zones SET state=?, pending_state=?, pending_count=?, "
                "last_fill=?, last_eval_ts=? WHERE id=?",
                (new_state, pend, pend_n, fill, time.time(), z["id"]))
        if changed and new_state != "unknown" and z["state"] != "unknown":
            ev_id = db.insert_event(z["id"], z["state"], new_state, fill, snap_id)
            payload = events.build_payload(z, cam_row, new_state, z["state"], fill,
                                           thumb_jpeg=_thumb(img, json.loads(z["polygon"])))
            events.deliver(ev_id, payload)
            log.info("EVENT %s/%s: %s -> %s (fill %.2f)",
                     cam_row["name"], z["name"], z["state"], new_state, fill)


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


def loop(stop: threading.Event) -> None:
    log.info("scheduler started (loop %ss)", LOOP_S)
    while not stop.is_set():
        try:
            now = time.time()
            for row in db.list_cameras(include_disabled=False):
                if is_due(row, now):
                    capture_one(row)
            events.retry_undelivered()
        except Exception:
            log.exception("scheduler loop error")
        stop.wait(LOOP_S)


def start() -> threading.Event:
    stop = threading.Event()
    t = threading.Thread(target=loop, args=(stop,), daemon=True, name="capture-loop")
    t.start()
    return stop
