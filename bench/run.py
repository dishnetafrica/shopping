"""
The benchmark runner — one command from a labelled clip (or a folder of them) to a full report.

    python3 bench/run.py path/to/clip.json [more.json ...] [--out out/report] [--tol 1.0]

Flow per clip:  timed decode → detector (single inference pass, recorded) → app.movement pipeline
(real sv.ByteTrack + sv.LineZone + MovementStateMachine, traced) → detection/tracking/movement/system
metrics → report.

`score()` is the pure metric-assembly step (no cv2, no model); it is what bench/selftest.py exercises
with scripted known-answer inputs to prove the metric math in the sandbox. `run_clip()` is the
real-footage pass that needs cv2 + a real detector on a real machine.

Which DETECTOR to benchmark is deliberately NOT decided here (YOLOX vs RF-DETR is the open question):
pass a factory via `--detector module:function`, or import run_clip and hand it a detector. The
zero-shot model is NOT accepted here as a scored detector — it is a pre-label tool only (see prelabel.py).
"""
from __future__ import annotations

import importlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from bench import contract_ref
from bench.manifest import Manifest, hard_errors, validate
from bench.metrics import detection, movement, system, tracking
from bench.report import render


class RecordingDetector:
    """Delegates to the real detector and records carton detections per frame index (call order),
    so detection precision/recall reuses the SAME single inference pass the pipeline runs — no
    second forward pass. Also records raw per-call inference time for the system stopwatch."""

    def __init__(self, inner, carton_class_id=1):
        self.inner = inner
        self.carton_class_id = carton_class_id
        self.by_frame: dict = {}
        self._i = -1
        self.infer_s = 0.0

    def detect(self, frame, ts):
        import time
        self._i += 1
        t0 = time.perf_counter()
        dets = self.inner.detect(frame, ts)
        self.infer_s += time.perf_counter() - t0
        rows = []
        cid = getattr(dets, "class_id", None)
        for k in range(len(dets)):
            if cid is not None and int(cid[k]) != self.carton_class_id:
                continue
            box = tuple(float(v) for v in dets.xyxy[k])
            conf = float(dets.confidence[k]) if dets.confidence is not None else 0.9
            rows.append((box, conf))
        self.by_frame[self._i] = rows
        return dets


def score(manifest: Manifest, recorded_dets: dict, trace: list, events: list, sw, latencies,
          tol_s: float = 1.0) -> dict:
    """Pure metric assembly — the sandbox-provable core. No cv2, no model, no camera."""
    gt_frames = manifest.frames
    per_frame_gts = {idx: [b.box for b in gf.boxes] for idx, gf in gt_frames.items()}
    moves = [e for e in events if e.get("event") == contract_ref.CARTON_MOVE]

    return {
        "clip_id": manifest.clip_id,
        "session": manifest.session,
        "split": manifest.split,
        "store": manifest.store,
        "zone_uid": manifest.zone_uid,
        "capture": manifest.capture,
        "detection": detection.evaluate(recorded_dets, per_frame_gts),
        "tracking": tracking.evaluate(trace, manifest.crossings, gt_frames),
        "movement": movement.evaluate(moves, manifest.crossings, tol_s=tol_s, fps=manifest.fps),
        "system": system.summarise(sw, latencies),
    }


def run_clip(manifest: Manifest, detector, video_root: str = ".", tol_s: float = 1.0) -> dict:
    """Real-footage pass: decode → detect → pipeline → score. Needs cv2 + a real detector."""
    import time

    from app.movement.config import MovementConfig
    from app.movement.pipeline import MovementPipeline
    from bench.ingest import TimedVideoReader

    cfg = MovementConfig(store=manifest.store, zone_uid=manifest.zone_uid, line=manifest.line)
    rec = RecordingDetector(detector, carton_class_id=cfg.carton_class_id)

    trace: list = []
    events: list = []
    sw = system.Stopwatch().start()
    latencies: list = []
    cur = {"ts": 0.0}

    def sink(payload):
        payload = dict(payload)
        payload["ts"] = cur["ts"]          # tag with the frame time it fired at (for movement scoring)
        events.append(payload)

    pipe = MovementPipeline(cfg, rec, sink, tracer=trace.append)

    reader = TimedVideoReader(os.path.join(video_root, manifest.video), fps_hint=manifest.fps)
    for idx, frame, ts, decode_s in reader.frames():
        cur["ts"] = ts
        sw.decode_s += decode_s
        t0 = time.perf_counter()
        pipe.process(frame, ts)
        dt = time.perf_counter() - t0
        latencies.append(dt * 1000.0)
        sw.pipeline_s += dt
        sw.frames += 1
    sw.infer_s = rec.infer_s
    sw.pipeline_s = max(0.0, sw.pipeline_s - rec.infer_s)

    return score(manifest, rec.by_frame, trace, events, sw, latencies, tol_s=tol_s)


def _load_detector_factory(spec: str):
    """spec = 'module.path:function' → call it to get a detector (satisfying Detector.detect)."""
    mod_name, _, fn = spec.partition(":")
    mod = importlib.import_module(mod_name)
    return getattr(mod, fn)()


def main(argv):
    import argparse

    ap = argparse.ArgumentParser(description="ShopBot Vision carton-crossing benchmark")
    ap.add_argument("manifests", nargs="+", help="clip manifest JSON file(s)")
    ap.add_argument("--detector", required=True,
                    help="'module:function' returning a detector (e.g. mydets:yolox). "
                         "The zero-shot model is NOT valid here — it is a pre-label tool only.")
    ap.add_argument("--video-root", default=".")
    ap.add_argument("--out", default="out/benchmark")
    ap.add_argument("--tol", type=float, default=1.0, help="crossing match tolerance, seconds")
    args = ap.parse_args(argv)

    detector = _load_detector_factory(args.detector)
    results = []
    for path in args.manifests:
        m = Manifest.load(path)
        problems = validate(m)
        errs = hard_errors(problems)
        if errs:
            print(f"[skip] {path}: {errs}", file=sys.stderr)
            continue
        for note in (p for p in problems if p.startswith("note:")):
            print(f"[warn] {m.clip_id}: {note}", file=sys.stderr)
        results.append(run_clip(m, detector, video_root=args.video_root, tol_s=args.tol))

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out + ".json", "w", encoding="utf-8") as fh:
        json.dump(results, fh, indent=2)
    md = render(results)
    with open(args.out + ".md", "w", encoding="utf-8") as fh:
        fh.write(md)
    print(f"wrote {args.out}.json and {args.out}.md ({len(results)} clip(s))")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
