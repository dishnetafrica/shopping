"""
Pre-labelling tool — RESEARCH ONLY. Runs the zero-shot detector over a clip and writes a DRAFT
manifest of PROPOSED carton boxes for a human to correct into verified ground truth.

    python3 bench/prelabel.py clip.mp4 --store wh-juba-01 --zone D->EXIT \
        --line 0,540,1920,540 --every 10 --out clip.draft.json

The output is explicitly marked unverified: every frame carries "verified": false and
"proposed_by": "zeroshot:...". A human then (a) corrects the boxes, (b) sets carton_present /
occluded / continuously_visible, (c) adds the `crossings` (direction, frame, ts, count) — which the
zero-shot model does NOT and MUST NOT produce — and (d) flips verified→true. Only then is it truth.

Needs the research deps (torch + groundingdino), which are intentionally absent from production. This
module imports nothing heavy at import time; the model loads only when you actually run it.
"""
from __future__ import annotations

import argparse
import json
import os
import sys


def prelabel(video: str, store: str, zone: str, line, every: int, fps: float, out: str):
    from bench.detectors.zeroshot import CANDIDATE, ZeroShotDetector
    from bench.ingest import TimedVideoReader

    det = ZeroShotDetector().load()
    frames = {}
    for idx, frame, ts, _decode in TimedVideoReader(video, fps_hint=fps).frames():
        if idx % every != 0:
            continue
        props = det.propose(frame)
        frames[str(idx)] = {
            "verified": False,                         # a human MUST correct this
            "proposed_by": f"zeroshot:{CANDIDATE}",
            "carton_present": bool(props),
            "occluded": False,
            "continuously_visible": True,
            "boxes": [{"box": list(b), "label": "carton", "proposed_conf": round(c, 3)}
                      for (b, _cls, c) in props],
        }

    draft = {
        "clip_id": os.path.splitext(os.path.basename(video))[0],
        "video": video, "store": store, "zone_uid": zone,
        "line": [[line[0], line[1]], [line[2], line[3]]], "fps": fps,
        "session": "UNSET-fill-in-recording-session", "split": "train",
        "capture": {"_TODO": "fill camera_height_m, lens, resolution, lighting, door_geometry, "
                             "carton_types, datetime, location — see docs/vision-footage-collection-guide.md"},
        "frames": frames,
        "crossings": [],   # HUMAN adds these — zero-shot must never propose crossings
        "_WARNING": "DRAFT. Zero-shot proposals are NOT ground truth. Correct every box, add crossings, "
                    "then set each frame's verified=true. Do not benchmark against this until verified.",
    }
    with open(out, "w", encoding="utf-8") as fh:
        json.dump(draft, fh, indent=2)
    print(f"wrote draft {out}: {len(frames)} proposed frames — now HUMAN-VERIFY before benchmarking")


def main(argv):
    ap = argparse.ArgumentParser(description="Zero-shot pre-labelling (research only)")
    ap.add_argument("video")
    ap.add_argument("--store", required=True)
    ap.add_argument("--zone", required=True)
    ap.add_argument("--line", required=True, help="ax,ay,bx,by in pixels")
    ap.add_argument("--every", type=int, default=10, help="sample every Nth frame")
    ap.add_argument("--fps", type=float, default=25.0)
    ap.add_argument("--out", required=True)
    args = ap.parse_args(argv)
    line = [float(v) for v in args.line.split(",")]
    if len(line) != 4:
        ap.error("--line must be ax,ay,bx,by")
    prelabel(args.video, args.store, args.zone, line, args.every, args.fps, args.out)
    return 0


if __name__ == "__main__":
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    raise SystemExit(main(sys.argv[1:]))
