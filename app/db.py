"""SQLite (WAL) persistence. One file, journaled, offline-first."""
from __future__ import annotations

import json
import os
import sqlite3
import threading
import time
import uuid
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
-- P0-8. The ONLY source of false negatives this system can ever have.
-- Acknowledgements answer alerts we sent; nothing about them can reveal a shelf that
-- sat empty while the box said nothing, because nobody is asked about an alert that
-- never arrived. Recall is unmeasurable without this table, and a recall figure
-- computed without it would be invented.
CREATE TABLE IF NOT EXISTS missed_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  zone_uid TEXT NOT NULL,
  ts REAL NOT NULL,
  reported_by TEXT DEFAULT '',
  note TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_missed_zone ON missed_reports(zone_uid, ts DESC);
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
    # P0-4. ref_descriptors holds the JSON ARRAY of enrolled views; ref_descriptor
    # (the original single column) stays populated with the newest one so anything
    # still reading it — including a box mid-upgrade — keeps working unchanged.
    ("zones", "ref_descriptors", "TEXT"),
    ("zones", "ref_suspect", "INTEGER DEFAULT 0"),
    ("zones", "ref_suspect_count", "INTEGER DEFAULT 0"),
    ("zones", "enrolled_edge", "REAL"),
    ("zones", "enrolled_ts", "REAL"),
    # P0-5, the temporal layer. Without state_since an event can say a shelf is empty
    # but not for how long, so a shopper's momentary gap and a shelf empty since
    # Tuesday arrive looking identical — and that difference is most of what makes an
    # alert worth sending.
    ("zones", "state_since", "REAL"),
    ("zones", "escalated_at", "REAL"),
    ("events", "uid", "TEXT"),
    ("events", "duration_s", "REAL"),
    ("events", "confidence", "REAL"),
    # The payload AS SENT. retry_undelivered used to rebuild it from columns with a
    # different confidence formula and a fresh timestamp, so a retried event carried a
    # different number and claimed to have happened at retry time. Storing what was
    # built removes the whole class of drift: the retry re-sends it verbatim.
    ("events", "payload", "TEXT"),
    # P0-6, zone identity. Events used to travel keyed on the zone NAME alone, and there
    # was no way to edit a zone at all — the only way to fix a typo was to delete and
    # recreate, which destroys the enrolled reference and orphans every past event in
    # CloudBSS under the old name. zone_uid is minted once and never changes.
    ("zones", "zone_uid", "TEXT"),
    ("zones", "aisle", "TEXT DEFAULT ''"),
    ("zones", "shelf", "TEXT DEFAULT ''"),
    ("zones", "face", "TEXT DEFAULT ''"),
    # The zone→SKU map. CloudBSS's ingest controller says in as many words that joining
    # shelf events to stock "needs zone→SKU mapping"; this is that mapping, captured at
    # the moment someone is standing in front of the shelf drawing the zone.
    ("zones", "skus", "TEXT DEFAULT '[]'"),
    # P0-7, the alert and acknowledgement loop. `alertable` records the gateway's own
    # judgement about whether a person should have been told, and `alert_reason` why
    # not — so "why wasn't I warned?" has an answer months later.
    ("events", "alertable", "INTEGER DEFAULT 0"),
    ("events", "alert_reason", "TEXT DEFAULT ''"),
    # The acknowledgement IS the label. It is the only ground truth this project will
    # ever have: every threshold so far was calibrated on synthetic fixtures, and P0-8
    # cannot evaluate anything without real answers.
    ("events", "ack_verdict", "TEXT DEFAULT ''"),
    ("events", "ack_at", "REAL"),
    ("events", "ack_by", "TEXT DEFAULT ''"),
    ("events", "ack_note", "TEXT DEFAULT ''"),
]


def _ensure_columns(con: sqlite3.Connection) -> None:
    for table, col, decl in _ADDED_COLUMNS:
        have = {r["name"] for r in con.execute(f"PRAGMA table_info({table})")}
        if col not in have:
            con.execute(f"ALTER TABLE {table} ADD COLUMN {col} {decl}")
    _backfill_zone_uids(con)


def _backfill_zone_uids(con: sqlite3.Connection) -> None:
    """Give every pre-P0-6 zone a permanent id.

    An ALTER cannot generate one per row, and a zone without a uid would fall back to
    being identified by its name — which is the exact failure P0-6 exists to end. Done
    here rather than in a one-shot script so a box that upgrades, is restored from a
    backup, and upgrades again still ends up with every zone identified.
    """
    try:
        rows = con.execute("SELECT id FROM zones WHERE zone_uid IS NULL OR zone_uid=''").fetchall()
    except sqlite3.Error:
        return
    for r in rows:
        con.execute("UPDATE zones SET zone_uid=? WHERE id=?", (new_zone_uid(), r["id"]))


def new_zone_uid() -> str:
    """Opaque and permanent. Not derived from the name, the polygon or the camera —
    all three are things an operator is allowed to change without the shelf becoming a
    different shelf."""
    return uuid.uuid4().hex[:16]


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
    """Drop old frames — but never one that a human has passed judgement on.

    P0-7 makes acknowledged events the training set for P0-8, and a label whose image
    has been deleted is not a label, it is a row of numbers nobody can check. At a
    ten-minute cadence `keep=500` is about three and a half days, so without this every
    label older than the working week would have quietly lost its picture. The frames
    are the expensive part to collect and the only part that cannot be recomputed.
    """
    rows = db().execute(
        "SELECT id, path FROM snapshots WHERE camera_id=? AND id NOT IN "
        "  (SELECT snapshot_id FROM events WHERE snapshot_id IS NOT NULL "
        "     AND length(ack_verdict) > 0) "
        "ORDER BY ts DESC LIMIT -1 OFFSET ?",
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


def record_payload(event_id: int, payload: dict) -> None:
    """Store the payload AS BUILT, minus the thumbnail.

    So a retry re-sends it rather than re-deriving it. The old retry path rebuilt the
    payload from columns with a different confidence formula and a fresh timestamp,
    which meant the same event told two different stories depending on whether the
    network happened to be up. The thumbnail is dropped because base64 images would
    bloat the database for a field the retry does not send anyway.
    """
    slim = {k: v for k, v in payload.items() if k != "thumbnail"}
    try:
        with tx() as con:
            con.execute("UPDATE events SET payload=?, uid=?, duration_s=?, confidence=? "
                        "WHERE id=?",
                        (json.dumps(slim), slim.get("uid"), slim.get("duration_s"),
                         slim.get("confidence"), event_id))
    except sqlite3.Error:
        # Recording the payload must never lose the event itself.
        pass


def record_decision(event_id: int, decision) -> None:
    """Persist the alert judgement next to the event it is about."""
    try:
        with tx() as con:
            con.execute("UPDATE events SET alertable=?, alert_reason=? WHERE id=?",
                        (int(decision.alert), decision.reason, event_id))
    except sqlite3.Error:
        pass


def insert_event(zone_id: int, from_state: str, to_state: str, fill: float,
                 snapshot_id: int | None) -> int:
    with tx() as con:
        cur = con.execute(
            "INSERT INTO events (zone_id, ts, from_state, to_state, fill, snapshot_id) "
            "VALUES (?,?,?,?,?,?)",
            (zone_id, time.time(), from_state, to_state, fill, snapshot_id))
        return cur.lastrowid
