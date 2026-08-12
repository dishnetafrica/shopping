"""P0-7 — deciding who gets woken, and recording what they said back.

Everything before this milestone made the box more honest. None of it made the box
useful to a shopkeeper, and none of it produced a single fact about the real world.

Two claims here:

  1. **Not every event deserves a person.** Until now CloudBSS received every state
     change with no way to tell an alert from a log line, so either everything woke
     somebody or nothing did. The gateway now states its judgement AND its reason —
     "why wasn't I warned?" has an answer.

  2. **An acknowledgement is the only ground truth this project will ever have.** Every
     threshold in P0-3 to P0-6 was calibrated on synthetic aisles; P0-5's confidence is
     explicitly uncalibrated. One shopkeeper saying "no, that shelf was full" outweighs
     any further tuning against fixtures — which is why the label, and the FRAME behind
     it, must survive.
"""
from __future__ import annotations

import base64
import json
import time

import cv2
import pytest
from fastapi.testclient import TestClient

from app import alerting, db, detector, events, quality, scheduler
from tests import frames as F

USER, PASS = "vision", "a-long-enough-password"


def _auth() -> dict:
    return {"Authorization": "Basic " + base64.b64encode(f"{USER}:{PASS}".encode()).decode()}


def desc(img):
    return detector.describe(img, F.ZONE)


def aisle(mid, **kw):
    return F.as_jpeg(F.aisle(facings=(F.FACINGS, mid, F.FACINGS), **kw))


def _jpeg(img):
    return cv2.imencode(".jpg", img)[1].tobytes()


# ── 1. who deserves a person ──────────────────────────────────────────────────────

def test_an_empty_shelf_is_worth_telling_somebody():
    d = alerting.should_alert("empty", 0.9, reference_suspect=False)
    assert d.alert and d.reason == "actionable"


def test_a_restock_is_recorded_not_alerted():
    """Good news at 6am is not an alert."""
    d = alerting.should_alert("stocked", 0.9, reference_suspect=False)
    assert not d.alert and d.reason == "not_actionable"


def test_a_suspect_reference_suppresses_the_alert_first_of_all():
    """The P0-4 case. A zone that may not be watching its shelf produces
    confident-looking numbers about nothing — sending a shopkeeper to a full shelf is
    how they stop believing the next alert."""
    d = alerting.should_alert("empty", 0.99, reference_suspect=True)
    assert not d.alert and d.reason == "reference_suspect"
    assert "re-enrol" in d.detail


def test_suspect_reference_beats_every_other_consideration():
    """Order matters: it is the only check that says the READING cannot be trusted."""
    d = alerting.should_alert("empty", 0.99, reference_suspect=True,
                              zone_quality_ok=False, escalation=True)
    assert d.reason == "reference_suspect"


def test_an_unreadable_zone_does_not_alert():
    d = alerting.should_alert("empty", 0.9, reference_suspect=False, zone_quality_ok=False)
    assert not d.alert and d.reason == "zone_quality"


def test_a_marginal_reading_does_not_alert():
    d = alerting.should_alert("empty", 0.05, reference_suspect=False)
    assert not d.alert and d.reason == "low_confidence"
    assert "0.05" in d.detail


def test_the_confidence_floor_is_a_floor_not_a_ceiling():
    assert alerting.should_alert("empty", alerting.MIN_CONFIDENCE, False).alert
    assert not alerting.should_alert("empty", alerting.MIN_CONFIDENCE - 0.01, False).alert


def test_a_missing_confidence_does_not_block_the_alert():
    """A pre-P0-5 event has no confidence. Absence of evidence is not evidence."""
    assert alerting.should_alert("empty", None, reference_suspect=False).alert


def test_escalation_is_its_own_reason():
    d = alerting.should_alert("empty", 0.9, False, escalation=True)
    assert d.alert and d.reason == "still_out"
    assert "nobody has restocked" in d.detail


def test_a_low_shelf_is_alertable():
    assert alerting.should_alert("low", 0.9, False).alert


def test_unknown_is_never_an_alert():
    assert not alerting.should_alert("unknown", 0.9, False).alert


def test_the_decision_travels_as_a_dict():
    d = alerting.should_alert("empty", 0.9, False)
    assert d.as_dict()["alertable"] is True
    assert d.as_dict()["alert_reason"] == "actionable"


# ── 2. verdicts a real person would send ──────────────────────────────────────────

@pytest.mark.parametrize("raw", ["true", "TRUE", " Yes ", "y", "1", "correct", "real"])
def test_affirmative_verdicts(raw):
    assert alerting.normalise_verdict(raw) == "true"


@pytest.mark.parametrize("raw", ["false", "No", "n", "0", "wrong", "not empty"])
def test_negative_verdicts(raw):
    assert alerting.normalise_verdict(raw) == "false"


@pytest.mark.parametrize("raw", ["", "   ", "maybe", "ok", None, "asdf"])
def test_unusable_verdicts_are_refused_not_guessed(raw):
    """A guessed label is worse than no label — it poisons the only ground truth."""
    assert alerting.normalise_verdict(raw) == ""


# ── 3. the decision reaches the payload and the row ───────────────────────────────

def _cam_with_zone(ref_img, **cols):
    base = {"ref_suspect": 0, "quality_state": "ok"}
    base.update(cols)
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state,"
            "zone_uid,skus,ref_suspect,quality_state) "
            "VALUES (?,?,?,?,'stocked','uid0000000000001',?,?,?)",
            (cam, "Sugar level 2", json.dumps(F.ZONE), desc(ref_img).to_json(),
             json.dumps(["SUG-1KG"]), base["ref_suspect"], base["quality_state"]))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(ref_img), cam))
    return db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()


def _capture(monkeypatch):
    """Observe what the box QUEUED, not what it posted.

    P0-10 removed HTTP from the capture thread: evaluate_zones records the payload and
    the budgeted drain sends it a loop later. So intercepting events.deliver no longer
    sees anything. Watching record_payload/send is stronger anyway — it asserts the
    durable row, which is what actually reaches CloudBSS.
    """
    sent = []
    real = db.record_payload
    monkeypatch.setattr(scheduler.db, "record_payload",
                        lambda eid, p: (sent.append(p), real(eid, p))[1])
    monkeypatch.setattr(scheduler.events, "send", lambda p: (sent.append(p), True)[1])
    return sent


def test_a_real_out_of_stock_is_marked_alertable(fresh_box, monkeypatch):
    sent = _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    shelf = [p for p in sent if p["event"].startswith("shelf.")]
    assert shelf and shelf[0]["alertable"] is True
    row = db.db().execute("SELECT alertable, alert_reason FROM events").fetchone()
    assert row["alertable"] == 1 and row["alert_reason"] == "actionable"


def test_a_suspect_flag_clears_itself_before_an_out_of_stock_alert(fresh_box, monkeypatch):
    """Documenting real behaviour I expected to be different, because it matters.

    A zone is flagged suspect for reading FULLER than its reference. The moment it reads
    at or below that reference — which is exactly when it would report empty — P0-4's
    step_suspect clears the flag. So the reference_suspect suppression essentially never
    fires on an out-of-stock event reached through the state machine.

    That turns out to be correct rather than a hole. A zone enrolled on a half-empty
    shelf under-reports DEPLETION; when it finally reads empty, the shelf really is
    emptier than an already-low reference, so the alert is trustworthy. The operator is
    still told to re-enrol, by the separate zone.reference_suspect event.

    Written down because the opposite assumption is the natural one, and a future reader
    finding the suppression "not working" should find this test rather than 'fix' it.
    """
    sent = _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6), ref_suspect=1)
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    shelf = [p for p in sent if p["event"].startswith("shelf.")]
    assert shelf, "the event must still be delivered"
    assert shelf[0]["alertable"] is True
    assert db.db().execute("SELECT ref_suspect FROM zones").fetchone()["ref_suspect"] == 0


def test_a_suspect_zone_does_not_escalate(fresh_box, monkeypatch):
    """Where the suppression DOES apply: the escalation path reads the zone row as it
    stood at the start of the evaluation, so a zone still under suspicion does not get
    to nag a shopkeeper about a shelf it may not be watching."""
    sent = _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    with db.tx() as con:
        con.execute("UPDATE zones SET ref_suspect=1, state_since=?, escalated_at=NULL",
                    (time.time() - detector.ESCALATE_AFTER_S - 60,))
    sent.clear()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))

    esc = [p for p in sent if p["event"] == events.STILL_OUT]
    assert esc, "the escalation must still be recorded"
    assert esc[0]["alertable"] is False
    assert esc[0]["alert_reason"] == "reference_suspect"


def test_a_restock_event_is_delivered_but_not_alertable(fresh_box, monkeypatch):
    sent = _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    sent.clear()
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(6)))
    shelf = [p for p in sent if p["event"].startswith("shelf.")]
    assert shelf and shelf[0]["to_state"] == "stocked"
    assert shelf[0]["alertable"] is False


def test_the_stored_payload_keeps_the_decision(fresh_box, monkeypatch):
    _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    stored = json.loads(db.db().execute("SELECT payload FROM events").fetchone()["payload"])
    assert stored["alertable"] is True


# ── 4. the acknowledgement, through the API ───────────────────────────────────────

@pytest.fixture
def box(clean_env, fresh_box, monkeypatch):
    monkeypatch.setenv("ADMIN_USER", USER)
    monkeypatch.setenv("ADMIN_PASSWORD", PASS)
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    monkeypatch.setenv("ALLOW_NO_WEBHOOK", "1")
    monkeypatch.setenv("SNAP_DIR", str(fresh_box / "snapshots"))
    monkeypatch.delenv("ALLOW_NO_AUTH", raising=False)
    from app.main import app
    with TestClient(app) as c:
        yield c, fresh_box


def _one_event(monkeypatch, snapshot_id=None):
    monkeypatch.setattr(scheduler.events, "deliver", lambda eid, p: None)
    monkeypatch.setattr(scheduler.events, "send", lambda p: True)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, snapshot_id, _jpeg(aisle(0)))
    return db.db().execute("SELECT uid FROM events").fetchone()["uid"]


def test_a_person_can_confirm_an_alert(box, monkeypatch):
    client, _ = box
    uid = _one_event(monkeypatch)
    r = client.post(f"/api/events/{uid}/ack", headers=_auth(),
                    json={"verdict": "yes", "by": "Mary", "note": "sugar gone by 9"})
    assert r.status_code == 200 and r.json()["verdict"] == "true"
    row = db.db().execute("SELECT * FROM events WHERE uid=?", (uid,)).fetchone()
    assert row["ack_verdict"] == "true" and row["ack_by"] == "Mary"
    assert row["ack_at"] > 0 and "sugar gone" in row["ack_note"]


def test_a_person_can_call_it_a_false_alarm(box, monkeypatch):
    client, _ = box
    uid = _one_event(monkeypatch)
    client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "no"})
    row = db.db().execute("SELECT ack_verdict FROM events WHERE uid=?", (uid,)).fetchone()
    assert row["ack_verdict"] == "false"


def test_a_person_may_correct_themselves(box, monkeypatch):
    """Refusing the correction would keep the wrong label for ever."""
    client, _ = box
    uid = _one_event(monkeypatch)
    client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "yes"})
    client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "no"})
    row = db.db().execute("SELECT ack_verdict FROM events WHERE uid=?", (uid,)).fetchone()
    assert row["ack_verdict"] == "false"


def test_an_unusable_verdict_is_refused_with_a_hint(box, monkeypatch):
    client, _ = box
    uid = _one_event(monkeypatch)
    r = client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "maybe"})
    assert r.status_code == 422
    assert "true/yes" in r.json()["detail"]["hint"]
    row = db.db().execute("SELECT ack_verdict FROM events WHERE uid=?", (uid,)).fetchone()
    assert row["ack_verdict"] == "", "a guess was recorded as a label"


def test_acknowledging_an_unknown_event_is_a_404(box):
    client, _ = box
    assert client.post("/api/events/nosuchuid/ack", headers=_auth(),
                       json={"verdict": "yes"}).status_code == 404


# ── 5. the label set ──────────────────────────────────────────────────────────────

def test_labels_export_counts_both_verdicts(box, monkeypatch):
    client, _ = box
    uid = _one_event(monkeypatch)
    client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "yes"})
    out = client.get("/api/labels", headers=_auth()).json()
    assert out["acknowledged"] == 1 and out["true_positives"] == 1
    assert out["false_positives"] == 0
    lab = out["labels"][0]
    assert lab["zone_uid"] == "uid0000000000001"
    assert lab["skus"] == ["SUG-1KG"]
    assert lab["ack_verdict"] == "true"


def test_unacknowledged_events_are_excluded_by_default(box, monkeypatch):
    client, _ = box
    _one_event(monkeypatch)
    assert client.get("/api/labels", headers=_auth()).json()["count"] == 0
    assert client.get("/api/labels?only_acked=false", headers=_auth()).json()["count"] >= 1


def test_the_export_reports_labels_whose_frame_is_gone(box, monkeypatch):
    """A label with no image cannot be re-scored, and silently counting it as usable
    would overstate the training set P0-8 thinks it has."""
    client, _ = box
    uid = _one_event(monkeypatch)           # snapshot_id is None here
    client.post(f"/api/events/{uid}/ack", headers=_auth(), json={"verdict": "yes"})
    out = client.get("/api/labels", headers=_auth()).json()
    assert out["frames_missing"] == 1
    assert out["labels"][0]["frame_available"] is False


# ── 6. the frame behind the label must survive ────────────────────────────────────

def _snapshot(cam_id, root, n=1):
    ids = []
    snaps = root / "snapshots"
    snaps.mkdir(parents=True, exist_ok=True)
    for i in range(n):
        p = str(snaps / f"c{cam_id}_{i}.jpg")
        cv2.imwrite(p, F.as_jpeg(F.aisle(facings=F.FULL)))
        with db.tx() as con:
            ids.append(con.execute(
                "INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                (cam_id, time.time() + i, p)).lastrowid)
    return ids


def test_pruning_never_deletes_the_frame_behind_a_label(fresh_box):
    """At a ten-minute cadence keep=500 is three and a half days. Without this every
    label older than the working week would quietly lose its picture — and a label
    without its frame is a row of numbers nobody can check."""
    db.db()
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver) VALUES ('A','demo')").lastrowid
        zid = con.execute("INSERT INTO zones (camera_id,name,polygon) VALUES (?,?,?)",
                          (cam, "Z", json.dumps(F.ZONE))).lastrowid
    ids = _snapshot(cam, fresh_box, 6)
    oldest = ids[0]
    with db.tx() as con:
        con.execute("INSERT INTO events (zone_id, ts, to_state, snapshot_id, ack_verdict) "
                    "VALUES (?,?, 'empty', ?, 'true')", (zid, time.time(), oldest))

    db.prune_snapshots(cam, keep=2)

    kept = {r["id"] for r in db.db().execute("SELECT id FROM snapshots")}
    assert oldest in kept, "the frame behind an acknowledged label was deleted"


def test_pruning_still_removes_unlabelled_frames(fresh_box):
    """The protection must not turn into 'never prune anything'."""
    db.db()
    with db.tx() as con:
        cam = con.execute("INSERT INTO cameras (name, driver) VALUES ('A','demo')").lastrowid
    ids = _snapshot(cam, fresh_box, 6)
    db.prune_snapshots(cam, keep=2)
    kept = {r["id"] for r in db.db().execute("SELECT id FROM snapshots")}
    assert len(kept) == 2 and ids[0] not in kept


def test_an_unacknowledged_event_does_not_protect_its_frame(fresh_box):
    """Only judged frames are the training set. Protecting every event's frame would
    mean never pruning at all, and the disk on a shop PC is not infinite."""
    db.db()
    with db.tx() as con:
        cam = con.execute("INSERT INTO cameras (name, driver) VALUES ('A','demo')").lastrowid
        zid = con.execute("INSERT INTO zones (camera_id,name,polygon) VALUES (?,?,?)",
                          (cam, "Z", json.dumps(F.ZONE))).lastrowid
    ids = _snapshot(cam, fresh_box, 6)
    with db.tx() as con:
        con.execute("INSERT INTO events (zone_id, ts, to_state, snapshot_id) "
                    "VALUES (?,?, 'empty', ?)", (zid, time.time(), ids[0]))
    db.prune_snapshots(cam, keep=2)
    kept = {r["id"] for r in db.db().execute("SELECT id FROM snapshots")}
    assert ids[0] not in kept


# ── 7. gaps the mutants found ─────────────────────────────────────────────────────

def test_an_escalation_from_an_unreadable_zone_does_not_alert(fresh_box, monkeypatch):
    """The escalation path builds its own decision, and nothing checked that it passes
    the zone's quality through — the ref_suspect test above short-circuits before
    quality is ever consulted, so a mutant that hardcoded zone_quality_ok=True survived."""
    sent = _capture(monkeypatch)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    with db.tx() as con:
        con.execute("UPDATE zones SET quality_state='bad', state_since=?, escalated_at=NULL",
                    (time.time() - detector.ESCALATE_AFTER_S - 60,))
    sent.clear()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))

    esc = [p for p in sent if p["event"] == events.STILL_OUT]
    assert esc, "the escalation must still be recorded"
    assert esc[0]["alertable"] is False
    assert esc[0]["alert_reason"] == "zone_quality"
