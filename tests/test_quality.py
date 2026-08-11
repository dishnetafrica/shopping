"""P0-3 — the box must say "I cannot see" instead of "the shelf is empty".

Two halves, and the SECOND one is the one that keeps this feature honest:

  1. every failing case the audit measured must now be REFUSED rather than classified
  2. every genuinely empty, half-empty and full shelf must still PASS the gate

A gate that suppresses real stockouts is worse than no gate at all. An empty shelf is
legitimately dark, flat and textureless, so any check calibrated on "the frame looks
featureless" refuses exactly the thing the product is sold to detect. Half of this file
exists to hold that line, and the thresholds in quality.py were moved twice because of
it — a whole-frame flatness check refused an empty gondola, and a mean-brightness
lighting check could not tell 17% of stock disappearing from 20% of the light going.
"""
from __future__ import annotations

import json

import cv2
import numpy as np
import pytest

from app import db, detector, events, quality, scheduler
from tests.frames import (ALL_EMPTY, FULL, MIDDLE_EMPTY, MIDDLE_HALF, ZONE,
                          aisle as _raw_aisle, as_jpeg)


def aisle(*a, **kw):
    """Every frame the product ever sees is JPEG. Testing on raw arrays measures a
    cleaner world than the box lives in, and it hid a false obstruction on an
    ordinary full shelf until the first live walkthrough."""
    return as_jpeg(_raw_aisle(*a, **kw))

BASE = aisle(FULL, seed=1)
SIG = quality.baseline_signature(BASE)
REF = detector.describe(BASE, ZONE)


def verdict(img):
    return quality.assess_frame(img, SIG)


def shelf_state(img):
    f = detector.fill_score(detector.describe(img, ZONE), REF)
    return f, detector.classify(f, 0.35, 0.65)


# ══ 1. the audit's failures must now be refusals ══════════════════════════════════

@pytest.mark.parametrize("label,img,reason", [
    ("40% dimmer (evening / half the lights off)", aisle(FULL, seed=3, bright=0.60), "lighting"),
    ("55% dimmer",                                 aisle(FULL, seed=3, bright=0.45), "lighting"),
    ("70% dimmer (lights off, ambient only)",      aisle(FULL, seed=3, bright=0.30), "dark"),
    ("70% brighter (sun on the aisle)",            aisle(FULL, seed=3, bright=1.70), "lighting"),
    ("dusty lens",                                 aisle(FULL, seed=3, blur=2),      "blurred"),
    ("out of focus",                               aisle(FULL, seed=3, blur=4),      "blurred"),
    ("badly out of focus",                         aisle(FULL, seed=3, blur=7),      "blurred"),
    ("camera nudged 16px",                         aisle(FULL, seed=3, shift=16),    "moved"),
    ("camera nudged 25px",                         aisle(FULL, seed=3, shift=25),    "moved"),
])
def test_a_full_shelf_is_never_called_empty(label, img, reason):
    """Every one of these is a COMPLETELY FULL shelf the detector got wrong."""
    v = verdict(img)
    assert not v.ok, f"{label}: the gate let this through — the detector would guess at it"
    assert v.reason == reason, f"{label}: refused as {v.reason!r}, expected {reason!r}"
    assert v.detail, "a refusal with no explanation is not actionable"


@pytest.mark.parametrize("label,img,reason", [
    ("black frame (dead channel)", np.zeros((480, 640, 3), np.uint8),      "black"),
    ("white frame (blown out)",    np.full((480, 640, 3), 255, np.uint8),  "bright"),
    ("lens covered",               np.full((480, 640, 3), 30, np.uint8),   "black"),
    # A real aisle with the lights almost off: structure survives, brightness does not.
    ("lights off, ambient only",   aisle(FULL, seed=3, bright=0.30),        "dark"),
])
def test_an_unusable_frame_is_refused_even_with_no_baseline(label, img, reason):
    """Caught by the absolute backstops, before any enrolment exists.

    The EXACT reason is asserted, not just that something was refused. Every one of
    these frames trips more than one check, so a loose assertion passes with a backstop
    deleted — the black-frame check was removed by a mutant and every case still failed
    for a different reason, which read as green.
    """
    v = quality.assess_frame(img, None)
    assert not v.ok, label
    assert v.reason == reason, f"{label}: refused as {v.reason!r}, expected {reason!r}"


# ══ 2. THE LINE: the gate must not suppress a real stockout ═══════════════════════

@pytest.mark.parametrize("label,facings", [
    ("full shelf",                    FULL),
    ("the middle row is EMPTY",       MIDDLE_EMPTY),
    ("the middle row is half sold",   MIDDLE_HALF),
    ("EVERY row is empty",            ALL_EMPTY),
])
def test_real_shelf_states_still_pass_the_gate(label, facings):
    """An empty shelf is dark, flat and textureless. So is a dead camera. If this test
    ever fails, the product has stopped detecting the thing it is sold to detect."""
    v = verdict(aisle(facings, seed=3))
    assert v.ok, f"{label}: the gate REFUSED a real shelf state — {v.reason}: {v.detail}"


def test_an_empty_gondola_is_still_reported_empty():
    """End to end: the gate passes it and the detector still calls it."""
    img = aisle(MIDDLE_EMPTY, seed=3)
    assert verdict(img).ok
    fill, state = shelf_state(img)
    assert state == "empty", f"an empty shelf came back as {state} (fill {fill:.3f})"


@pytest.mark.parametrize("label,img", [
    ("10% dimmer (a cloud, one lamp out)", aisle(FULL, seed=3, bright=0.90)),
    ("15% brighter",                       aisle(FULL, seed=3, bright=1.15)),
    ("camera drifted 4px",                 aisle(FULL, seed=3, shift=4)),
])
def test_ordinary_variation_does_not_trip_the_gate(label, img):
    """A gate that fires on normal conditions gets muted, and then it protects nothing."""
    v = verdict(img)
    assert v.ok, f"{label}: refused as {v.reason} — {v.detail}"


# ══ 3. the measurements the thresholds rest on ════════════════════════════════════

def test_lighting_is_measured_by_gain_not_by_brightness():
    """Emptying a gondola drops mean brightness 17%, which is the same order as the
    dimming that has to be caught. Median per-pixel gain sees the light, not the stock —
    this is the measurement that made a safe threshold possible at all."""
    base_g = quality._work(BASE)
    empty_g = quality._work(aisle(ALL_EMPTY, seed=3))
    dim_g = quality._work(aisle(FULL, seed=3, bright=0.80))

    empty_gain = abs(1 - quality.light_gain(empty_g, base_g))
    dim_gain = abs(1 - quality.light_gain(dim_g, base_g))

    assert empty_gain < 0.05, f"emptying every shelf moved the gain {empty_gain:.1%}"
    assert dim_gain > 0.15, f"dimming 20% only moved the gain {dim_gain:.1%}"
    assert empty_gain < quality.LIGHT_CHANGE < dim_gain, (
        "the threshold does not sit between 'all the stock went' and 'the lights went'")


def test_movement_is_measured_against_enrolment_not_the_last_frame():
    """A camera drifting a pixel a day never trips a frame-to-frame check and is
    pointing somewhere else by Christmas."""
    base_g = quality._work(BASE)
    assert quality.frame_shift(quality._work(aisle(FULL, seed=3)), base_g) < 1.0
    assert quality.frame_shift(quality._work(aisle(FULL, seed=3, shift=16)), base_g) > quality.MOVE_PX
    # content changing must not read as movement
    for facings in (MIDDLE_EMPTY, ALL_EMPTY):
        s = quality.frame_shift(quality._work(aisle(facings, seed=3)), base_g)
        assert s < quality.MOVE_PX, f"emptying a shelf read as {s:.1f}px of camera movement"


@pytest.mark.parametrize("px,detected", [
    (0, False), (2, False), (4, False),          # vibration must not trip it
    (8, True), (16, True), (25, True), (40, True), (100, True), (300, True),
])
def test_the_movement_check_across_its_whole_range(px, detected):
    """8px to 300px on a 640px frame, and nothing below 4px.

    The 25px case is the one the audit measured: the detector reported STOCKED against a
    reference that had silently stopped describing that shelf, and nothing said a word.
    """
    base_g = quality._work(BASE)
    shift = quality.frame_shift(quality._work(aisle(FULL, seed=3, shift=px)), base_g)
    assert (shift > quality.MOVE_PX) is detected, f"{px}px measured {shift:.1f}px"


@pytest.mark.parametrize("label,img", [
    ("one shelf emptied",   aisle(MIDDLE_EMPTY, seed=3)),
    ("the gondola emptied", aisle(ALL_EMPTY, seed=3)),
    ("a shopper in the aisle", aisle(FULL, seed=3, occluder=(200, 420, (40, 40, 190)))),
    ("40% dimmer",          aisle(FULL, seed=3, bright=0.60)),
    ("out of focus",        aisle(FULL, seed=3, blur=4)),
])
def test_nothing_but_movement_reads_as_movement(label, img):
    """The failure that would matter most: 'the camera moved' blocks classification, so
    anything that fools it suppresses a real stockout."""
    shift = quality.frame_shift(quality._work(img), quality._work(BASE))
    assert shift < quality.MOVE_PX, f"{label} read as {shift:.1f}px of camera movement"


# ══ 4. obstruction: what it catches, and what it does not ═════════════════════════

def _novel(img):
    crop = detector._crop_zone(img, ZONE)
    return quality.novel_colour_fraction(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV),
                                         REF.hist, detector.HIST_BINS)[1]   # blob


@pytest.mark.parametrize("label,bgr", [
    ("red coat",      (40, 40, 190)),
    ("yellow hi-vis", (60, 220, 235)),
    ("dark clothing", (35, 35, 35)),
])
def test_an_unfamiliar_obstruction_is_refused(label, bgr):
    """Colours the shelf does not contain. These are what the check can actually see."""
    img = aisle(FULL, seed=3, occluder=(200, 420, bgr))
    crop = detector._crop_zone(img, ZONE)
    v = quality.assess_zone(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV), REF.hist, detector.HIST_BINS)
    assert not v.ok, f"{label}: not detected (blob {_novel(img):.1%})"
    assert v.reason == "obstructed"


@pytest.mark.parametrize("label,bgr", [
    ("blue coat — blue is a product colour",   (190, 90, 40)),
    ("grey coat — grey is the back panel",     (110, 110, 110)),
    ("brown trolley",                          (60, 90, 130)),
    ("white shirt — white is the rail tickets", (230, 230, 230)),
])
def test_an_obstruction_the_colour_of_the_shelf_is_missed(label, bgr):
    """Measured, not wished for. A colour test cannot see an obstruction the colour of
    the thing it covers, and all four of these score 0.3% against a 5% threshold.

    Recorded as tests so the documented limit cannot quietly drift: if a later change
    starts catching one of these, this fails and the honest claim gets updated instead
    of the code silently outgrowing the README.
    """
    img = aisle(FULL, seed=3, occluder=(200, 420, bgr))
    crop = detector._crop_zone(img, ZONE)
    v = quality.assess_zone(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV), REF.hist, detector.HIST_BINS)
    assert v.ok, f"{label} is now caught — update the documented limits in quality.py"


def test_a_hand_drawn_tilted_zone_still_detects_an_obstruction():
    """The zone editor produces tilted quads, not rectangles, and the crop fills the
    rest of its bounding box with BLACK. Those pixels are not shelf; counting them
    dilutes the measurement until a real obstruction slips under the threshold."""
    tilted = [[30, 190], [610, 176], [614, 300], [26, 316]]
    base_ref = detector.describe(aisle(FULL, seed=1), tilted)
    img = aisle(FULL, seed=3, occluder=(200, 420, (40, 40, 190)))
    crop = detector._crop_zone(img, tilted)
    v = quality.assess_zone(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV),
                            base_ref.hist, detector.HIST_BINS)
    assert not v.ok, "a red coat in a hand-drawn zone was not detected"

    # …and the empty version of the same tilted zone must still pass
    clean = detector._crop_zone(aisle(MIDDLE_EMPTY, seed=3), tilted)
    assert quality.assess_zone(cv2.cvtColor(clean, cv2.COLOR_BGR2HSV),
                               base_ref.hist, detector.HIST_BINS).ok


def test_scattered_novelty_alone_would_point_the_wrong_way():
    """The finding that forced the connected-component measure.

    JPEG fringes every sharp product edge with unfamiliar colour, so a busy FULL shelf
    scores higher on scattered novelty than a shelf with a coat across it. Deciding on
    the scattered fraction reports an obstruction on an ordinary stocked shelf — which
    is exactly what the first live walkthrough did.
    """
    def measure(img):
        crop = detector._crop_zone(img, ZONE)
        return quality.novel_colour_fraction(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV),
                                             REF.hist, detector.HIST_BINS)

    full_scattered, full_blob = measure(aisle(FULL, seed=3))
    coat_scattered, coat_blob = measure(aisle(FULL, seed=3, occluder=(200, 420, (190, 90, 40))))

    assert full_scattered > coat_scattered, (
        "scattered novelty no longer inverts — re-check whether the blob measure is "
        "still needed, and update quality.py's reasoning if not")
    assert full_blob < quality.NOVEL_ABOVE, "a plain full shelf reads as obstructed"


def test_scattered_patches_are_not_an_obstruction_however_many_there_are():
    """"One solid patch" is the actual claim, so it has to be the actual test.

    New price tags along a rail, or a row of unfamiliar labels, put novel colour all over
    a zone without anything standing in front of it. Summing the novel regions instead of
    taking the largest turns that into a phantom shopper — and a phantom shopper blocks
    classification, so the real stockout underneath it is never reported.
    """
    img = _raw_aisle(FULL, seed=3)
    for i in range(9):                      # nine small unfamiliar patches, well spread
        x = 40 + i * 62
        cv2.rectangle(img, (x, 250), (x + 34, 284), (200, 20, 200), -1)
    crop = detector._crop_zone(as_jpeg(img), ZONE)
    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    scattered, blob = quality.novel_colour_fraction(hsv, REF.hist, detector.HIST_BINS)

    assert scattered > quality.NOVEL_ABOVE, (
        "the fixture does not actually produce enough novel colour to be a real test")
    assert blob < quality.NOVEL_ABOVE, (
        f"nine scattered patches ({scattered:.1%} total, largest {blob:.1%}) were called "
        f"an obstruction — the measure is summing regions instead of taking the largest")
    assert quality.assess_zone(hsv, REF.hist, detector.HIST_BINS).ok


def test_the_masked_border_is_not_counted_as_shelf():
    """A hand-drawn polygon is cropped to its bounding box and everything outside it is
    filled with BLACK. Those pixels are not shelf. Counting them dilutes the measurement
    in proportion to how tilted the zone is, and a marginal obstruction — a blue coat,
    the weakest of the cases below — drops under the threshold in a steeply drawn zone
    (measured: 22.5% masked, 6.4% unmasked, against a threshold of 8%).

    Asserted as a property rather than through a verdict flip, so it holds for every
    polygon shape rather than the one that happened to straddle the line.
    """
    img = aisle(FULL, seed=3, occluder=(200, 420, (40, 40, 190)))
    crop = detector._crop_zone(img, ZONE)
    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    plain = quality.novel_colour_fraction(hsv, REF.hist, detector.HIST_BINS)[1]

    h, w = hsv.shape[:2]
    padded = np.zeros((h, w * 2, 3), np.uint8)      # half the bounding box is now mask
    padded[:, :w] = hsv
    padded_frac = quality.novel_colour_fraction(padded, REF.hist, detector.HIST_BINS)[1]

    assert abs(plain - padded_frac) < 0.01, (
        f"doubling the masked-out area moved the measurement {plain:.1%} -> "
        f"{padded_frac:.1%}; the border is being counted as shelf")


def test_a_zone_drawn_across_two_shelves_is_a_known_trap():
    """Recorded because an installer will do it and the failure is silent.

    A zone drawn as a narrow diagonal band across two shelf rows measures 19% novel
    colour when the shelf is simply EMPTY — above the obstruction threshold — so a real
    gap is reported as "something in front of it" for ever. The check assumes a zone
    covers one shelf face, which is what the enrolment guidance says and what P0-4 will
    enforce rather than merely advise.
    """
    band = [[30, 140], [610, 268], [610, 318], [30, 190]]
    ref = detector.describe(aisle(FULL, seed=1), band)
    crop = detector._crop_zone(aisle(MIDDLE_EMPTY, seed=3), band)
    novel = quality.novel_colour_fraction(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV),
                                          ref.hist, detector.HIST_BINS)[1]
    assert novel > quality.NOVEL_ABOVE, (
        "a zone spanning two shelves no longer misreports an empty shelf as obstructed — "
        "good, but update the documented limit in quality.py and the P0-4 handoff")


def test_the_white_shirt_case_is_documented_because_it_is_missed():
    """Not a wish — a measurement. White is already in the reference as price-rail
    tickets and label print, so a white shirt is invisible to a colour-novelty test.

    Recorded as a test so it cannot quietly become untrue in either direction: if a
    later change starts catching it, this fails and the honest claim in quality.py and
    in the P0-3 report gets updated rather than silently drifting.
    """
    img = aisle(FULL, seed=3, occluder=(200, 420, (230, 230, 230)))
    assert _novel(img) < quality.NOVEL_ABOVE, (
        "the white-shirt case is now caught — update the documented limits")


@pytest.mark.parametrize("label,facings", [
    ("full shelf", FULL), ("empty shelf", MIDDLE_EMPTY),
    ("half shelf", MIDDLE_HALF), ("empty gondola", ALL_EMPTY)])
def test_no_shelf_state_is_ever_mistaken_for_an_obstruction(label, facings):
    """The failure that would matter: a real gap suppressed as 'somebody standing there'."""
    img = aisle(facings, seed=3)
    crop = detector._crop_zone(img, ZONE)
    v = quality.assess_zone(cv2.cvtColor(crop, cv2.COLOR_BGR2HSV), REF.hist, detector.HIST_BINS)
    assert v.ok, f"{label}: wrongly called an obstruction ({_novel(img):.1%} novel)"


# ══ 5. what the box actually does with a refused frame ════════════════════════════

def _camera_with_zone(baseline=True):
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 3','demo',600)"
        ).lastrowid
        con.execute("INSERT INTO zones (camera_id,name,polygon,ref_descriptor,state) "
                    "VALUES (?,?,?,?,'stocked')",
                    (cam, "Sugar level 2", json.dumps(ZONE), REF.to_json()))
        if baseline:
            con.execute("UPDATE cameras SET baseline_png=? WHERE id=?", (SIG, cam))
    return db.db().execute("SELECT * FROM cameras WHERE id=?", (cam,)).fetchone()


def _jpeg(img):
    return cv2.imencode(".jpg", img)[1].tobytes()


def _sent(monkeypatch):
    out = []
    monkeypatch.setattr(events, "send", lambda p: out.append(p) or True)
    return out


def test_a_bad_frame_does_not_change_the_shelf_state(fresh_box, monkeypatch):
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, blur=4)))

    z = db.db().execute("SELECT * FROM zones").fetchone()
    assert z["state"] == "stocked", "a blurred frame moved the shelf state"
    assert z["pending_count"] == 0, "a frame we could not read counted as a confirmation"
    assert [p["event"] for p in sent] == ["image.bad"]
    assert sent[0]["to_state"] == "blurred"
    assert not any(p["event"].startswith("shelf.") for p in sent)


def test_a_bad_frame_does_not_reset_a_confirmation_already_in_progress(fresh_box, monkeypatch):
    """Confirmations mean consecutive TRUSTWORTHY observations. A dirty-lens frame in
    the middle of a real stockout must not restart the clock, or a shelf in a shop with
    a flickering light is never confirmed at all."""
    _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(MIDDLE_EMPTY, seed=3)))      # 1st real
    assert db.db().execute("SELECT pending_count FROM zones").fetchone()[0] == 1

    cam = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam["id"],)).fetchone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, blur=4)))      # unreadable
    assert db.db().execute("SELECT pending_count FROM zones").fetchone()[0] == 1, (
        "an unreadable frame reset a confirmation that was already under way")


def test_a_shopper_stops_the_shelf_being_classified(fresh_box, monkeypatch):
    """Through the scheduler, not just the unit: the zone check has to actually gate.

    Testing quality.assess_zone directly proves the maths and nothing about whether the
    scheduler consults it — a mutant that deleted the `continue` left every obstruction
    test green while the shelf got classified anyway.
    """
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, occluder=(200, 420, (40, 40, 190)))))

    z = db.db().execute("SELECT * FROM zones").fetchone()
    assert z["quality_state"] == "bad" and z["quality_reason"] == "obstructed"
    assert z["pending_count"] == 0, "the obstructed frame was counted as an observation"
    assert [p["event"] for p in sent] == ["image.bad"]
    assert sent[0]["to_state"] == "obstructed"
    assert sent[0]["zone"] == "Sugar level 2", "a per-zone problem must name the zone"


def test_image_bad_is_edge_triggered_not_every_frame(fresh_box, monkeypatch):
    """A dirty lens at a ten-minute cadence would otherwise post 144 events a day."""
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    for _ in range(4):
        cam = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam["id"],)).fetchone()
        scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, blur=4)))
    assert [p["event"] for p in sent] == ["image.bad"], f"got {[p['event'] for p in sent]}"


def test_recovery_is_announced_and_classification_resumes(fresh_box, monkeypatch):
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, blur=4)))
    cam = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam["id"],)).fetchone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(MIDDLE_EMPTY, seed=3)))

    assert [p["event"] for p in sent] == ["image.bad", "image.ok"]
    assert db.db().execute("SELECT pending_count FROM zones").fetchone()[0] == 1, (
        "classification did not resume once the picture came back")


def test_a_stockout_during_a_bad_patch_is_delayed_not_lost(fresh_box, monkeypatch):
    """The trade-off the gate makes, stated as a test.

    Half the lights go off at 18:00 and the shelf sells out at 18:10. The gate refuses
    the dim frames — it genuinely cannot tell an empty shelf from a dark one — so the
    stockout is not reported at 18:10. The moment the lights come back it is picked up
    and confirmed. A delayed alert is the price of not inventing one; a LOST alert would
    not be, and this asserts the difference.
    """
    _sent(monkeypatch)
    cam = _camera_with_zone()

    def feed(img):
        row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam["id"],)).fetchone()
        scheduler.evaluate_zones(row, None, _jpeg(img))

    feed(aisle(FULL, seed=3, bright=0.60))                      # lights down
    feed(aisle(MIDDLE_EMPTY, seed=3, bright=0.60))              # sells out, unreadable
    assert db.db().execute("SELECT state FROM zones").fetchone()[0] == "stocked", (
        "the gate guessed at a frame it had already refused")

    feed(aisle(MIDDLE_EMPTY, seed=4))                           # lights back
    feed(aisle(MIDDLE_EMPTY, seed=5))
    assert db.db().execute("SELECT state FROM zones").fetchone()[0] == "empty", (
        "the stockout was lost, not delayed — the gate is suppressing real events")


def test_an_undecodable_frame_is_reported_instead_of_ignored(fresh_box, monkeypatch):
    """It used to `return` silently: no event, no log, no flag. The shelf simply
    stopped being watched and nobody was told."""
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, b"this is not a jpeg")
    assert [p["event"] for p in sent] == ["image.bad"]
    assert sent[0]["to_state"] == "black"


def test_the_quality_event_carries_no_invented_confidence(fresh_box, monkeypatch):
    """This event is the system saying it does NOT know. A number here would be the
    same dishonesty as the fabricated confidence the audit found."""
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    sent = _sent(monkeypatch)
    cam = _camera_with_zone()
    scheduler.evaluate_zones(cam, None, _jpeg(aisle(FULL, seed=3, blur=4)))
    assert "confidence" not in sent[0] and "fill" not in sent[0]
    assert sent[0]["metrics"], "the numbers behind the refusal are not reported"
    # Without a store id CloudBSS cannot attribute this to a shop, and it is as useless
    # as the shelf events were before P0-1.
    assert sent[0]["store"] == events.store_id() != "", "the quality event names no store"
    assert sent[0]["camera"] == "Aisle 3"


# ══ 6. camera health and backoff ══════════════════════════════════════════════════

def _failing_camera():
    from app.cal.base import CameraDriver, CameraError, Capabilities, register

    class DeadDriver(CameraDriver):
        name = "dead"

        def probe(self, cam):
            return Capabilities(ok=False, detail="dead")

        def get_snapshot(self, cam):
            raise CameraError("connection refused")

    register(DeadDriver())
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver, interval_s) VALUES ('Aisle 9','dead',600)"
        ).lastrowid
    return cam


def test_a_dead_camera_reports_offline_once_and_then_backs_off(fresh_box, monkeypatch):
    """The audit found a dead camera stayed permanently 'due' and was retried every 15
    seconds for ever, each attempt blocking the single capture thread for its full
    driver timeout. Three dead RTSP cameras starved every working camera on the box."""
    monkeypatch.setenv("STORE_ID", "juba-main-01")
    sent = _sent(monkeypatch)
    cam_id = _failing_camera()

    for _ in range(5):
        row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
        assert scheduler.capture_one(row) is None

    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert row["consecutive_failures"] == 5
    assert row["health_state"] == "offline"
    assert [p["event"] for p in sent] == ["camera.offline"], "offline was announced repeatedly"
    assert sent[0]["store"] == events.store_id() != "", "the health event names no store"
    assert sent[0]["camera"] == "Aisle 9"

    import time as _t
    assert row["next_attempt_ts"] > _t.time() + 60, "a dead camera is still being hammered"


def test_a_recovered_camera_says_so(fresh_box, monkeypatch):
    sent = _sent(monkeypatch)
    cam_id = _failing_camera()
    for _ in range(2):
        row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
        scheduler.capture_one(row)
    with db.tx() as con:
        con.execute("UPDATE cameras SET driver='demo' WHERE id=?", (cam_id,))

    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert scheduler.capture_one(row) is not None

    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert row["health_state"] == "online" and row["consecutive_failures"] == 0
    assert [p["event"] for p in sent] == ["camera.offline", "camera.online"]


def test_the_scheduler_honours_the_backoff(fresh_box):
    """Calls scheduler.is_due — the function the loop itself calls.

    This test used to recompute the due expression inline, which made it a test of its
    own copy of the logic: a mutant that reverted the loop to the old last_ok_ts
    expression survived untouched.
    """
    import time as _t
    now = _t.time()
    cam_id = _failing_camera()

    with db.tx() as con:
        con.execute("UPDATE cameras SET next_attempt_ts=?, last_ok_ts=? WHERE id=?",
                    (now + 600, now - 99999, cam_id))
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert not scheduler.is_due(row, now), (
        "a camera in backoff is still due — the old last_ok_ts rule is back, and a dead "
        "camera is being retried every loop again")

    with db.tx() as con:
        con.execute("UPDATE cameras SET next_attempt_ts=? WHERE id=?", (now - 1, cam_id))
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert scheduler.is_due(row, now), "a camera past its backoff is never tried again"

    # a row from before P0-3 (NULL next_attempt_ts) must behave as it always did
    with db.tx() as con:
        con.execute("UPDATE cameras SET next_attempt_ts=NULL, last_ok_ts=? WHERE id=?",
                    (now - 99999, cam_id))
    row = db.db().execute("SELECT * FROM cameras WHERE id=?", (cam_id,)).fetchone()
    assert scheduler.is_due(row, now), "an upgraded box stopped capturing"


# ══ 7. enrolment captures the reference view ══════════════════════════════════════

def test_enrolment_stores_the_baseline_the_gate_needs(fresh_box):
    """Without it the gate has nothing to measure 'since when' against, and a nudged
    camera reports STOCKED for ever against a reference describing another shelf."""
    from app import main
    cam = _camera_with_zone(baseline=False)
    assert cam["baseline_png"] is None

    snap_dir = fresh_box / "snapshots"
    snap_dir.mkdir(exist_ok=True)
    path = str(snap_dir / "base.jpg")
    cv2.imwrite(path, BASE)
    with db.tx() as con:
        con.execute("INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                    (cam["id"], 1.0, path))
    zone_id = db.db().execute("SELECT id FROM zones").fetchone()[0]

    res = main.enroll_zone(zone_id)
    assert res["baseline_captured"] is True

    row = db.db().execute("SELECT baseline_png FROM cameras WHERE id=?", (cam["id"],)).fetchone()
    assert row["baseline_png"], "enrolment did not capture the camera's reference view"
    v = quality.assess_frame(aisle(FULL, seed=3, shift=25), row["baseline_png"])
    assert not v.ok and v.reason == "moved", "the stored baseline does not detect movement"


def test_the_baseline_is_never_served_over_the_api(fresh_box):
    cam = _camera_with_zone()
    d = db.row_to_dict(cam, secret=None)
    assert "baseline_png" not in d, "a raw image blob in every camera-list response"
