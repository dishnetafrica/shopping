# Track A — ShopBot Stock Object Detector v0.1 (training pipeline)

Builds a **generic `stock_object`** detector from the approved commercial datasets in
`datasets.yaml`. This directory is the **reproducible training pipeline**; it does not deploy (like
`bench/` and `tests/`, it rides in the repo for reference).

## Honest boundary (read first)

**The ShopBot sandbox has no GPU, limited ephemeral disk, and no dataset API credentials — it cannot
download tens of GB or train YOLOX.** So this task delivers the *pipeline*, not a fabricated model:
- ✅ **Runs here (proven, `python3 training/selftest.py` → 23/0):** class normalization map, deterministic
  train/val/test split, and the evaluation wiring (shared with `bench/metrics/detection.py`).
- ⏳ **Runs on a GPU box / Colab (produces the real artifacts):** dataset download, `prepare.py`,
  `yolox.tools.train`, ONNX export, `evaluate.py` on the real test split.

**No trained model or accuracy number is invented here.** Deliverables 3 (model) and 4 (metrics) are
produced by running the steps below on a GPU; I will then ingest and critique those real results.

## What's built

| File | Role | Runs in sandbox? |
|---|---|---|
| `datasets.yaml` | approved source + license register (NC/unverified excluded) | data |
| `classmap.py` | source class → `stock_object` (+ advisory subclass); negatives → dropped | ✅ tested |
| `prepare.py` | parse → remap → dedup → split → unified single-class dataset + provenance manifest | ⏳ needs data |
| `exp_stock_object.py` | YOLOX Exp config (single class, yolox-s, 640, strong aug) | ⏳ needs YOLOX/GPU |
| `evaluate.py` | precision/recall/mAP@0.5 on the test split via `bench/metrics/detection.py` | ✅ (any predictions/GT) |
| `selftest.py` | proves classmap + split + eval logic | ✅ 23/0 |

## Run it (GPU box or Colab)

```bash
# 0. env: Python 3.11, a CUDA GPU, official YOLOX (Apache-2.0), pyyaml, pillow
pip install yolox pyyaml pillow   # + torch/torchvision matching your CUDA

# 1. Download the approved sources into <root>/<source-id>/ and VERIFY each license.
#    Roboflow sets: record the exact dataset id + its CC BY 4.0 (or other) license in provenance.
#    Do NOT download the excluded ones (Amazon Bin = NC, YouTube/Vimeo = not licensed).

# 2. Assemble the unified single-class dataset + provenance manifest
python training/prepare.py --root /data/sources --out /data/v0_1
#    (finish the 4 elided format parsers in prepare.py for your downloaded layouts — the only TODO)

# 3. Train YOLOX v0.1 (single class stock_object)
python -m yolox.tools.train -f training/exp_stock_object.py -d 1 -b 16 --fp16 -o -c yolox_s.pth

# 4. Export ONNX (opset 12 → later RKNN/TensorRT, per the export matrix)
python -m yolox.tools.export_onnx -f training/exp_stock_object.py \
    -c YOLOX_outputs/stock_object_v0_1/best_ckpt.pth --output-name stock_object_v0_1.onnx --opset 12

# 5. Honest eval on the held-out PUBLIC test split
python training/evaluate.py --pred predictions.json --gt ground_truth.json
```

Optional RF-DETR benchmark (only if compute/time is reasonable): train RF-DETR (Apache base) on the same
`/data/v0_1` and compare mAP + latency — accuracy ceiling reference, not the edge default.

## Required outputs of the GPU run (the 7 the task asked for)

1. **Dataset manifest** with per-source license/provenance (`/data/v0_1/manifest.json` from `prepare.py`).
2. **Training configuration** (`exp_stock_object.py`, committed).
3. **YOLOX v0.1 model** (`best_ckpt.pth` + `stock_object_v0_1.onnx`).
4. **Validation/test metrics** (`evaluate.py` output — precision/recall/mAP@0.5/FP).
5. **Failure examples** (worst false-positives/negatives on the test split — save the images).
6. **Model-size / inference requirements** (ONNX size, params, CPU/GPU latency at 640).
7. **What remains unproven** (below).

## What remains unproven without real warehouse footage

- These are **PUBLIC-DATA** numbers = **bootstrap accuracy, not production accuracy.** They say the model
  learned "package objectness" in general imagery — **not** that it works at a real dispatch door.
- **Unmeasured until Track B:** target-camera geometry, real lighting/backlight/low-light, worker
  occlusion, stacked/damaged packages, African godown domain, CCTV compression, and the operational
  metric that actually matters — **one physical package crossing → exactly one `stock.move`** (that is a
  `bench/` pipeline test on real video, not a detector mAP number).
- **v0.1 is expected to be mediocre — that's the point.** Run it on existing CCTV via `bench/`, collect
  the failures, and those failures define the labels for v0.2.

## Scope honoured

No Laravel / reconciliation / `MovementStateMachine` / `carton.move` / `stock.move` change. No customer
deployment. No NC datasets, no YouTube/Vimeo footage. The detector stays behind the `Detector` interface;
`training/` only produces a model artifact the edge later loads.
