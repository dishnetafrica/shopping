"""P0-8 — the evaluation framework.

Everything from P0-1 to P0-7 was reasoned, tested and unverified against reality. Every
threshold came from synthetic aisles; P0-5's confidence says in its own docstring that
it is not a calibrated probability. This is the milestone that makes those numbers
falsifiable.

The failure mode to guard against is NOT a wrong calculation. It is a right calculation
presented as more certain than the data supports — "precision 100%" from three labels
reads as knowledge and is not, which is the same sin as the fabricated confidence the
audit found. So the tests below spend more effort on refusals and intervals than on
arithmetic.

And one whole half of what people will ask for is unmeasurable here, on purpose:
acknowledgements answer alerts we SENT, so they can never reveal a shelf that sat empty
while the box stayed quiet. Recall requires `missed_reports`. Several tests exist purely
to stop a future reader from "fixing" that refusal.
"""
from __future__ import annotations

import base64
import json
import time

import cv2
import pytest
from fastapi.testclient import TestClient

from app import db, detector, evaluation, quality, scheduler
from tests import frames as F

USER, PASS = "vision", "a-long-enough-password"


def _auth() -> dict:
    return {"Authorization": "Basic " + base64.b64encode(f"{USER}:{PASS}".encode()).decode()}


def desc(img):
    return detector.describe(img, F.ZONE)


def aisle(mid, **kw):
    return F.as_jpeg(F.aisle(facings=(F.FACINGS, mid, F.FACINGS), **kw))


# ── 1. the interval, because the point estimate is the dangerous part ─────────────

def test_wilson_matches_published_values():
    lo, hi = evaluation.wilson_interval(50, 100)
    assert round(lo, 4) == 0.4038 and round(hi, 4) == 0.5962


def test_a_perfect_score_does_not_claim_certainty():
    """The normal approximation gives ZERO width at p=1: "100%, ±0" from ten labels.
    That single property is why Wilson is used here."""
    lo, hi = evaluation.wilson_interval(10, 10)
    # `> 0.999` rather than `== 1.0`: at p=1 the arithmetic lands on 0.999999…, and the
    # clamp never fires. Asserting equality would fail for a reason that has nothing to
    # do with the property being tested.
    assert hi > 0.999
    assert lo < 0.75, f"ten-for-ten should not imply a floor above 0.75, got {lo}"


def test_a_zero_score_does_not_claim_certainty_either():
    lo, hi = evaluation.wilson_interval(0, 10)
    assert lo == 0.0 and hi > 0.2


def test_the_interval_narrows_as_evidence_accumulates():
    widths = [evaluation.wilson_interval(int(n * 0.8), n)[1]
              - evaluation.wilson_interval(int(n * 0.8), n)[0]
              for n in (10, 50, 200, 1000)]
    assert widths == sorted(widths, reverse=True)


def test_no_data_means_no_claim():
    assert evaluation.wilson_interval(0, 0) == (0.0, 1.0)


def test_the_interval_never_leaves_zero_to_one():
    for k, n in ((0, 1), (1, 1), (1, 3), (99, 100), (0, 100)):
        lo, hi = evaluation.wilson_interval(k, n)
        assert 0.0 <= lo <= hi <= 1.0


# ── 2. refusing to quote a rate from too little ───────────────────────────────────

def _labels(n_true, n_false, alertable=True):
    return ([{"alertable": alertable, "ack_verdict": "true"}] * n_true
            + [{"alertable": alertable, "ack_verdict": "false"}] * n_false)


def test_a_handful_of_labels_yields_counts_but_no_rate():
    out = evaluation.precision(_labels(3, 0))
    assert out["true_positives"] == 3
    assert out["precision"] is None and out["enough_data"] is False
    assert str(evaluation.MIN_LABELS) in out["note"]


def test_enough_labels_yields_a_rate_with_an_interval():
    out = evaluation.precision(_labels(18, 6))
    assert out["enough_data"] is True
    assert out["precision"] == 0.75
    assert out["precision_95_low"] < 0.75 < out["precision_95_high"]


def test_suppressed_events_are_not_counted_as_alerts():
    """Precision must describe what the shopkeeper actually experienced."""
    out = evaluation.precision(_labels(20, 0) + _labels(0, 40, alertable=False))
    assert out["alerts_judged"] == 20 and out["false_positives"] == 0


def test_recall_is_refused_and_says_why():
    out = evaluation.precision(_labels(30, 5))
    assert out["recall"] is None
    assert "missed_reports" in out["recall_unavailable_because"]


# ── 3. re-scoring a stored frame ──────────────────────────────────────────────────

def test_rescore_reproduces_the_detector_on_a_real_file(tmp_path):
    img = aisle(0)
    p = str(tmp_path / "f.jpg")
    cv2.imwrite(p, img)
    refs = [desc(aisle(6))]
    got = evaluation.rescore(p, F.ZONE, refs, 0.35, 0.65)
    assert got["state"] == "empty"
    assert abs(got["fill"] - detector.fill_score_multi(desc(cv2.imread(p)), refs)) < 1e-9


def test_rescore_answers_a_different_threshold(tmp_path):
    """The engine of the milestone: what WOULD another threshold have done."""
    p = str(tmp_path / "f.jpg")
    cv2.imwrite(p, aisle(3))
    refs = [desc(aisle(6))]
    assert evaluation.rescore(p, F.ZONE, refs, 0.9, 0.95)["state"] == "empty"
    assert evaluation.rescore(p, F.ZONE, refs, 0.01, 0.02)["state"] == "stocked"


def test_a_missing_frame_is_reported_not_skipped(tmp_path):
    assert evaluation.rescore(str(tmp_path / "gone.jpg"), F.ZONE, [desc(aisle(6))],
                              0.35, 0.65) is None
    assert evaluation.rescore("", F.ZONE, [desc(aisle(6))], 0.35, 0.65) is None


def test_an_unreadable_file_does_not_crash_the_run(tmp_path):
    p = str(tmp_path / "junk.jpg")
    open(p, "wb").write(b"this is not a jpeg")
    assert evaluation.rescore(p, F.ZONE, [desc(aisle(6))], 0.35, 0.65) is None


def test_a_broken_polygon_does_not_crash_the_run(tmp_path):
    p = str(tmp_path / "f.jpg")
    cv2.imwrite(p, aisle(6))
    assert evaluation.rescore(p, [[0, 0], [1, 0], [1, 1]], [desc(aisle(6))],
                              0.35, 0.65) is None


# ── 4. the sweep ──────────────────────────────────────────────────────────────────

def _scored(pairs):
    return [{"fill": f, "verdict": v, "confidence": c} for f, v, c in pairs]


def test_a_higher_threshold_alerts_more():
    s = _scored([(0.1, "true", 0.9), (0.3, "true", 0.5), (0.5, "false", 0.2)])
    out = evaluation.sweep(s, [0.2, 0.4, 0.6], 0.65)
    assert [r["would_alert"] for r in out] == [1, 2, 3]


def test_the_sweep_counts_what_a_threshold_would_stop_catching():
    s = _scored([(0.1, "true", 0.9), (0.3, "true", 0.5)])
    out = evaluation.sweep(s, [0.2], 0.65)
    assert out[0]["labelled_misses"] == 1


def test_the_sweep_refuses_a_rate_on_thin_evidence():
    s = _scored([(0.1, "true", 0.9)])
    assert evaluation.sweep(s, [0.5], 0.65)[0]["precision"] is None


def test_the_sweep_quotes_a_rate_once_there_is_enough():
    s = _scored([(0.1, "true", 0.9)] * 20 + [(0.2, "false", 0.8)] * 5)
    r = evaluation.sweep(s, [0.5], 0.65)[0]
    assert r["would_alert"] == 25 and r["precision"] == 0.8
    assert r["precision_95_low"] < 0.8 < r["precision_95_high"]


def test_the_sweep_never_calls_anything_recall():
    r = evaluation.sweep(_scored([(0.1, "true", 0.9)]), [0.5], 0.65)[0]
    assert "recall" not in r


# ── 5. calibration ────────────────────────────────────────────────────────────────

def test_a_well_calibrated_bin_reads_back_its_own_rate():
    s = _scored([(0.1, "true", 0.9)] * 45 + [(0.1, "false", 0.9)] * 5)
    top = evaluation.calibration(s)[-1]
    assert top["n"] == 50 and top["observed_correct"] == 0.9
    assert top["enough_data"] is True


def test_an_empty_bin_claims_nothing():
    for b in evaluation.calibration([]):
        assert b["n"] == 0 and b["observed_correct"] is None


def test_a_thin_bin_is_flagged_as_thin():
    s = _scored([(0.1, "true", 0.9)] * 3)
    assert evaluation.calibration(s)[-1]["enough_data"] is False


def test_confidence_of_exactly_one_lands_in_the_top_bin():
    """A boundary that would otherwise silently drop the most confident readings."""
    s = _scored([(0.05, "true", 1.0)] * 5)
    assert evaluation.calibration(s)[-1]["n"] == 5
    assert sum(b["n"] for b in evaluation.calibration(s)) == 5


def test_bins_partition_without_overlap():
    s = _scored([(0.1, "true", c) for c in (0.0, 0.2, 0.4, 0.6, 0.8, 1.0)])
    assert sum(b["n"] for b in evaluation.calibration(s)) == 6


# ── 6. through the API ────────────────────────────────────────────────────────────

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


def _labelled_event(root, verdict, facings=0, keep_frame=True, uid=None):
    """One acknowledged event with a real frame on disk."""
    img = aisle(facings)
    with db.tx() as con:
        cam = con.execute(
            "INSERT INTO cameras (name, driver) VALUES ('Aisle 3','demo')").lastrowid
        zid = con.execute(
            "INSERT INTO zones (camera_id,name,polygon,ref_descriptor,zone_uid,"
            "empty_below,low_below) VALUES (?,?,?,?,?,0.35,0.65)",
            (cam, "Sugar", json.dumps(F.ZONE), desc(aisle(6)).to_json(),
             uid or db.new_zone_uid())).lastrowid
    snaps = root / "snapshots"
    snaps.mkdir(parents=True, exist_ok=True)
    path = str(snaps / f"e{time.time_ns()}.jpg")
    cv2.imwrite(path, img)
    with db.tx() as con:
        sid = con.execute("INSERT INTO snapshots (camera_id, ts, path) VALUES (?,?,?)",
                          (cam, time.time(), path)).lastrowid
        con.execute(
            "INSERT INTO events (zone_id, ts, from_state, to_state, fill, snapshot_id, "
            "uid, alertable, confidence, ack_verdict, ack_at) "
            "VALUES (?,?,'stocked','empty',0.1,?,?,1,0.9,?,?)",
            (zid, time.time(), sid if keep_frame else None,
             f"u{time.time_ns()}", verdict, time.time()))
    return zid


def test_the_endpoint_reports_counts_and_refuses_a_rate_when_thin(box):
    client, root = box
    for _ in range(3):
        _labelled_event(root, "true")
    out = client.get("/api/evaluation", headers=_auth()).json()
    assert out["labels_total"] == 3 and out["rescored"] == 3
    assert out["precision"]["precision"] is None
    assert out["precision"]["true_positives"] == 3


def test_the_endpoint_quotes_a_rate_once_there_is_enough(box):
    client, root = box
    for _ in range(evaluation.MIN_LABELS):
        _labelled_event(root, "true")
    for _ in range(5):
        _labelled_event(root, "false")
    out = client.get("/api/evaluation", headers=_auth()).json()
    p = out["precision"]
    assert p["enough_data"] is True
    assert p["precision_95_low"] < p["precision"] < 1.0


def test_the_endpoint_reports_labels_whose_frame_is_gone(box):
    """A sweep over the surviving half, presented as covering everything, is a lie of
    omission — so the count is prominent rather than inferred from a shortfall."""
    client, root = box
    _labelled_event(root, "true")
    _labelled_event(root, "true", keep_frame=False)
    out = client.get("/api/evaluation", headers=_auth()).json()
    assert out["labels_total"] == 2 and out["rescored"] == 1
    assert out["frames_missing"] == 1


def test_the_sweep_runs_over_real_frames(box):
    client, root = box
    for _ in range(6):
        _labelled_event(root, "true", facings=0)
    out = client.get("/api/evaluation?empty_below_from=0.05&empty_below_to=0.9&steps=4",
                     headers=_auth()).json()
    grid = [r["empty_below"] for r in out["threshold_sweep"]]
    assert len(grid) == 4 and grid == sorted(grid)
    assert out["threshold_sweep"][-1]["would_alert"] >= out["threshold_sweep"][0]["would_alert"]


def test_a_person_can_report_a_stockout_we_missed(box):
    client, root = box
    zid = _labelled_event(root, "true", uid="zoneuid000000001")
    r = client.post("/api/zones/zoneuid000000001/missed", headers=_auth(),
                    json={"by": "Mary", "note": "sugar was out all morning"})
    assert r.status_code == 200
    row = db.db().execute("SELECT * FROM missed_reports").fetchone()
    assert row["zone_uid"] == "zoneuid000000001" and row["reported_by"] == "Mary"


def test_reporting_a_miss_against_an_unknown_zone_is_a_404(box):
    client, _ = box
    assert client.post("/api/zones/nosuchzone/missed", headers=_auth(),
                       json={}).status_code == 404


def test_missed_reports_are_surfaced_in_the_evaluation(box):
    client, root = box
    _labelled_event(root, "true", uid="zoneuid000000002")
    client.post("/api/zones/zoneuid000000002/missed", headers=_auth(), json={})
    assert client.get("/api/evaluation", headers=_auth()).json()["missed_reports"] == 1


def test_the_endpoint_never_reports_a_recall_figure(box):
    """Guarding the refusal itself. Someone will want this number; the point is that
    the data cannot produce it, and quietly producing one anyway is the failure."""
    client, root = box
    for _ in range(evaluation.MIN_LABELS + 5):
        _labelled_event(root, "true")
    out = client.get("/api/evaluation", headers=_auth()).json()
    assert out["precision"]["recall"] is None
    for r in out["threshold_sweep"]:
        assert "recall" not in r


def test_an_empty_box_returns_a_shape_not_an_error(box):
    client, _ = box
    out = client.get("/api/evaluation", headers=_auth()).json()
    assert out["labels_total"] == 0 and out["rescored"] == 0
    assert out["precision"]["precision"] is None
    assert isinstance(out["threshold_sweep"], list)


# ── 7. gaps the mutants found ─────────────────────────────────────────────────────

def test_the_clamp_catches_floating_point_dust():
    """The clamp looks decorative until you measure it.

    Unclamped, k=0/n=2 lands on -5.55e-17 and k=20/n=20 on 1.0000000000000002. Tiny —
    but a negative probability or one above 1 in a report is the kind of detail that
    makes a reader stop trusting the whole page, and JSON consumers do not all cope.
    My first range test used inputs that never produce the dust, so a mutant deleting
    the clamp survived.
    """
    lo, _ = evaluation.wilson_interval(0, 2)
    assert lo >= 0.0, f"negative lower bound leaked: {lo!r}"
    _, hi = evaluation.wilson_interval(20, 20)
    assert hi <= 1.0, f"upper bound above one leaked: {hi!r}"
    for n in (2, 7, 9, 11, 20, 32):
        for k in (0, n):
            a, b = evaluation.wilson_interval(k, n)
            assert 0.0 <= a <= b <= 1.0, f"k={k} n={n} -> ({a!r}, {b!r})"


def test_one_undecodable_frame_does_not_abort_the_whole_evaluation(tmp_path, monkeypatch):
    """The property that matters: a single bad frame costs one label, not the run.

    Measured — cv2.error is genuinely reachable from describe(): a single-channel image
    raises it inside cvtColor. rescore() reads with the default IMREAD_COLOR so it
    normally sees three channels, which is why the plain junk-file test never exercised
    that branch and a mutant narrowing the except clause survived.
    """
    p = str(tmp_path / "f.jpg")
    cv2.imwrite(p, aisle(6))
    grey = cv2.cvtColor(cv2.imread(p), cv2.COLOR_BGR2GRAY)
    monkeypatch.setattr(evaluation.cv2, "imread", lambda *a, **k: grey)
    assert evaluation.rescore(p, F.ZONE, [desc(aisle(6))], 0.35, 0.65) is None
