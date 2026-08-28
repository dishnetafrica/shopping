"""
Honest evaluation of a trained v0.1 model on the held-out PUBLIC test split.

Reuses the exact detection metrics from bench/metrics/detection.py (precision / recall / mAP@0.5 / FP)
so the training-side and the warehouse-benchmark side are scored the same way — no second, drifting
metric implementation.

Inputs are two JSON files in a simple shape (produced by running the model over the test split and by
reading the GT), so this runs anywhere — including the sandbox, on synthetic data, to prove the wiring
(selftest.py does exactly that). On a GPU box you feed it the real predictions.

  predictions.json : { "<image_id>": [[x0,y0,x1,y1, confidence], ...], ... }
  ground_truth.json: { "<image_id>": [[x0,y0,x1,y1], ...], ... }   (stock_object boxes)

  python training/evaluate.py --pred predictions.json --gt ground_truth.json
"""
from __future__ import annotations

import argparse
import json
import os
import sys

# reuse the ONE detection-metric implementation
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from bench.metrics import detection


def evaluate(pred: dict, gt: dict, iou_thr: float = 0.5) -> dict:
    per_frame_dets = {k: [((b[0], b[1], b[2], b[3]), float(b[4]) if len(b) > 4 else 1.0) for b in v]
                      for k, v in pred.items()}
    per_frame_gts = {k: [(b[0], b[1], b[2], b[3]) for b in v] for k, v in gt.items()}
    # score only images that have GT (the test split); ignore stray predictions on unlabelled images
    per_frame_dets = {k: per_frame_dets.get(k, []) for k in per_frame_gts}
    return detection.evaluate(per_frame_dets, per_frame_gts, iou_thr=iou_thr)


def main(argv):
    ap = argparse.ArgumentParser()
    ap.add_argument("--pred", required=True)
    ap.add_argument("--gt", required=True)
    ap.add_argument("--iou", type=float, default=0.5)
    args = ap.parse_args(argv)
    with open(args.pred) as fh:
        pred = json.load(fh)
    with open(args.gt) as fh:
        gt = json.load(fh)
    result = evaluate(pred, gt, iou_thr=args.iou)
    print(json.dumps(result, indent=2))
    print("\nNOTE: this is the PUBLIC test split — bootstrap accuracy, NOT production accuracy. "
          "Production accuracy requires target-camera warehouse footage (Track B).", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
