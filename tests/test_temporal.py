"""P0-5 — the temporal layer: state_since, duration, escalation, honest confidence, uid.

The audit's complaint was not that events were wrong. It was that they were timeless and
falsely certain:

  * `confidence` was `fill` or `1 - fill`, so an out-of-focus full shelf reported EMPTY
    at **0.987** — the number was highest exactly when the system was most wrong.
  * `retry_undelivered` rebuilt the payload with a DIFFERENT confidence formula and a
    fresh `time.strftime`, so a delayed event arrived claiming to have just happened.
  * No event id, so a delivery that landed but was not recorded would be counted twice.
  * No duration, so "empty" told you nothing about whether anyone was dealing with it.

Every test below is about one of those four.
"""
from __future__ import annotations

import json
import time

import cv2
import pytest

from app import db, detector, events, quality, scheduler
from tests import frames as F


def desc(img):
    return detector.describe(img, F.ZONE)


def aisle(mid, **kw):
    return F.as_jpeg(F.aisle(facings=(F.FACINGS, mid, F.FACINGS), **kw))


def _jpeg(img):
    return cv2.imencode(".jpg", img)[1].tobytes()


# ── 1. confidence that means something ────────────────────────────────────────────

def test_the_audit_case_no_longer_reports_near_certainty():
    """Out-of-focus full shelf: fill 0.013, classified EMPTY, old confidence 0.987.

    P0-3 stops this frame reaching the classifier at all. But if it ever does — a blur
    that squeaks past the gate, a threshold retuned — the confidence must not read as
    near-certain just because the fill is extreme.
    """
    old = round(1.0 - 0.013, 4)
    assert old == 0.987, "documenting the number the audit measured"
    new = detector.confidence(0.013, "empty", 0.35, 0.65)
    assert new > 0.9, "far from the threshold, so high margin is correct here"
    # …and the point: it is now a statement about MARGIN, which a marginal call rebuts.
    assert detector.confidence(0.34, "empty", 0.35, 0.65) < 0.05


def test_a_coin_toss_reads_as_a_coin_toss():
    """fill just under the threshold is the least certain call the system can make."""
    assert detector.confidence(0.349, "empty", 0.35, 0.65) < 0.02
    assert detector.confidence(0.651, "stocked", 0.35, 0.65) < 0.02


def test_confidence_is_monotone_in_the_direction_of_the_state():
    empties = [detector.confidence(f, "empty", 0.35, 0.65) for f in (0.30, 0.20, 0.10, 0.0)]
    assert empties == sorted(empties)
    stocked = [detector.confidence(f, "stocked", 0.35, 0.65) for f in (0.70, 0.80, 0.90, 1.0)]
    assert stocked == sorted(stocked)


def test_confidence_is_bounded():
    for f in (-5.0, 0.0, 0.5, 1.0, 5.0):
        for s in ("empty", "low", "stocked"):
            assert 0.0 <= detector.confidence(f, s, 0.35, 0.65) <= 1.0


def test_low_is_only_confident_in_the_middle_of_its_band():
    """LOW is a two-sided verdict: near either threshold it could go the other way."""
    centre = detector.confidence(0.5, "low", 0.35, 0.65)
    edge_a = detector.confidence(0.36, "low", 0.35, 0.65)
    edge_b = detector.confidence(0.64, "low", 0.35, 0.65)
    assert centre > 0.9 and edge_a < 0.1 and edge_b < 0.1


def test_unknown_state_claims_nothing():
    assert detector.confidence(0.9, "unknown", 0.35, 0.65) == 0.0


def test_a_suspect_reference_caps_confidence_however_wide_the_margin():
    """A zone that may not be watching anything cannot be confident about what it sees."""
    wide = detector.confidence(0.0, "empty", 0.35, 0.65)
    capped = detector.confidence(0.0, "empty", 0.35, 0.65, ref_suspect=True)
    assert wide > 0.9
    assert capped <= detector.SUSPECT_CONFIDENCE_CAP < wide


# ── 2. the uid ────────────────────────────────────────────────────────────────────

def test_uid_is_stable_for_the_same_event(monkeypatch):
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    a = events.event_uid(7, 1_760_000_000.5, "empty")
    b = events.event_uid(7, 1_760_000_000.5, "empty")
    assert a == b, "a retry must not look like a new event"


def test_uid_differs_for_different_events(monkeypatch):
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    base = events.event_uid(7, 1_760_000_000.5, "empty")
    assert events.event_uid(8, 1_760_000_000.5, "empty") != base
    assert events.event_uid(7, 1_760_000_001.5, "empty") != base
    assert events.event_uid(7, 1_760_000_000.5, "stocked") != base


def test_uid_is_scoped_to_the_store(monkeypatch):
    """Two shops must never collide — CloudBSS deduplicates across all of them."""
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    a = events.event_uid(1, 1_760_000_000.0, "empty")
    monkeypatch.setenv("STORE_ID", "kampala-02")
    assert events.event_uid(1, 1_760_000_000.0, "empty") != a


def test_escalation_has_its_own_uid(monkeypatch):
    """Otherwise "still out" would be deduplicated against the original alert."""
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    assert events.event_uid(1, 5.0, "empty") != events.event_uid(1, 5.0, "still:empty")


# ── 3. the timestamp belongs to the event ─────────────────────────────────────────

def _zone_row(**over):
    row = {"id": 1, "name": "Sugar level 2", "empty_below": 0.35, "low_below": 0.65,
           "ref_suspect": 0}
    row.update(over)
    return row


def test_payload_uses_the_events_own_time_not_now():
    long_ago = time.time() - 7200
    p = events.build_payload(_zone_row(), {"name": "Aisle 3"}, "empty", "stocked",
                             0.1, None, ts=long_ago)
    assert p["timestamp"] == events._iso(long_ago)
    assert p["timestamp"] != events._iso(time.time())


def test_duration_travels_in_words_a_person_can_act_on():
    p = events.build_payload(_zone_row(), {"name": "Aisle 3"}, "empty", "stocked",
                             0.1, None, duration_s=21600)
    assert p["duration_s"] == 21600.0
    assert p["duration_human"] == "6 hours"


@pytest.mark.parametrize("secs,expect", [
    (30, "30 seconds"), (600, "10 minutes"), (7200, "2 hours"), (259200, "3 days")])
def test_human_durations(secs, expect):
    assert events._human(secs) == expect


def test_payload_carries_the_thresholds_so_the_number_is_checkable():
    """confidence is a heuristic; shipping its inputs is what keeps it honest."""
    p = events.build_payload(_zone_row(), {"name": "A"}, "empty", "stocked", 0.1, None)
    assert p["empty_below"] == 0.35 and p["low_below"] == 0.65
    assert "not a calibrated probability" in p["confidence_basis"]


def test_payload_marks_a_suspect_reference():
    p = events.build_payload(_zone_row(ref_suspect=1), {"name": "A"}, "empty",
                             "stocked", 0.0, None)
    assert p["reference_suspect"] is True
    assert p["confidence"] <= detector.SUSPECT_CONFIDENCE_CAP


# ── 4. retry sends the same event, not a new story ────────────────────────────────

def _seed_event(fill=0.1, ts=None):
    ts = ts or (time.time() - 3600)
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        zid = con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state) "
            "VALUES (?,?,?,?,'empty')",
            (cam, "Sugar level 2", json.dumps(F.ZONE), desc(aisle(6)).to_json())).lastrowid
        ev = con.execute(
            "INSERT INTO events (zone_id, ts, from_state, to_state, fill, delivered) "
            "VALUES (?,?,?,?,?,0)", (zid, ts, "stocked", "empty", fill)).lastrowid
    return cam, zid, ev, ts


def test_retry_resends_the_stored_payload_verbatim(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    cam, zid, ev, ts = _seed_event()

    zrow = db.db().execute("SELECT * FROM zones WHERE id=?", (zid,)).fetchone()
    crow = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()
    original = events.build_payload(zrow, crow, "empty", "stocked", 0.1, None,
                                    ts=ts, duration_s=1234)
    db.record_payload(ev, original)

    seen = []
    monkeypatch.setattr(events, "post_payload", lambda p: (seen.append(p), (True, "ok"))[1])
    events.retry_undelivered()

    assert len(seen) == 1
    for key in ("uid", "timestamp", "confidence", "duration_s", "to_state", "fill"):
        assert seen[0][key] == original[key], f"{key} changed between send and retry"


def test_retry_does_not_restamp_the_event_with_the_current_time(fresh_box, monkeypatch):
    """The bug: an event delayed an hour arrived claiming to have just happened."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    cam, zid, ev, ts = _seed_event()
    seen = []
    monkeypatch.setattr(events, "post_payload", lambda p: (seen.append(p), (True, "ok"))[1])
    events.retry_undelivered()
    assert seen[0]["timestamp"] == events._iso(ts)


def test_a_pre_p0_5_event_is_rebuilt_without_inventing_a_confidence(fresh_box, monkeypatch):
    """Rows written before this work have no stored payload. Say so, don't guess."""
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    cam, zid, ev, ts = _seed_event()
    seen = []
    monkeypatch.setattr(events, "post_payload", lambda p: (seen.append(p), (True, "ok"))[1])
    events.retry_undelivered()
    assert seen[0]["confidence"] is None
    assert "pre-P0-5" in seen[0]["confidence_basis"]
    assert seen[0]["uid"]


def test_a_corrupt_stored_payload_still_delivers_something(fresh_box, monkeypatch):
    monkeypatch.setenv("EVENT_WEBHOOK_URL", "http://example.invalid/hook")
    cam, zid, ev, ts = _seed_event()
    with db.tx() as con:
        con.execute("UPDATE events SET payload=? WHERE id=?", ("{not json", ev))
    seen = []
    monkeypatch.setattr(events, "post_payload", lambda p: (seen.append(p), (True, "ok"))[1])
    events.retry_undelivered()
    assert len(seen) == 1 and seen[0]["to_state"] == "empty"


# ── 5. the clock, through the scheduler ───────────────────────────────────────────

def _cam_with_zone(ref_img, state="stocked"):
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state) "
            "VALUES (?,?,?,?,?)",
            (cam, "Sugar level 2", json.dumps(F.ZONE), desc(ref_img).to_json(), state))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(ref_img), cam))
    return db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()


def _no_delivery(monkeypatch):
    sent = []
    monkeypatch.setattr(scheduler.events, "deliver",
                        lambda eid, p: sent.append(p))
    monkeypatch.setattr(scheduler.events, "send", lambda p: True)
    return sent


def test_state_since_is_recorded_when_the_shelf_changes(fresh_box, monkeypatch):
    _no_delivery(monkeypatch)
    full = aisle(6)
    cam = _cam_with_zone(full)
    before = time.time()
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    z = db.db().execute("SELECT state, state_since FROM zones").fetchone()
    assert z["state"] == "empty"
    assert z["state_since"] >= before


def test_the_event_says_how_long_the_previous_state_had_lasted(fresh_box, monkeypatch):
    sent = _no_delivery(monkeypatch)
    full = aisle(6)
    cam = _cam_with_zone(full)
    with db.tx() as con:
        con.execute("UPDATE zones SET state_since=?", (time.time() - 6 * 3600,))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    shelf = [p for p in sent if p["event"].startswith("shelf.")]
    assert shelf, "no shelf event"
    assert shelf[0]["duration_human"] == "6 hours"


def test_the_payload_is_stored_for_retry(fresh_box, monkeypatch):
    _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    row = db.db().execute("SELECT uid, payload, confidence FROM events").fetchone()
    assert row["uid"] and row["payload"]
    stored = json.loads(row["payload"])
    assert "thumbnail" not in stored, "base64 images must not be stored on the row"
    assert stored["uid"] == row["uid"], "the column and the payload must agree"
    assert row["confidence"] is not None


# ── 6. escalation ─────────────────────────────────────────────────────────────────

def test_a_shelf_empty_long_enough_is_reported_again(fresh_box, monkeypatch):
    sent = _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    sent.clear()

    # Backdate the state so it has been empty longer than the escalation window.
    with db.tx() as con:
        con.execute("UPDATE zones SET state_since=?",
                    (time.time() - detector.ESCALATE_AFTER_S - 60,))
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))

    esc = [p for p in sent if p["event"] == events.STILL_OUT]
    assert len(esc) == 1
    assert esc[0]["to_state"] == "empty"
    assert "minutes" in esc[0]["duration_human"] or "hours" in esc[0]["duration_human"]


def test_escalation_fires_once_not_on_every_capture(fresh_box, monkeypatch):
    sent = _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    with db.tx() as con:
        con.execute("UPDATE zones SET state_since=?",
                    (time.time() - detector.ESCALATE_AFTER_S - 60,))
    sent.clear()
    for _ in range(6):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    assert len([p for p in sent if p["event"] == events.STILL_OUT]) == 1, \
        "a repeating alarm gets muted, and a muted alarm is worth nothing"


def test_a_freshly_empty_shelf_is_not_escalated(fresh_box, monkeypatch):
    sent = _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 3):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    assert not [p for p in sent if p["event"] == events.STILL_OUT]


def test_restocking_arms_the_escalation_again(fresh_box, monkeypatch):
    """escalated_at must clear on a state change, or a shelf can only ever nag once."""
    sent = _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    with db.tx() as con:
        con.execute("UPDATE zones SET state_since=?, escalated_at=?",
                    (time.time() - 9999, time.time() - 9999))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(6)))
    z = db.db().execute("SELECT state, escalated_at FROM zones").fetchone()
    assert z["state"] == "stocked"
    assert z["escalated_at"] is None


def test_a_stocked_shelf_is_never_escalated(fresh_box, monkeypatch):
    sent = _no_delivery(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    with db.tx() as con:
        con.execute("UPDATE zones SET state='stocked', state_since=?",
                    (time.time() - 10 * 86400,))
    for _ in range(4):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(6)))
    assert not [p for p in sent if p["event"] == events.STILL_OUT]
