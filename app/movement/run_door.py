"""
Run ONE dispatch door: RTSP → detector → Supervision/ByteTrack/LineZone → MovementStateMachine →
carton.move → the existing outbox (events.send → SQLite → HTTPS → ShopBot). This is the Phase-1
single-box entrypoint — the smallest runnable thing that answers "can one box turn one real camera
into one correct carton.move?".

    # plumbing selftest — no camera, no model: emit one synthetic carton.move to the outbox
    python -m app.movement.run_door --selftest --store wh-juba-01 --zone "D->EXIT"

    # real door — needs a detector factory (see bench/README.md) and ffmpeg/opencv in the image
    python -m app.movement.run_door --rtsp "rtsp://user:pass@cam/Streaming/Channels/101" \
        --store wh-juba-01 --zone "D->EXIT" --line 0,540,1920,540 --detector mydetectors:make

Hardware-independent: the detector (and thus the inference runtime — PyTorch / TensorRT / RKNN) is
injected by `--detector module:function`; nothing NVIDIA/RKNN-specific lives here or in the pipeline.
For first-light on real pixels before a carton model is trained, the detector can be the research
zero-shot bootstrap (Grounding DINO) — see bench/MODEL_LICENSES.md; it is NOT for production.
"""
from __future__ import annotations

import argparse
import importlib
import json
import sys

from .config import MovementConfig


def _parse_line(s: str):
    v = [float(x) for x in s.split(",")]
    if len(v) != 4:
        raise argparse.ArgumentTypeError("--line must be ax,ay,bx,by")
    return ((v[0], v[1]), (v[2], v[3]))


def _load_detector(spec: str):
    mod_name, _, fn = spec.partition(":")
    if not fn:
        raise SystemExit(f"--detector must be 'module:function', got {spec!r}")
    return getattr(importlib.import_module(mod_name), fn)()


def _sink(dry_run: bool):
    if dry_run:
        def sink(payload):
            print(json.dumps(payload))
        return sink
    from app import events  # lazy: pulls cv2-coupled modules; only needed for the real outbox path
    return events.send


def selftest(cfg: MovementConfig, dry_run: bool) -> int:
    """Emit ONE synthetic carton.move through the real contract + sink, no camera and no model.
    Proves detector→pipeline→contract→outbox wiring the moment a box is powered on."""
    import time

    from .state_machine import Crossing, Frame, MovementStateMachine

    sink = _sink(dry_run)
    m = MovementStateMachine(cfg.store, cfg.zone_uid, sink)
    m.observe(Frame(ts=time.time(), crossings=[Crossing(track_id=1, direction="out", confidence=0.9)]))
    print("selftest: emitted one synthetic carton.move "
          + ("(dry-run, not delivered)" if dry_run else "to the outbox"), file=sys.stderr)
    return 0


def run(cfg: MovementConfig, rtsp: str, detector, max_fps: float, dry_run: bool) -> int:
    from .pipeline import MovementPipeline
    from .sources import RtspFrameSource

    sink = _sink(dry_run)
    pipe = MovementPipeline(cfg, detector, sink)
    source = RtspFrameSource(rtsp, max_fps=max_fps)
    print(f"door running: {cfg.store}/{cfg.zone_uid}  line={cfg.line}  rtsp set, max_fps={max_fps}",
          file=sys.stderr)
    pipe.run(source)          # runs until the stream ends / process is stopped
    return 0


def main(argv):
    ap = argparse.ArgumentParser(description="Run one dispatch door: RTSP → carton.move → outbox")
    ap.add_argument("--store", required=True)
    ap.add_argument("--zone", required=True, help="zone_uid, e.g. 'D->EXIT'")
    ap.add_argument("--line", type=_parse_line, default=((0, 540), (1920, 540)),
                    help="counting line ax,ay,bx,by (pixels)")
    ap.add_argument("--rtsp", help="RTSP URL (required unless --selftest)")
    ap.add_argument("--detector", help="'module:function' returning a Detector (required for --rtsp)")
    ap.add_argument("--person-class", type=int, default=0)
    ap.add_argument("--carton-class", type=int, default=1)
    ap.add_argument("--max-fps", type=float, default=8.0)
    ap.add_argument("--dry-run", action="store_true", help="print events instead of sending to outbox")
    ap.add_argument("--selftest", action="store_true", help="emit one synthetic carton.move, no camera")
    args = ap.parse_args(argv)

    cfg = MovementConfig(store=args.store, zone_uid=args.zone, line=args.line,
                         person_class_id=args.person_class, carton_class_id=args.carton_class)

    if args.selftest:
        return selftest(cfg, args.dry_run)
    if not args.rtsp:
        ap.error("--rtsp is required (or use --selftest)")
    if not args.detector:
        ap.error("--detector is required for a real run (see bench/README.md; for first light the "
                 "research zero-shot bootstrap may be used — bench/MODEL_LICENSES.md)")
    return run(cfg, args.rtsp, _load_detector(args.detector), args.max_fps, args.dry_run)


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
