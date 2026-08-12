"""P0-6 — zone identity, the store/aisle/shelf hierarchy, and the zone→SKU map.

Two findings drive this, and the second is worse than the audit recorded.

  1. Every event travelled keyed on `zone: <name>` and nothing else. Two zones called
     "Sugar" on different cameras were indistinguishable downstream, and a renamed zone
     became a new shelf with all its history orphaned.

  2. **A zone could not be edited at all.** The gateway had POST, enroll and DELETE and
     no update path. Fixing a typo meant deleting and redrawing — which throws away the
     enrolled reference, resets the state machine, and orphans every past event in
     CloudBSS under a name nothing points at any more. An install with one typo had to
     lose its history to correct it.

CloudBSS's own ingest controller says v1 is "STORAGE ONLY … needs zone→SKU mapping".
`skus` is that mapping, captured while someone is standing in front of the shelf.
"""
from __future__ import annotations

import base64
import json
import time

import cv2
import pytest
from fastapi.testclient import TestClient

from app import db, detector, events, quality, scheduler
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


def _seed(root, img):
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, host, channel, enabled) "
            "VALUES ('Aisle 3','demo','',1,1)").lastrowid
    snaps = root / "snapshots"
    snaps.mkdir(parents=True, exist_ok=True)
    path = str(snaps / f"{cam}-{time.time()}.jpg")
    cv2.imwrite(path, img)
    with db.tx() as con:
        con.execute("INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                    (cam, time.time(), path))
    return cam


def _zone(client, cam, **extra):
    body = {"camera_id": cam, "name": "Sugar level 2", "polygon": F.ZONE,
            "empty_below": 0.35, "low_below": 0.65}
    body.update(extra)
    r = client.post("/api/zones", headers=_auth(), json=body)
    assert r.status_code == 200, r.text
    return r.json()


# ── 1. a permanent id ─────────────────────────────────────────────────────────────

def test_a_new_zone_gets_a_uid(box):
    client, root = box
    out = _zone(client, _seed(root, F.as_jpeg(F.aisle(facings=F.FULL))))
    assert out["zone_uid"] and len(out["zone_uid"]) >= 12


def test_two_zones_never_share_a_uid(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    a = _zone(client, cam, name="Sugar")["zone_uid"]
    b = _zone(client, cam, name="Rice")["zone_uid"]
    assert a != b


def test_renaming_a_zone_keeps_its_uid(box):
    """The whole point. Downstream history must survive a typo being fixed."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam, name="Suger level 2")
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"name": "Sugar level 2"})
    assert r.status_code == 200
    assert r.json()["zone"]["zone_uid"] == z["zone_uid"]
    assert r.json()["zone"]["name"] == "Sugar level 2"


def test_the_uid_cannot_be_patched(box):
    """A mutable join key is the bug, not the fix — the field is simply not accepted."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    client.patch(f"/api/zones/{z['id']}", headers=_auth(),
                 json={"zone_uid": "deadbeefdeadbeef", "name": "x"})
    row = db.db().execute("SELECT zone_uid FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert row["zone_uid"] == z["zone_uid"]


def test_a_pre_p0_6_zone_is_given_a_uid_on_upgrade(fresh_box):
    """A box in the field has zones with no uid. Without a backfill they would keep
    being identified by name — the exact failure this work ends."""
    db.db()
    with db.tx() as con:
        con.execute("INSERT INTO zones (camera_id,name,polygon) VALUES (1,'Old',?)",
                    (json.dumps(F.ZONE),))
        con.execute("UPDATE zones SET zone_uid=NULL")
    db._ensure_columns(db.db())
    row = db.db().execute("SELECT zone_uid FROM zones").fetchone()
    assert row["zone_uid"], "a pre-P0-6 zone was left unidentifiable"


# ── 2. editing a zone without destroying it ───────────────────────────────────────

def test_patching_a_zone_does_not_touch_its_reference(box):
    """Before P0-6 the only way to rename was delete-and-redraw, which lost this."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    client.post(f"/api/zones/{z['id']}/enroll", headers=_auth())
    before = db.db().execute("SELECT ref_descriptors, state FROM zones WHERE id=?",
                             (z["id"],)).fetchone()
    client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"name": "Renamed"})
    after = db.db().execute("SELECT ref_descriptors, state FROM zones WHERE id=?",
                            (z["id"],)).fetchone()
    assert after["ref_descriptors"] == before["ref_descriptors"]
    assert after["state"] == before["state"]


def test_an_empty_name_is_refused(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"name": "   "})
    assert r.status_code == 400


def test_thresholds_that_cross_over_are_refused(box):
    """empty_below above low_below makes LOW unreachable — the zone would jump
    straight from stocked to empty and nobody would know why."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(),
                     json={"empty_below": 0.8, "low_below": 0.4})
    assert r.status_code == 400 and "low_below" in r.json()["detail"]


def test_out_of_range_thresholds_are_refused(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    assert client.patch(f"/api/zones/{z['id']}", headers=_auth(),
                        json={"empty_below": 1.7}).status_code == 400


def test_patching_nothing_changes_nothing(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={})
    assert r.status_code == 200 and r.json()["changed"] == 0


def test_patching_an_unknown_zone_is_a_404(box):
    client, _ = box
    assert client.patch("/api/zones/9999", headers=_auth(),
                        json={"name": "x"}).status_code == 404


# ── 3. the product map ────────────────────────────────────────────────────────────

def test_skus_are_stored_and_returned(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam, skus=["SUG-1KG", "SUG-2KG"])
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={})
    row = db.db().execute("SELECT skus FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert json.loads(row["skus"]) == ["SUG-1KG", "SUG-2KG"]


def test_skus_are_trimmed_deduplicated_and_ordered(box):
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam, skus=["  SUG-1KG ", "", "SUG-2KG", "SUG-1KG", "   "])
    row = db.db().execute("SELECT skus FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert json.loads(row["skus"]) == ["SUG-1KG", "SUG-2KG"]


def test_too_many_skus_means_the_zone_is_drawn_too_wide(box):
    from app.main import MAX_SKUS_PER_ZONE
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam, skus=[f"SKU-{i}" for i in range(MAX_SKUS_PER_ZONE + 8)])
    row = db.db().execute("SELECT skus FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert len(json.loads(row["skus"])) == MAX_SKUS_PER_ZONE


def test_skus_can_be_set_later(box):
    """The installer draws zones before they know the product codes."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"skus": ["RICE-25KG"]})
    row = db.db().execute("SELECT skus FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert json.loads(row["skus"]) == ["RICE-25KG"]


# ── 4. identity travels in the payload ────────────────────────────────────────────

def _zrow(**over):
    row = {"id": 1, "name": "Sugar level 2", "empty_below": 0.35, "low_below": 0.65,
           "ref_suspect": 0, "zone_uid": "abc123def456", "aisle": "3", "shelf": "2",
           "face": "left", "skus": json.dumps(["SUG-1KG", "SUG-2KG"])}
    row.update(over)
    return row


def test_zone_identity_extracts_the_join_key_and_the_place():
    ident = events.zone_identity(_zrow())
    assert ident["zone_uid"] == "abc123def456"
    assert (ident["aisle"], ident["shelf"], ident["face"]) == ("3", "2", "left")
    assert ident["skus"] == ["SUG-1KG", "SUG-2KG"]
    assert ident["sku"] == "SUG-1KG", "the first SKU is the one an alert names"


def test_zone_identity_survives_a_row_with_none_of_it():
    """A box mid-upgrade, or a camera-level event. Empty strings, never a crash."""
    ident = events.zone_identity({"id": 1, "name": "x"})
    assert ident["zone_uid"] == "" and ident["skus"] == [] and ident["sku"] == ""


def test_zone_identity_survives_corrupt_sku_json():
    assert events.zone_identity(_zrow(skus="{not json"))["skus"] == []


def test_a_shelf_event_carries_the_identity():
    p = events.build_payload(_zrow(), {"name": "Aisle 3"}, "empty", "stocked", 0.1, None)
    assert p["zone_uid"] == "abc123def456"
    assert p["sku"] == "SUG-1KG" and p["aisle"] == "3"
    assert p["zone"] == "Sugar level 2", "the human name must still travel too"


def test_a_zone_quality_event_carries_the_identity():
    p = events.build_quality_payload("Aisle 3", "Sugar level 2", False, "blurred",
                                     "d", {}, zone_row=_zrow())
    assert p["zone_uid"] == "abc123def456"


def test_a_camera_quality_event_does_not_invent_a_zone():
    """A dirty lens is about the camera. Attaching a shelf id would be a lie."""
    p = events.build_quality_payload("Aisle 3", None, False, "dark", "d", {})
    assert "zone_uid" not in p and p["zone"] == ""


def test_a_reference_event_carries_the_identity():
    p = events.build_reference_payload("Aisle 3", "Sugar level 2", True, 1.8, 0.03,
                                       zone_row=_zrow())
    assert p["zone_uid"] == "abc123def456" and p["sku"] == "SUG-1KG"


# ── 5. through the scheduler ──────────────────────────────────────────────────────

def _cam_with_zone(ref_img, **zone):
    cols = {"aisle": "3", "shelf": "2", "face": "left",
            "skus": json.dumps(["SUG-1KG"]), "zone_uid": "fixeduid00000001"}
    cols.update(zone)
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state,"
            "aisle,shelf,face,skus,zone_uid) VALUES (?,?,?,?,'stocked',?,?,?,?,?)",
            (cam, "Sugar level 2", json.dumps(F.ZONE), desc(ref_img).to_json(),
             cols["aisle"], cols["shelf"], cols["face"], cols["skus"], cols["zone_uid"]))
        con.execute("UPDATE cameras SET baseline_png=? WHERE id=?",
                    (quality.baseline_signature(ref_img), cam))
    return db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()


def test_a_real_shelf_event_carries_the_identity_end_to_end(fresh_box, monkeypatch):
    sent = []
    real = db.record_payload
    monkeypatch.setattr(scheduler.db, "record_payload",
                        lambda eid, p: (sent.append(p), real(eid, p))[1])
    monkeypatch.setattr(scheduler.events, "send", lambda p: (sent.append(p), True)[1])
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    shelf = [p for p in sent if p["event"].startswith("shelf.")]
    assert shelf, "no shelf event"
    assert shelf[0]["zone_uid"] == "fixeduid00000001"
    assert shelf[0]["sku"] == "SUG-1KG"
    assert shelf[0]["aisle"] == "3"


def test_the_stored_payload_keeps_the_identity_for_retry(fresh_box, monkeypatch):
    monkeypatch.setattr(scheduler.events, "deliver", lambda eid, p: None)
    monkeypatch.setattr(scheduler.events, "send", lambda p: True)
    cam = _cam_with_zone(aisle(6))
    for _ in range(detector.CONFIRMATIONS + 1):
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(0)))
    row = db.db().execute("SELECT payload FROM events").fetchone()
    assert json.loads(row["payload"])["zone_uid"] == "fixeduid00000001"


# ── 6. gaps the mutants found ─────────────────────────────────────────────────────
#
# Four mutants survived the tests above. Each was a real hole, not a fussy one.

def test_re_running_the_migration_does_not_re_key_existing_zones(fresh_box):
    """A backfill that overwrote live uids would orphan every shelf's history in
    CloudBSS on every restart — the exact failure P0-6 exists to end, delivered
    by the fix for it."""
    db.db()
    with db.tx() as con:
        con.execute("INSERT INTO zones (camera_id,name,polygon,zone_uid) "
                    "VALUES (1,'Sugar',?,'keepthisuid00001')", (json.dumps(F.ZONE),))
    for _ in range(3):
        db._ensure_columns(db.db())
    row = db.db().execute("SELECT zone_uid FROM zones").fetchone()
    assert row["zone_uid"] == "keepthisuid00001"


def test_the_scheduler_does_not_attach_a_zone_identity_to_a_camera_event(fresh_box, monkeypatch):
    """A dirty lens is a fact about the camera. Tagging it with a shelf id would let
    CloudBSS file "the picture is dark" against one arbitrary shelf on that camera."""
    sent = []
    monkeypatch.setattr(scheduler.events, "send", lambda p: sent.append(p) or True)
    monkeypatch.setattr(scheduler.events, "deliver", lambda eid, p: None)
    cam = _cam_with_zone(aisle(6))
    scheduler.evaluate_zones(cam, None, _jpeg(F.as_jpeg(F.aisle(facings=F.FULL, bright=0.22))))

    cam_events = [p for p in sent if p["event"] in (events.IMAGE_BAD, events.IMAGE_OK)
                  and not p["zone"]]
    assert cam_events, "expected a camera-level quality event"
    for p in cam_events:
        assert "zone_uid" not in p, "a camera event was given a shelf identity"


def test_a_threshold_outside_zero_to_one_is_refused_even_without_crossing(box):
    """The earlier test used empty_below=1.7, which the CROSSOVER check also rejects —
    so it passed even with the range check removed. This one only the range check can
    catch."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"low_below": 1.5})
    assert r.status_code == 400
    r = client.patch(f"/api/zones/{z['id']}", headers=_auth(), json={"empty_below": -0.2})
    assert r.status_code == 400


def test_skus_are_cleaned_on_the_patch_path_too(box):
    """The dedup test only covered creation. The patch path had its own copy of the
    call and nothing checked it."""
    client, root = box
    cam = _seed(root, F.as_jpeg(F.aisle(facings=F.FULL)))
    z = _zone(client, cam)
    client.patch(f"/api/zones/{z['id']}", headers=_auth(),
                 json={"skus": ["  RICE-25KG ", "", "RICE-25KG", "OIL-5L", "   "]})
    row = db.db().execute("SELECT skus FROM zones WHERE id=?", (z["id"],)).fetchone()
    assert json.loads(row["skus"]) == ["RICE-25KG", "OIL-5L"]
