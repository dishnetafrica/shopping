"""P0-4 — enrolment validation, multi-reference, and the silent-blindness alarm.

The audit's worst finding was not that the detector is crude. It was that a zone
enrolled on a shelf that was not full reports STOCKED for ever and nobody is told —
`edge_ratio` saturates at 1.0, so the evidence is computed and discarded on the same
line. A crude detector that says "I don't know" is a product; a confident one that is
permanently blind is a liability.

These tests hold three claims:

  1. Enrolment refuses a frame we would refuse to score, and can be forced past.
  2. A zone can hold several references, and is scored against the best match.
  3. A zone enrolled on an under-stocked shelf is FOUND OUT in operation.

Claim 3 is the one that matters, and it is tested with the numbers from the probe
rather than round ones, so that moving a threshold breaks a test rather than quietly
widening a blind spot.
"""
from __future__ import annotations

import json

import pytest

from app import detector, events
from tests import frames as F


def desc(img):
    return detector.describe(img, F.ZONE)


def aisle(mid, **kw):
    """The gondola with `mid` facings on the middle row, through JPEG as always."""
    return F.as_jpeg(F.aisle(facings=(F.FACINGS, mid, F.FACINGS), **kw))


# ── 1. the ratio that fill_score throws away ──────────────────────────────────────

def test_fill_score_still_saturates_so_the_evidence_must_live_elsewhere():
    """Pin the actual defect, so nobody 'fixes' it by unclipping fill_score.

    Unclipping would make a well-stocked shelf score above 1.0 and break every
    threshold in the product. The clip is correct; discarding the number was not.
    """
    ref_half = desc(aisle(3))
    cur_full = desc(aisle(6))
    assert detector.fill_score(cur_full, ref_half) <= 1.0
    assert detector.edge_ratio_raw(cur_full, ref_half) > 1.5


def test_raw_ratio_grows_as_the_enrolled_reference_gets_worse():
    cur_full = desc(aisle(6))
    ratios = [detector.edge_ratio_raw(cur_full, desc(aisle(n))) for n in (5, 4, 3, 2)]
    assert ratios == sorted(ratios), "a worse reference must read as a bigger ratio"
    assert ratios[0] < 1.25 < ratios[1], (
        "the 5-facing case is deliberately inside the lighting band and the 4-facing "
        f"case deliberately outside it; got {ratios}")


def test_threshold_clears_the_worst_legitimate_reading():
    """A correct enrolment must never trip the alarm on a frame the gate accepts.

    1.115 is the measured worst case (lights 15% up). Anything brighter is refused by
    the quality gate before the detector sees it, so it cannot set this threshold.
    """
    ref = desc(aisle(6))
    worst = max(detector.edge_ratio_raw(desc(aisle(6, bright=b)), ref)
                for b in (1.0, 1.05, 1.10, 1.15))
    assert worst < detector.SUSPECT_ABOVE, f"legitimate ratio {worst} would alarm"


# ── 2. multi-reference ────────────────────────────────────────────────────────────

def test_multi_reference_takes_the_best_match_not_the_average():
    dim, bright = aisle(6, bright=0.85), aisle(6, bright=1.15)
    refs = [desc(dim), desc(bright)]
    for img in (dim, bright):
        assert detector.fill_score_multi(desc(img), refs) > \
               detector.fill_score(desc(img), refs[0]) - 1e-9


def test_multi_reference_still_reports_an_empty_shelf_empty():
    """The point of several references is lighting tolerance, not blanket forgiveness."""
    refs = [desc(aisle(6, bright=0.85)), desc(aisle(6, bright=1.15))]
    fill = detector.fill_score_multi(desc(aisle(0)), refs)
    assert detector.classify(fill, 0.35, 0.6) == "empty"


def test_references_from_json_prefers_the_array_and_never_double_counts():
    a, b = desc(aisle(6)), desc(aisle(6, seed=9))
    many = json.dumps([{"edge_density": r.edge_density, "hist": r.hist} for r in (a, b)])
    assert len(detector.references_from_json(a.to_json(), many)) == 2


def test_references_from_json_falls_back_for_a_box_mid_upgrade():
    a = desc(aisle(6))
    assert len(detector.references_from_json(a.to_json(), None)) == 1


def test_corrupt_reference_array_falls_back_instead_of_blinding_the_zone():
    a = desc(aisle(6))
    assert len(detector.references_from_json(a.to_json(), "{not json")) == 1
    assert detector.references_from_json(None, "{not json") == []


def test_suspect_ratio_is_the_minimum_so_one_good_reference_exonerates():
    """If any enrolled view already explains a frame this full, nothing is wrong."""
    refs = [desc(aisle(6)), desc(aisle(3))]     # one good, one taken half-empty
    assert detector.suspect_ratio(desc(aisle(6)), refs) < detector.SUSPECT_ABOVE


# ── 3. hysteresis on the alarm ────────────────────────────────────────────────────

def test_one_high_frame_does_not_raise_the_alarm():
    count, flagged, changed = detector.step_suspect(0, False, 2.0)
    assert (flagged, changed) == (False, False)


def test_alarm_needs_consecutive_confirmations():
    count, flagged, changed = 0, False, False
    for _ in range(detector.SUSPECT_CONFIRMATIONS):
        count, flagged, changed = detector.step_suspect(count, flagged, 2.0)
    assert flagged and changed


def test_a_normal_frame_resets_the_run():
    count, flagged, _ = detector.step_suspect(0, False, 2.0)
    count, flagged, _ = detector.step_suspect(count, flagged, 2.0)
    count, flagged, _ = detector.step_suspect(count, flagged, 1.0)
    assert count == 0 and not flagged


def test_alarm_fires_once_not_every_frame():
    count, flagged, changed = 0, False, False
    for _ in range(detector.SUSPECT_CONFIRMATIONS):
        count, flagged, changed = detector.step_suspect(count, flagged, 2.0)
    assert changed
    for _ in range(5):
        count, flagged, changed = detector.step_suspect(count, flagged, 2.0)
        assert not changed, "a standing fault must not re-alert every capture"


def test_alarm_clears_after_re_enrolment():
    count, flagged, changed = 0, False, False
    for _ in range(detector.SUSPECT_CONFIRMATIONS):
        count, flagged, changed = detector.step_suspect(count, flagged, 2.0)
    count, flagged, changed = detector.step_suspect(count, flagged, 1.0)
    assert changed and not flagged


# ── 4. end to end: the audit's exact scenario ─────────────────────────────────────

def test_the_audit_scenario_is_now_caught():
    """Enrol at 3 of 6 facings. Shelf is later genuinely full.

    Before P0-4 this zone reported STOCKED for ever with nothing to contradict it.
    """
    refs = [desc(aisle(3))]
    full = desc(aisle(6))

    fill = detector.fill_score_multi(full, refs)
    assert detector.classify(fill, 0.35, 0.6) == "stocked", \
        "the blindness itself is unchanged — this test documents it"

    count, flagged, changed = 0, False, False
    for _ in range(detector.SUSPECT_CONFIRMATIONS):
        ratio = detector.suspect_ratio(full, refs)
        count, flagged, changed = detector.step_suspect(count, flagged, ratio)
    assert flagged and changed, "…but the operator is now told the reference is wrong"


def test_a_correctly_enrolled_zone_is_never_accused():
    refs = [desc(aisle(6))]
    count, flagged = 0, False
    for b in (1.0, 1.05, 1.1, 1.15, 1.0, 0.95, 1.1, 1.05):
        ratio = detector.suspect_ratio(desc(aisle(6, bright=b)), refs)
        count, flagged, _ = detector.step_suspect(count, flagged, ratio)
    assert not flagged


def test_an_emptying_shelf_is_never_mistaken_for_a_bad_reference():
    """The two failures point opposite ways; confusing them would be the worst bug."""
    refs = [desc(aisle(6))]
    count, flagged = 0, False
    for n in (6, 5, 4, 3, 2, 1, 0):
        ratio = detector.suspect_ratio(desc(aisle(n)), refs)
        count, flagged, _ = detector.step_suspect(count, flagged, ratio)
    assert not flagged
    assert detector.classify(detector.fill_score_multi(desc(aisle(0)), refs), 0.35, 0.6) == "empty"


# ── 5. the payload a human reads ──────────────────────────────────────────────────

def test_suspect_payload_says_what_to_do():
    p = events.build_reference_payload("Aisle 3", "Sugar level 2", True, 1.79, 0.034)
    assert p["event"] == events.REFERENCE_SUSPECT
    assert p["zone"] == "Sugar level 2"
    assert "re-enrol" in p["to_state"]
    assert "1.79" in p["detail"]
    assert p["ratio"] == 1.79


def test_recovery_payload_is_a_different_event():
    p = events.build_reference_payload("Aisle 3", "Sugar level 2", False, 1.0, 0.061)
    assert p["event"] == events.REFERENCE_OK
    assert "re-enrol" not in p["to_state"]


@pytest.mark.parametrize("suspect", [True, False])
def test_reference_payload_never_carries_a_fabricated_fill(suspect):
    """The audit found confidence invented from thin air. Not repeating it here:
    this event is about the reference, and says nothing about the shelf."""
    p = events.build_reference_payload("Aisle 3", "Z", suspect, 1.5, 0.05)
    assert "fill" not in p and "confidence" not in p


# ── 6. the endpoint, not just the arithmetic ──────────────────────────────────────
#
# Everything above tests functions. These test the decision an operator actually
# meets: the button either takes the picture or refuses it, and says why.

import base64          # noqa: E402
import time            # noqa: E402

import cv2             # noqa: E402
from fastapi.testclient import TestClient   # noqa: E402

USER, PASS = "vision", "a-long-enough-password"


def _auth() -> dict:
    raw = base64.b64encode(f"{USER}:{PASS}".encode()).decode()
    return {"Authorization": f"Basic {raw}"}


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


def _seed(client, root, img, name="Aisle 3"):
    """A camera with one snapshot on disk, which is what enrolment reads."""
    from app import db

    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, host, channel, enabled) "
            "VALUES (?,?,?,?,1)", (name, "demo", "", 1)).lastrowid
    snaps = root / "snapshots"
    snaps.mkdir(parents=True, exist_ok=True)
    path = str(snaps / f"{cam}-{time.time()}.jpg")
    cv2.imwrite(path, img)
    with db.tx() as con:
        con.execute("INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                    (cam, time.time(), path))
    return cam


def _zone(client, cam, polygon=None):
    r = client.post("/api/zones", headers=_auth(), json={
        "camera_id": cam, "name": "Sugar level 2",
        "polygon": polygon or F.ZONE, "empty_below": 0.35, "low_below": 0.6})
    assert r.status_code == 200, r.text
    return r.json()["id"]


def test_enrolment_refuses_a_frame_it_would_refuse_to_score(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL, blur=6)))
    zid = _zone(client, cam)
    r = client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    assert r.status_code == 409
    body = r.json()["detail"]
    assert body["reason"] == "blurred"
    assert "force=true" in body["hint"]


def test_a_dark_frame_is_refused_too(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL, bright=0.22)))
    zid = _zone(client, cam)
    assert client.post(f"/api/zones/{zid}/enroll", headers=_auth()).status_code == 409


def test_force_enrols_anyway_and_admits_it(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL, blur=6)))
    zid = _zone(client, cam)
    r = client.post(f"/api/zones/{zid}/enroll?force=true", headers=_auth())
    assert r.status_code == 200
    assert r.json()["forced"] is True


def test_a_good_frame_enrols_and_is_not_marked_forced(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    zid = _zone(client, cam)
    r = client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    assert r.status_code == 200
    assert r.json()["forced"] is False and r.json()["references"] == 1


def test_enrolling_twice_keeps_both_views(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    zid = _zone(client, cam)
    client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL, bright=1.1)), name="Aisle 3")
    from app import db
    with db.tx() as con:
        con.execute("UPDATE snapshots SET camera_id=? WHERE camera_id=(SELECT MAX(camera_id) FROM snapshots)", (cam,))
    r = client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    assert r.json()["references"] == 2


def test_replace_discards_the_older_views(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    zid = _zone(client, cam)
    client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    r = client.post(f"/api/zones/{zid}/enroll?replace=true", headers=_auth())
    assert r.json()["references"] == 1 and r.json()["replaced"] is True


def test_references_are_capped(box):
    from app.main import MAX_REFERENCES
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    zid = _zone(client, cam)
    last = 0
    for _ in range(MAX_REFERENCES + 3):
        last = client.post(f"/api/zones/{zid}/enroll", headers=_auth()).json()["references"]
    assert last == MAX_REFERENCES


def test_re_enrolling_clears_a_standing_suspect_flag(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    zid = _zone(client, cam)
    client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    from app import db
    with db.tx() as con:
        con.execute("UPDATE zones SET ref_suspect=1, ref_suspect_count=9 WHERE id=?", (zid,))
    client.post(f"/api/zones/{zid}/enroll", headers=_auth())
    row = db.db().execute("SELECT ref_suspect, ref_suspect_count FROM zones WHERE id=?",
                          (zid,)).fetchone()
    assert row["ref_suspect"] == 0 and row["ref_suspect_count"] == 0


def test_a_zone_covering_the_whole_gondola_is_refused(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    whole = [[5, 5], [F.W - 5, 5], [F.W - 5, F.H - 5], [5, F.H - 5]]
    r = client.post("/api/zones", headers=_auth(), json={
        "camera_id": cam, "name": "everything", "polygon": whole,
        "empty_below": 0.35, "low_below": 0.6})
    assert r.status_code == 400
    assert "shelf face" in r.json()["detail"]


def test_a_sliver_zone_is_refused(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    sliver = [[10, 10], [24, 10], [24, 24], [10, 24]]
    r = client.post("/api/zones", headers=_auth(), json={
        "camera_id": cam, "name": "sliver", "polygon": sliver,
        "empty_below": 0.35, "low_below": 0.6})
    assert r.status_code == 400
    assert "reliably" in r.json()["detail"]


def test_an_ordinary_shelf_face_is_accepted(box):
    client, root = box
    cam = _seed(client, root, F.as_jpeg(F.aisle(facings=F.FULL)))
    assert _zone(client, cam) > 0


# ── 7. the scheduler actually calling it ──────────────────────────────────────────
#
# Two mutants survived the sections above: deleting the _reference_check call, and
# reverting the scheduler to a single reference. Both passed every test here, because
# everything above tests the arithmetic and the endpoint and nothing proved the loop
# that runs every fifteen seconds uses either. A feature nothing invokes is a feature
# that does not exist.

from app import db, quality, scheduler   # noqa: E402


def _jpeg(img):
    return cv2.imencode(".jpg", img)[1].tobytes()


def _cam_with_zone(refs, baseline_img):
    """A camera whose zone was enrolled against `refs`, ready for evaluate_zones."""
    payload = json.dumps([{"edge_density": r.edge_density, "hist": r.hist} for r in refs])
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,ref_descriptors,state) "
            "VALUES (?,?,?,?,?,'stocked')",
            (cam, "Sugar level 2", json.dumps(F.ZONE), refs[-1].to_json(), payload))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(baseline_img), cam))
    return db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()


def _sent(monkeypatch):
    out = []
    monkeypatch.setattr(scheduler.events, "send", lambda p: out.append(p) or True)
    return out


def test_the_scheduler_raises_the_alarm_for_a_zone_enrolled_half_empty(fresh_box, monkeypatch):
    """The audit's scenario, driven through the loop that runs in production."""
    sent = _sent(monkeypatch)
    half, full = aisle(3), aisle(6)
    cam = _cam_with_zone([desc(half)], full)

    for _ in range(detector.SUSPECT_CONFIRMATIONS):
        scheduler.evaluate_zones(cam, None, _jpeg(full))

    assert any(p["event"] == events.REFERENCE_SUSPECT for p in sent), \
        "the scheduler never told anyone the reference was wrong"
    z = db.db().execute("SELECT ref_suspect FROM zones").fetchone()
    assert z["ref_suspect"] == 1


def test_the_scheduler_leaves_a_correctly_enrolled_zone_alone(fresh_box, monkeypatch):
    sent = _sent(monkeypatch)
    full = aisle(6)
    cam = _cam_with_zone([desc(full)], full)

    for _ in range(detector.SUSPECT_CONFIRMATIONS + 2):
        scheduler.evaluate_zones(cam, None, _jpeg(full))

    assert not any(p["event"] == events.REFERENCE_SUSPECT for p in sent)


def test_the_scheduler_scores_against_every_reference_not_just_one(fresh_box, monkeypatch):
    """A full shelf under evening light must score as full, not merely pass.

    Measured: scored against a brighter reference alone, an identical full shelf reads
    0.891; against both enrolled views it reads 1.000. Neither crosses the LOW
    threshold today, which is exactly why this asserts the NUMBER rather than the
    state — the damage from a single reference is eroded margin, not an immediate
    misread. A zone sitting at 0.891 on a genuinely full shelf has spent a third of its
    headroom on the time of day, and crosses into LOW on a stock dip that a
    properly-enrolled zone would shrug off.

    Asserting only the state here let the "scheduler scores against one reference"
    mutant survive.
    """
    _sent(monkeypatch)
    monkeypatch.setattr(scheduler.events, "deliver", lambda *a, **k: None)
    dim, bright = aisle(6, bright=0.92), aisle(6, bright=1.10)
    cam = _cam_with_zone([desc(bright), desc(dim)], dim)

    scheduler.evaluate_zones(cam, None, _jpeg(dim))
    z = db.db().execute("SELECT state, last_fill FROM zones").fetchone()
    assert z["state"] == "stocked"
    assert z["last_fill"] > 0.95, (
        f"full shelf scored {z['last_fill']} — that is the single-reference number, so "
        f"the scheduler is not using every enrolled view")


def test_the_scheduler_still_reports_an_empty_shelf_with_several_references(fresh_box, monkeypatch):
    """Multi-reference must not become a way of never reporting anything.

    Asserted against the `events` TABLE rather than the webhook, because that is the
    durable record — shelf events go out through events.deliver, and an earlier draft
    of this test watched events.send and passed for the wrong reason.
    """
    _sent(monkeypatch)
    monkeypatch.setattr(scheduler.events, "deliver", lambda *a, **k: None)
    cam = _cam_with_zone([desc(aisle(6, bright=0.88)), desc(aisle(6, bright=1.12))],
                         aisle(6, bright=1.12))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0, bright=1.0)))

    rows = db.db().execute("SELECT to_state FROM events").fetchall()
    assert [r["to_state"] for r in rows] == ["empty"], \
        f"an empty shelf went unreported (events: {[dict(r) for r in rows]})"


def test_a_real_obstruction_is_never_called_a_bad_reference(fresh_box, monkeypatch):
    """The guarantee that lets the reference check run before the obstruction gate.

    An obstruction is a flat object that DESTROYS texture, so it drives the ratio down
    (measured: 0.34–1.15). A reference captured under-stocked drives it up (1.42–2.36).
    If that ever stopped holding, the check would have to move back after the gate and
    bad references would hide behind obstruction alerts again.
    """
    sent = _sent(monkeypatch)
    monkeypatch.setattr(scheduler.events, "deliver", lambda *a, **k: None)
    full = aisle(6)
    cam = _cam_with_zone([desc(full)], full)
    for occ in [(240, 380, (60, 60, 70)), (240, 380, (230, 230, 230)),
                (120, 500, (60, 60, 70)), (300, 340, (250, 250, 250)),
                (240, 380, (40, 40, 190)), (120, 500, (235, 235, 235))]:
        scheduler.evaluate_zones(
            cam, None, _jpeg(F.as_jpeg(F.aisle(facings=F.FULL, occluder=occ))))

    assert not any(p["event"] == events.REFERENCE_SUSPECT for p in sent), \
        "a shopper standing in front of the shelf was blamed on the reference"
    assert db.db().execute("SELECT ref_suspect FROM zones").fetchone()["ref_suspect"] == 0


def test_the_reference_payload_carries_the_store_id(monkeypatch):
    """Every payload must say which shop it came from — CloudBSS routes on it.

    This assertion exists because the "store id stops travelling" mutant survived once
    this module was added: it patches the first occurrence in events.py, which is now
    this payload, and nothing here had checked the field.
    """
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    p = events.build_reference_payload("Aisle 3", "Sugar level 2", True, 1.8, 0.03)
    assert p["store"] == "juba-main-01"
