"""
Sandbox self-test — proves the benchmark's METRIC MATH is correct on known-answer data, and that a
real app.movement pipeline pass feeds those metrics correctly. Runs with `python3 bench/selftest.py`.

What this DOES prove (here, now, no footage): detection precision/recall/mAP counting, tracking
continuity/ID-switch logic, movement crossing-matching + duplicate/false/missed/direction logic,
system summarisation, and that a scripted detector → real sv.ByteTrack + sv.LineZone →
MovementStateMachine emits a carton.move that scores as exactly-one-correct.

What this does NOT prove (needs real warehouse footage on a real box): whether a real detector actually
sees cartons (precision/recall on real pixels), real FPS/RAM/GPU, and real crossing reliability. Those
are the numbers the whole exercise exists to obtain — and they cannot be manufactured in a sandbox.
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from bench.manifest import GtBox, GtCrossing, GtFrame, Manifest
from bench.metrics import detection, movement, system, tracking

_pass = _fail = 0


def check(name, cond, detail=""):
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"  PASS  {name}")
    else:
        _fail += 1
        print(f"  FAIL  {name}  {detail}")


# ── detection math ───────────────────────────────────────────────────────────────────────────
def test_detection_math():
    gt = {10: [(100, 100, 140, 140)], 20: [(200, 200, 260, 260)]}
    perfect = {10: [((100, 100, 140, 140), 0.9)], 20: [((200, 200, 260, 260), 0.8)]}
    r = detection.evaluate(perfect, gt)
    check("detection: perfect → P=R=1, mAP=1",
          r["precision"] == 1.0 and r["recall"] == 1.0 and r["mAP50"] == 1.0, r)

    with_fp = {10: [((100, 100, 140, 140), 0.9), ((500, 500, 540, 540), 0.7)], 20: [((200, 200, 260, 260), 0.8)]}
    r = detection.evaluate(with_fp, gt)
    check("detection: one spurious box → P=2/3, R=1, FP=1",
          r["true_positives"] == 2 and r["false_positives"] == 1 and r["recall"] == 1.0, r)

    with_miss = {10: [((100, 100, 140, 140), 0.9)], 20: []}
    r = detection.evaluate(with_miss, gt)
    check("detection: one missed box → R=0.5, FN=1",
          r["recall"] == 0.5 and r["false_negatives"] == 1, r)


# ── movement math (the operational metric) ────────────────────────────────────────────────────
def _cross(gt_id, direction, ts, occluded=False):
    return GtCrossing(gt_id=gt_id, direction=direction, frame=int(ts), ts=ts, occluded=occluded)


def test_movement_math():
    C = [_cross("A", "out", 7.0)]
    ev = [{"event": "carton.move", "ts": 7.0, "direction": "out"}]
    r = movement.evaluate(ev, C)
    check("movement: clean crossing → exactly-one-correct rate 1.0",
          r["exactly_one_correct_rate"] == 1.0 and r["true_crossings"] == 1
          and r["missed_crossings"] == 0 and r["false_crossings"] == 0
          and r["duplicate_carton_moves"] == 0 and r["direction_accuracy"] == 1.0, r)

    r = movement.evaluate([{"event": "carton.move", "ts": 7.0, "direction": "in"}], C)
    check("movement: wrong direction → matched but dir_acc 0, EOC 0",
          r["true_crossings"] == 1 and r["direction_accuracy"] == 0.0
          and r["exactly_one_correct"] == 0, r)

    r = movement.evaluate([], C)
    check("movement: no event → missed 1, EOC rate 0",
          r["missed_crossings"] == 1 and r["exactly_one_correct_rate"] == 0.0, r)

    r = movement.evaluate([{"event": "carton.move", "ts": 7.0, "direction": "out"}], [])
    check("movement: event without GT → false crossing 1",
          r["false_crossings"] == 1 and r["gt_crossings"] == 0, r)

    dup = [{"event": "carton.move", "ts": 7.0, "direction": "out"},
           {"event": "carton.move", "ts": 7.2, "direction": "out"}]
    r = movement.evaluate(dup, C)
    check("movement: two events one crossing → duplicate 1, EOC 0",
          r["duplicate_carton_moves"] == 1 and r["exactly_one_correct"] == 0
          and r["true_crossings"] == 1, r)

    r = movement.evaluate([{"event": "carton.move", "ts": 20.0, "direction": "out"}], C, tol_s=1.0)
    check("movement: event outside tolerance → missed 1 + false 1",
          r["missed_crossings"] == 1 and r["false_crossings"] == 1, r)


# ── tracking math ──────────────────────────────────────────────────────────────────────────────
def _frame(cartons, lost=None):
    return {"ts": 0.0, "cartons": cartons, "persons": {}, "crossings": [], "lost": lost or []}


def test_tracking_math():
    box = (100, 100, 140, 140)
    trace = [_frame({7: box}) for _ in range(11)]          # frames 0..10, stable id 7
    gt_frames = {i: GtFrame(index=i, carton_present=True, boxes=[GtBox(box)]) for i in (3, 5, 7)}
    C = [GtCrossing("A", "out", frame=5, ts=5.0)]
    r = tracking.evaluate(trace, C, gt_frames, window=5)
    check("tracking: stable id → 0 switches, continuity 1.0",
          r["id_switches_total"] == 0 and r["mean_continuity"] == 1.0, r)

    trace2 = [_frame({7: box}) if i < 5 else _frame({8: box}) for i in range(11)]   # id 7→8 at frame 5
    r = tracking.evaluate(trace2, C, gt_frames, window=5)
    check("tracking: id 7→8 across window → 1 switch",
          r["id_switches_total"] == 1, r)

    trace3 = [_frame({7: box}, lost=[7] if i == 8 else None) for i in range(11)]
    r = tracking.evaluate(trace3, C, gt_frames, window=5)
    check("tracking: a loss event is counted",
          r["tracking_loss_events"] == 1, r)


# ── system summarisation ─────────────────────────────────────────────────────────────────────
def test_system_math():
    sw = system.Stopwatch()
    sw._t0 = None
    sw.frames = 100
    sw.decode_s = 1.0
    sw.infer_s = 3.0
    sw.pipeline_s = 1.0
    # fake a wall time by setting _t0 in the past deterministically is not allowed (no time control);
    # instead assert the percentile logic directly:
    lat = [float(x) for x in range(1, 101)]   # 1..100 ms
    out = system.summarise(_FrozenSW(frames=100, wall=5.0, decode=1.0, infer=3.0, pipeline=1.0), lat)
    check("system: fps = frames/wall", out["fps"] == 20.0, out)
    check("system: p50≈50, p95≈95, max=100",
          out["latency_ms"]["p50"] in (50.0, 51.0) and out["latency_ms"]["max"] == 100.0, out["latency_ms"])
    check("system: decode share reported",
          out["rtsp_decode_load"]["decode_share_of_wall"] == 0.2, out["rtsp_decode_load"])
    check("system: gpu honestly 'none' when absent", out["gpu"]["backend"] == "none", out["gpu"])


class _FrozenSW:
    """A Stopwatch stand-in with a fixed wall time, so summarisation math is testable without a clock."""
    def __init__(self, frames, wall, decode, infer, pipeline):
        self.frames = frames
        self._wall = wall
        self.decode_s = decode
        self.infer_s = infer
        self.pipeline_s = pipeline

    def total_s(self):
        return self._wall


# ── end-to-end: scripted detector → REAL pipeline → metrics ──────────────────────────────────
def test_end_to_end_pipeline():
    try:
        import numpy  # noqa: F401
        import supervision  # noqa: F401
    except Exception as exc:
        print(f"  SKIP  end-to-end pipeline (numpy/supervision absent: {exc})")
        return

    from app.movement.config import MovementConfig
    from app.movement.pipeline import MovementPipeline
    from bench.detectors.detector_scripted import scripted_trajectory
    from bench.run import RecordingDetector, score

    frames = 14
    script, gt_boxes = scripted_trajectory(250, 350, frames=frames, x=300, line_y=300)
    cfg = MovementConfig(store="wh-x", zone_uid="D->EXIT", line=((0, 300), (640, 300)))

    from app.movement.detector import ScriptedDetector
    rec = RecordingDetector(ScriptedDetector(script), carton_class_id=cfg.carton_class_id)
    trace, events = [], []
    cur = {"ts": 0.0}

    def sink(p):
        p = dict(p)
        p["ts"] = cur["ts"]
        events.append(p)

    pipe = MovementPipeline(cfg, rec, sink, tracer=trace.append)
    for i in range(frames):
        cur["ts"] = float(i)
        pipe.process(i, float(i))

    move_events = [e for e in events if e["event"] == "carton.move"]
    check("e2e: pipeline emitted exactly one carton.move", len(move_events) == 1, f"{len(move_events)}")
    if not move_events:
        return
    emitted_dir = move_events[0]["direction"]
    emitted_ts = move_events[0]["ts"]

    # Build GT that DECLARES the observed crossing correct (proving the scorer ingests real output),
    # with verified boxes on a few frames for detection/tracking scoring.
    gt_frames = {i: GtFrame(index=i, carton_present=True, boxes=[GtBox(gt_boxes[i])])
                 for i in range(2, frames - 2)}
    m = Manifest(clip_id="selftest", video="-", store="wh-x", zone_uid="D->EXIT",
                 line=((0, 300), (640, 300)), fps=1.0, session="selftest", split="test",
                 frames=gt_frames,
                 crossings=[GtCrossing("A", emitted_dir, frame=int(emitted_ts), ts=emitted_ts)])

    sw = _FrozenSW(frames=frames, wall=1.0, decode=0.1, infer=0.5, pipeline=0.4)
    r = score(m, rec.by_frame, trace, events, sw, [1.0] * frames, tol_s=2.0)
    check("e2e: movement scored exactly-one-correct rate 1.0",
          r["movement"]["exactly_one_correct_rate"] == 1.0, r["movement"])
    check("e2e: detector recall > 0 on scripted boxes (dets fed the pipeline)",
          r["detection"]["recall"] > 0.0, r["detection"])
    check("e2e: reverse trajectory flips direction (independent check)",
          _reverse_dir_differs(emitted_dir), "reverse produced same direction")


def _reverse_dir_differs(down_dir):
    from app.movement.config import MovementConfig
    from app.movement.detector import ScriptedDetector
    from app.movement.pipeline import MovementPipeline
    from bench.detectors.detector_scripted import scripted_trajectory

    script, _ = scripted_trajectory(350, 250, frames=14, x=300, line_y=300)   # upward
    cfg = MovementConfig(store="wh-x", zone_uid="D->EXIT", line=((0, 300), (640, 300)))
    ev = []
    pipe = MovementPipeline(cfg, ScriptedDetector(script), lambda p: ev.append(p))
    for i in range(14):
        pipe.process(i, float(i))
    ups = [e for e in ev if e["event"] == "carton.move"]
    return len(ups) == 1 and ups[0]["direction"] != down_dir


def main():
    print("BENCHMARK SELF-TEST — metric math + real-pipeline integration\n")
    for fn in (test_detection_math, test_movement_math, test_tracking_math, test_system_math,
               test_end_to_end_pipeline):
        fn()
    print(f"\n{_pass} passed, {_fail} failed")
    return 1 if _fail else 0


if __name__ == "__main__":
    raise SystemExit(main())
