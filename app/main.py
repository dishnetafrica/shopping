"""CloudBSS Vision — Milestone 1 API + dashboard server."""
from __future__ import annotations

import json
import logging
import os
import time

import cv2
import numpy as np
from fastapi import FastAPI, HTTPException
from fastapi.responses import FileResponse, HTMLResponse, Response
from pydantic import BaseModel

from . import db, detector, events, scheduler
from .cal import CameraConfig, available_drivers, discover, get_driver

logging.basicConfig(level=logging.INFO,
                    format="%(asctime)s %(name)s %(levelname)s %(message)s")

app = FastAPI(title="CloudBSS Vision M1")
STATIC = os.path.join(os.path.dirname(__file__), "web", "static")
_stop = None


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
        d = db.row_to_dict(r)
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
    return cap.__dict__


@app.post("/api/cameras/{cam_id}/capture")
def capture_now(cam_id: int):
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    if not row:
        raise HTTPException(404, "no such camera")
    snap_id = scheduler.capture_one(row)
    if snap_id is None:
        err = db.db().execute("SELECT last_error FROM cameras WHERE id=?",
                              (cam_id,)).fetchone()["last_error"]
        raise HTTPException(502, f"capture failed: {err}")
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


@app.post("/api/zones")
def add_zone(z: ZoneIn):
    if len(z.polygon) < 3:
        raise HTTPException(400, "polygon needs at least 3 points")
    with db.tx() as con:
        cur = con.execute(
            "INSERT INTO zones (camera_id,name,polygon,empty_below,low_below) "
            "VALUES (?,?,?,?,?)",
            (z.camera_id, z.name, json.dumps(z.polygon), z.empty_below, z.low_below))
        return {"id": cur.lastrowid}


@app.post("/api/zones/{zone_id}/enroll")
def enroll_zone(zone_id: int):
    """Capture the current view as this zone's FULLY STOCKED reference."""
    z = db.db().execute("SELECT * FROM zones WHERE id=?", (zone_id,)).fetchone()
    if not z:
        raise HTTPException(404, "no such zone")
    snap = db.latest_snapshot(z["camera_id"])
    if not snap:
        raise HTTPException(409, "no snapshot for this camera yet — capture first")
    img = cv2.imread(snap["path"])
    try:
        desc = detector.describe(img, json.loads(z["polygon"]))
    except ValueError as e:
        raise HTTPException(400, str(e))
    with db.tx() as con:
        con.execute("UPDATE zones SET ref_descriptor=?, state='stocked', "
                    "pending_state='', pending_count=0 WHERE id=?",
                    (desc.to_json(), zone_id))
    return {"ok": True, "edge_density": desc.edge_density}


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
    return {"ok": True, "ts": time.time(),
            "cameras": len(db.list_cameras()),
            "zones": db.db().execute("SELECT COUNT(*) c FROM zones").fetchone()["c"]}
