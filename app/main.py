"""CloudBSS Vision — Milestone 1 API + dashboard server."""
from __future__ import annotations

import json
import logging
import os
import time

import cv2
import numpy as np
from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import FileResponse, HTMLResponse, Response
from pydantic import BaseModel

from . import auth, db, detector, events, quality, redact, scheduler
from .cal import CameraConfig, available_drivers, discover, get_driver

logging.basicConfig(level=logging.INFO,
                    format="%(asctime)s %(name)s %(levelname)s %(message)s")

# Authentication is declared HERE, on the application, not on each route. Every route
# that exists — and every route anyone adds later — is covered without being remembered.
# tests/test_security.py walks the live route table and fails if one answers unguarded.
#
# The interactive docs are off: an appliance has no use for Swagger, and /openapi.json
# hands an attacker the entire API surface for free.
app = FastAPI(title="CloudBSS Vision M1",
              dependencies=[Depends(auth.require_auth)],
              docs_url=None, redoc_url=None, openapi_url=None)
STATIC = os.path.join(os.path.dirname(__file__), "web", "static")
_stop = None


def check_security_config() -> None:
    """Refuse to put an open box on a shop's network.

    Same reasoning as the delivery check: the dangerous state is not a box that stops,
    it is a box that runs while quietly offering the recorder password to the WiFi.
    """
    fatal, warnings = auth.config_problems()
    for w in warnings:
        logging.warning("SECURITY: %s", w)
    if fatal:
        for f in fatal:
            logging.error("SECURITY: %s", f)
        raise RuntimeError("refusing to start — the gateway would be unprotected: "
                           + " | ".join(fatal))


def check_delivery_config() -> None:
    """Refuse to run a box that cannot report what it sees.

    A gateway with no webhook is not a degraded sensor, it is a silent one: the
    dashboard fills with states, the local event log fills with transitions, and the
    store believes its shelves are being watched while nothing ever leaves the
    building. Crashing on boot is the only failure mode a human notices.
    """
    fatal, warnings = events.config_problems()
    for w in warnings:
        logging.warning("CONFIG: %s", w)
    if fatal:
        for f in fatal:
            logging.error("CONFIG: %s", f)
        raise RuntimeError(
            "refusing to start — the gateway cannot deliver events: " + " | ".join(fatal)
        )
    if events.webhook_url():
        logging.info("delivery: store %r -> %s (token %s)", events.store_id(),
                     events.webhook_url(), "set" if events.token() else "MISSING")


@app.on_event("startup")
def _startup():
    global _stop
    check_security_config()
    check_delivery_config()
    db.db()
    if os.environ.get("DEMO", "0") == "1" and not db.list_cameras():
        with db.tx() as con:
            for i in (1, 2):
                con.execute(
                    "INSERT INTO cameras (name, driver, interval_s) VALUES (?,?,?)",
                    (f"Demo Aisle {i}", "demo", 20))
        logging.info("DEMO=1: seeded 2 synthetic cameras (20s cadence)")
    _stop = scheduler.start()


@app.on_event("shutdown")
def _shutdown():
    if _stop:
        _stop.set()


# ---------- models ----------

class CameraIn(BaseModel):
    name: str
    driver: str
    host: str = ""
    port: int = 80
    username: str = ""
    password: str = ""
    channel: int = 1
    rtsp_url: str = ""
    interval_s: int = 600
    enabled: bool = True


class ZoneIn(BaseModel):
    camera_id: int
    name: str
    polygon: list[list[int]]
    empty_below: float = 0.35
    low_below: float = 0.65


# ---------- pages ----------

@app.get("/", response_class=HTMLResponse)
def index():
    with open(os.path.join(STATIC, "index.html"), encoding="utf-8") as f:
        return f.read()


# ---------- cameras ----------

@app.get("/api/drivers")
def drivers():
    return available_drivers()


@app.get("/api/discover")
def api_discover():
    return discover(timeout=3.0)


@app.get("/api/cameras")
def cameras():
    out = []
    for r in db.list_cameras():
        d = db.row_to_dict(r, secret=r["password"])
        snap = db.latest_snapshot(r["id"])
        d["last_snapshot_id"] = snap["id"] if snap else None
        d["last_snapshot_ts"] = snap["ts"] if snap else None
        out.append(d)
    return out


@app.post("/api/cameras")
def add_camera(c: CameraIn):
    if c.driver not in available_drivers():
        raise HTTPException(400, f"unknown driver; use one of {available_drivers()}")
    with db.tx() as con:
        cur = con.execute(
            "INSERT INTO cameras (name,driver,host,port,username,password,channel,"
            "rtsp_url,interval_s,enabled) VALUES (?,?,?,?,?,?,?,?,?,?)",
            (c.name, c.driver, c.host, c.port, c.username, c.password, c.channel,
             c.rtsp_url, c.interval_s, int(c.enabled)))
        return {"id": cur.lastrowid}


@app.post("/api/cameras/{cam_id}/test")
def test_camera(cam_id: int):
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    if not row:
        raise HTTPException(404, "no such camera")
    cap = get_driver(row["driver"]).probe(scheduler.cam_config(row))
    # probe() builds rtsp://user:pass@host/... for display. Handing that back was a
    # direct disclosure of the recorder's admin password to any caller.
    return redact.scrub_row(dict(cap.__dict__), row["password"])


@app.post("/api/cameras/{cam_id}/capture")
def capture_now(cam_id: int):
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    if not row:
        raise HTTPException(404, "no such camera")
    snap_id = scheduler.capture_one(row)
    if snap_id is None:
        err = db.db().execute("SELECT last_error FROM cameras WHERE id=?",
                              (cam_id,)).fetchone()["last_error"]
        # Already scrubbed on the way in; scrubbed again on the way out, because a
        # single missed call site here hands out the recorder password.
        raise HTTPException(502, f"capture failed: {redact.scrub_secret(err, row['password'])}")
    return {"snapshot_id": snap_id}


@app.delete("/api/cameras/{cam_id}")
def delete_camera(cam_id: int):
    with db.tx() as con:
        con.execute("DELETE FROM zones WHERE camera_id=?", (cam_id,))
        con.execute("DELETE FROM cameras WHERE id=?", (cam_id,))
    return {"ok": True}


# ---------- snapshots ----------

@app.get("/api/snapshots/{snap_id}")
def snapshot(snap_id: int):
    row = db.db().execute("SELECT * FROM snapshots WHERE id=?", (snap_id,)).fetchone()
    if not row or not os.path.exists(row["path"]):
        raise HTTPException(404, "snapshot not found")
    return FileResponse(row["path"], media_type="image/jpeg")


@app.get("/api/cameras/{cam_id}/latest.jpg")
def latest_jpg(cam_id: int, overlay: int = 1):
    snap = db.latest_snapshot(cam_id)
    if not snap:
        raise HTTPException(404, "no snapshot yet")
    if not overlay:
        return FileResponse(snap["path"], media_type="image/jpeg")
    img = cv2.imread(snap["path"])
    if img is None:
        raise HTTPException(500, "snapshot unreadable")
    colors = {"stocked": (80, 180, 60), "low": (30, 160, 235),
              "empty": (46, 16, 200), "unknown": (140, 140, 140)}
    for z in db.db().execute("SELECT * FROM zones WHERE camera_id=?", (cam_id,)):
        pts = np.array(json.loads(z["polygon"]), np.int32)
        col = colors.get(z["state"], (140, 140, 140))
        cv2.polylines(img, [pts], True, col, 2)
        x, y = pts[0]
        label = f'{z["name"]} {z["state"]}' + (
            f' {z["last_fill"]:.2f}' if z["last_fill"] is not None else "")
        cv2.putText(img, label, (int(x), max(int(y) - 6, 12)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, col, 2, cv2.LINE_AA)
    ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return Response(buf.tobytes(), media_type="image/jpeg")


# ---------- zones ----------

@app.get("/api/zones")
def zones(camera_id: int | None = None):
    q, args = "SELECT * FROM zones", ()
    if camera_id:
        q, args = q + " WHERE camera_id=?", (camera_id,)
    return [db.row_to_dict(r) for r in db.db().execute(q + " ORDER BY id", args)]


# P0-4. The runbook says "shelf faces only, 3–8 per camera" and nothing enforced it,
# so a zone could cover half an aisle and the detector would average four shelves into
# one number that never moves. These bounds are what is actually measurable from the
# geometry, and they are honest about their limits: they catch a zone drawn round a
# whole gondola or a sliver too small to score, and they cannot tell one shelf face
# from two stacked ones. That distinction needs the operator's eyes.
ZONE_MAX_FRAME_FRACTION = 0.35
ZONE_MIN_FRAME_FRACTION = 0.004

# Enough for morning / midday / evening / night on a store that runs long hours.
# Unbounded would let a nightly re-enrol script grow the row without limit and slow
# every evaluation, since each frame is scored against every reference.
MAX_REFERENCES = 6


def _zcol(row, name: str, default=None):
    """sqlite3.Row raises on an unknown key, so a box mid-upgrade needs this."""
    try:
        return row[name]
    except (IndexError, KeyError):
        return default


def _check_zone_geometry(camera_id: int, polygon: list) -> None:
    snap = db.latest_snapshot(camera_id)
    if not snap:
        return                          # nothing to measure against yet; not a reason to block
    img = cv2.imread(snap["path"])
    if img is None:
        return
    frame_area = float(img.shape[0] * img.shape[1])
    area = abs(cv2.contourArea(np.array(polygon, dtype=np.int32)))
    frac = area / frame_area if frame_area else 0.0
    if frac > ZONE_MAX_FRAME_FRACTION:
        raise HTTPException(400, (
            f"this zone covers {frac:.0%} of the camera's view — that is a gondola, not a "
            f"shelf face. Several shelves averaged into one score never moves: draw one "
            f"zone per shelf face."))
    if frac < ZONE_MIN_FRAME_FRACTION:
        raise HTTPException(400, (
            f"this zone covers {frac:.2%} of the view — too few pixels to score reliably. "
            f"Zoom the camera or draw a larger zone."))


@app.post("/api/zones")
def add_zone(z: ZoneIn):
    if len(z.polygon) < 3:
        raise HTTPException(400, "polygon needs at least 3 points")
    _check_zone_geometry(z.camera_id, z.polygon)
    with db.tx() as con:
        cur = con.execute(
            "INSERT INTO zones (camera_id,name,polygon,empty_below,low_below) "
            "VALUES (?,?,?,?,?)",
            (z.camera_id, z.name, json.dumps(z.polygon), z.empty_below, z.low_below))
        return {"id": cur.lastrowid}


@app.post("/api/zones/{zone_id}/enroll")
def enroll_zone(zone_id: int, replace: bool = False, force: bool = False):
    """Capture the current view as this zone's FULLY STOCKED reference.

    P0-4 changes two things about what that sentence means.

    **It can refuse.** A frame too dark, too blurred or too washed out to SCORE is
    certainly too poor to become the definition of "full" for the life of the zone.
    The gate that guards scoring now guards enrolment, which is where a bad frame does
    permanent rather than momentary damage. `force=true` overrides it for an operator
    who knows the aisle is genuinely dim and wants that to be the reference.

    **It appends.** Store lighting changes through the day, and a single reference makes
    that read as stock movement. Enrol the same zone morning and evening and each frame
    is scored against whichever view it resembles most. `replace=true` discards the
    older ones — for when the shelf has been re-planogrammed and the old views are
    describing a different product.

    What it deliberately does NOT do is judge whether the shelf is full. That question
    has no answer from one frame with nothing to compare against, which is precisely
    what enrolment is creating — measured on the fixtures, lighting moves every
    single-frame stock proxy further than stock does. A reference taken on a
    half-empty shelf is caught later instead, in operation, by the suspect-ratio check
    in the scheduler.
    """
    z = db.db().execute("SELECT * FROM zones WHERE id=?", (zone_id,)).fetchone()
    if not z:
        raise HTTPException(404, "no such zone")
    snap = db.latest_snapshot(z["camera_id"])
    if not snap:
        raise HTTPException(409, "no snapshot for this camera yet — capture first")
    img = cv2.imread(snap["path"])
    if img is None:
        raise HTTPException(409, "the stored snapshot is not a readable image")

    # No baseline is passed: enrolment IS the baseline, so movement and lighting-drift
    # checks have nothing to compare against and would be meaningless here. The
    # absolute backstops — black, dark, blown out, blurred — are what apply.
    verdict = quality.assess_frame(img, None)
    if not verdict.ok and not force:
        raise HTTPException(409, {
            "error": "frame not good enough to enrol",
            "reason": verdict.reason,
            "detail": verdict.detail,
            "hint": "fix the view and capture again, or repeat with ?force=true to "
                    "enrol anyway and accept this as the definition of a full shelf",
            "metrics": verdict.metrics,
        })

    try:
        desc = detector.describe(img, json.loads(z["polygon"]))
    except ValueError as e:
        raise HTTPException(400, str(e))
    # P0-3: enrolling a zone also fixes the camera's reference VIEW — the picture that
    # later frames are compared against for movement and for lighting. Without it the
    # gate has nothing to measure "since when" against, and a camera nudged by a cleaner
    # goes on reporting STOCKED for ever against a reference describing another shelf.
    existing = [] if replace else detector.references_from_json(
        z["ref_descriptor"], _zcol(z, "ref_descriptors"))
    refs = existing + [desc]
    refs = refs[-MAX_REFERENCES:]
    refs_json = json.dumps([{"edge_density": r.edge_density, "hist": r.hist} for r in refs])

    baseline = quality.baseline_signature(img)
    with db.tx() as con:
        # ref_descriptor keeps the newest view so a box whose migration has not run,
        # and anything else still reading the single column, behaves exactly as before.
        # ref_suspect is cleared: re-enrolling is the operator answering the complaint,
        # and leaving the flag set would keep alarming about a reference they replaced.
        con.execute("UPDATE zones SET ref_descriptor=?, ref_descriptors=?, state='stocked', "
                    "pending_state='', pending_count=0, quality_state='unknown', "
                    "quality_reason='', quality_detail='', ref_suspect=0, "
                    "ref_suspect_count=0, enrolled_edge=?, enrolled_ts=? WHERE id=?",
                    (desc.to_json(), refs_json, desc.edge_density, time.time(), zone_id))
        con.execute("UPDATE cameras SET baseline_png=?, quality_state='unknown', "
                    "quality_reason='', quality_detail='' WHERE id=?",
                    (baseline, z["camera_id"]))
    return {"ok": True, "edge_density": desc.edge_density,
            "references": len(refs),
            "replaced": bool(replace),
            "forced": bool(force and not verdict.ok),
            "baseline_captured": bool(baseline),
            "frame": quality.frame_metrics(img)}


@app.delete("/api/zones/{zone_id}")
def delete_zone(zone_id: int):
    with db.tx() as con:
        con.execute("DELETE FROM zones WHERE id=?", (zone_id,))
    return {"ok": True}


# ---------- events / dashboard ----------

@app.get("/api/events")
def api_events(limit: int = 50):
    rows = db.db().execute(
        "SELECT e.*, z.name AS zone_name, c.name AS camera_name FROM events e "
        "JOIN zones z ON z.id=e.zone_id JOIN cameras c ON c.id=z.camera_id "
        "ORDER BY e.ts DESC LIMIT ?", (limit,)).fetchall()
    return [dict(r) for r in rows]


@app.post("/api/selftest")
def api_selftest():
    """Prove the uplink without a camera, a zone or a shelf.

    `visionctl selftest` calls this. It delivers one clearly-marked event through the
    real path and reports whether CloudBSS took it — the check to run the minute a box
    is plugged in, and before anyone leaves the shop.
    """
    return events.selftest()


@app.get("/api/health")
def health():
    con = db.db()
    return {
        "ok": True, "ts": time.time(),
        "cameras": len(db.list_cameras()),
        "zones": con.execute("SELECT COUNT(*) c FROM zones").fetchone()["c"],
        # What a person actually wants to know before trusting a quiet dashboard:
        # how much of this box is currently blind.
        "cameras_offline": con.execute(
            "SELECT COUNT(*) c FROM cameras WHERE health_state='offline'").fetchone()["c"],
        "cameras_bad_image": con.execute(
            "SELECT COUNT(*) c FROM cameras WHERE quality_state='bad'").fetchone()["c"],
        "zones_obstructed": con.execute(
            "SELECT COUNT(*) c FROM zones WHERE quality_state='bad'").fetchone()["c"],
    }
