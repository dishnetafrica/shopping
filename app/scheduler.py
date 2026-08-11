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

from . import db, detector, events, redact
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
        with db.tx() as con:
            con.execute("UPDATE cameras SET last_error=? WHERE id=?", (msg, cam.id))
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
        con.execute("UPDATE cameras SET last_ok_ts=?, last_error='' WHERE id=?", (ts, cam.id))
    db.prune_snapshots(cam.id)

    evaluate_zones(row, snap_id, jpeg)
    return snap_id


def evaluate_zones(cam_row, snap_id: int, jpeg: bytes) -> None:
    zones = db.db().execute(
        "SELECT * FROM zones WHERE camera_id=? AND ref_descriptor IS NOT NULL",
        (cam_row["id"],)).fetchall()
    if not zones:
        return
    img = cv2.imdecode(np.frombuffer(jpeg, np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        return
    for z in zones:
        try:
            cur_desc = detector.describe(img, json.loads(z["polygon"]))
            ref = detector.ZoneDescriptor.from_json(z["ref_descriptor"])
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
                due = (row["last_ok_ts"] or 0) + row["interval_s"] <= now
                if due:
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
