"""SQLite (WAL) persistence. One file, journaled, offline-first."""
from __future__ import annotations

import json
import os
import sqlite3
import threading
import time
from contextlib import contextmanager

from . import redact

DB_PATH = os.environ.get("VISION_DB", "data/vision.db")
_lock = threading.Lock()

SCHEMA = """
CREATE TABLE IF NOT EXISTS cameras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  driver TEXT NOT NULL,
  host TEXT DEFAULT '',
  port INTEGER DEFAULT 80,
  username TEXT DEFAULT '',
  password TEXT DEFAULT '',
  channel INTEGER DEFAULT 1,
  rtsp_url TEXT DEFAULT '',
  interval_s INTEGER DEFAULT 600,
  enabled INTEGER DEFAULT 1,
  last_ok_ts REAL,
  last_error TEXT DEFAULT '',
  consecutive_failures INTEGER DEFAULT 0,   -- P0-3: drives backoff and camera.offline
  next_attempt_ts REAL,                     -- P0-3: when this camera may be tried again
  health_state TEXT DEFAULT 'unknown',      -- unknown | online | offline
  quality_state TEXT DEFAULT 'unknown',     -- unknown | ok | bad  (edge-triggered events)
  quality_reason TEXT DEFAULT '',
  quality_detail TEXT DEFAULT '',
  quality_metrics TEXT DEFAULT '',          -- JSON, for the dashboard and for tuning
  baseline_png BLOB                         -- enrolment view: movement + lighting reference
);
CREATE TABLE IF NOT EXISTS snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  camera_id INTEGER NOT NULL,
  ts REAL NOT NULL,
  path TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_snap_cam_ts ON snapshots(camera_id, ts DESC);
CREATE TABLE IF NOT EXISTS zones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  camera_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  polygon TEXT NOT NULL,           -- JSON [[x,y],...] in image pixels
  ref_descriptor TEXT,             -- JSON descriptor of 'fully stocked' reference
  empty_below REAL DEFAULT 0.35,   -- fill-score thresholds
  low_below REAL DEFAULT 0.65,
  state TEXT DEFAULT 'unknown',
  pending_state TEXT DEFAULT '',
  pending_count INTEGER DEFAULT 0,
  last_fill REAL,
  last_eval_ts REAL,
  quality_state TEXT DEFAULT 'unknown',     -- P0-3: obstruction is per zone, not per frame
  quality_reason TEXT DEFAULT '',
  quality_detail TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  zone_id INTEGER NOT NULL,
  ts REAL NOT NULL,
  from_state TEXT, to_state TEXT,
  fill REAL,
  snapshot_id INTEGER,
  delivered INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts DESC);
"""


def connect() -> sqlite3.Connection:
    os.makedirs(os.path.dirname(DB_PATH) or ".", exist_ok=True)
    con = sqlite3.connect(DB_PATH, check_same_thread=False)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA journal_mode=WAL")
    con.execute("PRAGMA synchronous=NORMAL")
    return con


_con = None


# Columns added after v1.1 shipped. CREATE TABLE IF NOT EXISTS does nothing to a table
# that already exists, so a box in the field would keep the old schema and every P0-3
# write would fail. Each is added if missing; SQLite ALTER TABLE ADD COLUMN is cheap and
# non-destructive, and doing it here means there is no migration step for an installer
# to forget.
_ADDED_COLUMNS = [
    ("cameras", "consecutive_failures", "INTEGER DEFAULT 0"),
    ("cameras", "next_attempt_ts", "REAL"),
    ("cameras", "health_state", "TEXT DEFAULT 'unknown'"),
    ("cameras", "quality_state", "TEXT DEFAULT 'unknown'"),
    ("cameras", "quality_reason", "TEXT DEFAULT ''"),
    ("cameras", "quality_detail", "TEXT DEFAULT ''"),
    ("cameras", "quality_metrics", "TEXT DEFAULT ''"),
    ("cameras", "baseline_png", "BLOB"),
    ("zones", "quality_state", "TEXT DEFAULT 'unknown'"),
    ("zones", "quality_reason", "TEXT DEFAULT ''"),
    ("zones", "quality_detail", "TEXT DEFAULT ''"),
]


def _ensure_columns(con: sqlite3.Connection) -> None:
    for table, col, decl in _ADDED_COLUMNS:
        have = {r["name"] for r in con.execute(f"PRAGMA table_info({table})")}
        if col not in have:
            con.execute(f"ALTER TABLE {table} ADD COLUMN {col} {decl}")


def db() -> sqlite3.Connection:
    global _con
    if _con is None:
        _con = connect()
        _con.executescript(SCHEMA)
        _ensure_columns(_con)
        _con.commit()
    return _con


@contextmanager
def tx():
    with _lock:
        con = db()
        try:
            yield con
            con.commit()
        except Exception:
            con.rollback()
            raise


# ---- helpers -------------------------------------------------------------

def row_to_dict(row: sqlite3.Row, secret: str | None = None) -> dict:
    """A row as it is allowed to leave the box.

    `password` was already dropped. What was NOT dropped, and leaked the same secret by
    another route: `rtsp_url`, which for the rtsp driver is the full
    rtsp://user:pass@host/... string, and `last_error`, which carries whatever ffmpeg
    printed — usually that same URL. `username` goes too: half of a credential pair is
    still half a credential, and nothing in the dashboard displays it.

    Pass `secret` when the caller knows the row's password, so it is masked even where
    it appears outside a URL.
    """
    d = dict(row)
    if "polygon" in d and isinstance(d["polygon"], str):
        d["polygon"] = json.loads(d["polygon"])
    d.pop("ref_descriptor", None)
    d.pop("baseline_png", None)
    d.pop("password", None)
    d.pop("username", None)
    return redact.scrub_row(d, secret)


def list_cameras(include_disabled: bool = True) -> list[sqlite3.Row]:
    q = "SELECT * FROM cameras" + ("" if include_disabled else " WHERE enabled=1")
    return db().execute(q + " ORDER BY id").fetchall()


def latest_snapshot(camera_id: int) -> sqlite3.Row | None:
    return db().execute(
        "SELECT * FROM snapshots WHERE camera_id=? ORDER BY ts DESC LIMIT 1",
        (camera_id,)).fetchone()


def prune_snapshots(camera_id: int, keep: int = 500) -> None:
    rows = db().execute(
        "SELECT id, path FROM snapshots WHERE camera_id=? ORDER BY ts DESC LIMIT -1 OFFSET ?",
        (camera_id, keep)).fetchall()
    if not rows:
        return
    with tx() as con:
        con.executemany("DELETE FROM snapshots WHERE id=?", [(r["id"],) for r in rows])
    for r in rows:
        try:
            os.unlink(r["path"])
        except OSError:
            pass


def insert_event(zone_id: int, from_state: str, to_state: str, fill: float,
                 snapshot_id: int | None) -> int:
    with tx() as con:
        cur = con.execute(
            "INSERT INTO events (zone_id, ts, from_state, to_state, fill, snapshot_id) "
            "VALUES (?,?,?,?,?,?)",
            (zone_id, time.time(), from_state, to_state, fill, snapshot_id))
        return cur.lastrowid
