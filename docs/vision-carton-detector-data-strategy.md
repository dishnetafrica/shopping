# ShopBot Vision — Carton Detector: Data & Training Strategy (research, no code)

**Design only. No training code, no change to the production movement pipeline.** This establishes
*our* model/data pipeline so we — not the hardware supplier — own the detector choice, then benchmark
the same model across candidate boxes.

## The division of labour (unchanged, and load-bearing)

```
Carton detector (neural) → ByteTrack → LineZone → MovementStateMachine → carton.move → reconciliation
   "what is a carton?"        └──────── deterministic: "did it cross, which way, exactly once?" ───────┘
```

The neural model learns **only** "what is a carton?". Crossing, direction, and exactly-once are the
deterministic pipeline's job (already built + tested). So the detector is a **replaceable component**
behind the `Detector` interface — it can be retrained, swapped, or re-exported without touching the
pipeline or the `carton.move` contract.

---

## CRITICAL RULE — public data is BOOTSTRAP, not production accuracy

Public carton datasets get us a *working* detector fast. They **do not** establish production
accuracy. Production accuracy must be measured on **real warehouse footage from our target camera
position**, covering: brown / printed / taped / shrink-wrapped cartons; small/medium/large; stacked;
multiple; worker occlusion; backlighting; low light; damaged cartons; and **people crossing without
cartons**. No public-dataset number may be reported as our accuracy.

---

## 1–3. Dataset research (bootstrap candidates)

| Dataset | URL | Size | Classes | Format | License (dataset) | Commercial | Warehouse relevance |
|---|---|---|---|---|---|---|---|
| **Roboflow Universe — carton/cardboard** (many) | universe.roboflow.com (e.g. `ps-uqsf0/cardboard-box-detection`) | ~60 → **~9.8k** images each; dozens of sets | `carton` / `cardboard box` (some add open/closed, damage) | YOLO & COCO export | Mostly **CC BY 4.0** (per-dataset — verify each) | ✅ with **attribution** | Direct carton class, but mixed domains (packaging, recycling, desks); **not** our camera geometry |
| **Open Images V7 — "Box" / packaging classes** | storage.googleapis.com/openimages | 1.9M imgs / 16M boxes / 600 classes | generic `Box` (+ related) | box CSV → COCO | **Annotations CC BY 4.0 (Google)**; **images CC BY 2.0, per-image unverified** | ✅ annotations; ⚠️ verify images | Generic boxes in the wild; broad negatives; permissive labels |
| **LOCO — Logistics Objects in Context (TUM)** | github.com/tum-fml/loco | 39,101 imgs; 5,593 annotated; 151k instances | pallet, **small load carrier**, stillage, forklift, pallet truck (**not "carton"**) | **COCO** | **Public domain (CC0-style)** | ✅ **no restrictions** | **Real warehouse scenes** — best domain match; SLC ≈ box-proxy; use for environment/negatives, not the carton class itself |
| SKU110K (noted, not chosen) | github (retail) | 11k imgs | densely-packed retail products | — | research terms | ⚠️ | Retail *shelf* products, not dispatch cartons — low relevance |

**Reading:** Roboflow (carton class) + Open Images (generic box, permissive labels) give the **class**;
LOCO gives the **warehouse domain** (and free negatives / occlusion realism), commercially unrestricted.
None matches our camera position — that is exactly what our own footage is for.

## 4–5. Model candidates & the separated licensing table

Repository ≠ weights ≠ training data — recorded separately (full detail: `bench/MODEL_LICENSES.md`).

| Model | Code license | Weight license | Training-data license | Commercial (production) | Attribution | Notes for our targets |
|---|---|---|---|---|---|---|
| **YOLOX** (CNN) | Apache-2.0 ✅ | Apache-2.0 ⚠️ (weights not stated *separately*; issue #1865 — or train our own) | COCO (mixed Flickr img licenses) ⚠️ | ✅ | Yes | **CNN → exports cleanly to BOTH RKNN (RK3588 NPU) and TensorRT (Jetson)** — best hardware portability |
| **RF-DETR** (transformer) | Apache-2.0 ✅ (base; **`_plus`/XL = PML-1.0, avoid**) | Apache base ⚠️ (pin version — issue #592) | COCO/O365 + **DINOv2 backbone** provenance ⚠️ | ✅ (Apache base only) | Yes | Higher accuracy; TensorRT ✅ (~25 FPS Orin Nano); **RK3588 only partial** (transformer ops → backbone-on-NPU/head-on-CPU split) |
| **Grounding DINO** (zero-shot) | Apache-2.0 ✅ | Apache-2.0 ✅ | ~1.8M public imgs (authors assert commercial-licensed) ⚠️ log provenance | **Research/bootstrap only** (policy) | Yes | Pre-label proposals only — never production, never the yardstick |
| **YOLO-World** | **GPL-3.0** 🚫 | separate commercial | — | 🚫 **excluded** | — | Not in the production path unless a commercial licence is explicitly approved |
| Supervision / ByteTrack | MIT ✅ | — | — | ✅ | — | Pipeline infra (tracking/line), already in use |

**Bootstrap detector (Q4):** yes — **Grounding DINO** (zero-shot, Apache) as the *pre-label & first-light*
detector; a small YOLOX fine-tuned on the public carton sets can also serve as an early bootstrap once
labels exist. Neither is treated as ground truth.

---

## The training pipeline (design)

```
Public carton datasets (Roboflow CC-BY + Open Images CC-BY) + LOCO (warehouse domain, CC0)
        ↓  (train a first YOLOX; commercial-clean)
Bootstrap detector  ──────────────┐
        ↓                         │
Real ShopBot warehouse footage (target camera position, the condition list above)
        ↓                         │
Zero-shot (Grounding DINO) / bootstrap detector PROPOSES boxes  ←── proposals, NOT truth
        ↓                         │
HUMAN verifies / corrects  ───────┘  (AI proposal → human correction → verified GT)
        ↓
ShopBot carton dataset  (versioned; held-out test split from a DIFFERENT session/day)
        ↓
Train production detector (YOLOX primary; RF-DETR alt for Jetson-only)
        ↓
Benchmark on held-out REAL warehouse footage  (bench/ harness; exactly-one-correct metric)
        ↓
Export for edge hardware (RKNN / TensorRT / ONNX) → version + ship
```

Every stage already has a home: pre-label + verified-GT format (`bench/prelabel.py`, `bench/manifest.py`),
benchmark (`bench/`), export/versioning (below). This document adds no code — it names the sequence.

## D. How much of OUR footage/labels?

Iterative, and **measured by the exactly-one-correct rate on held-out real footage, not by image count**:
- **Phase A — first light:** ~**0** labels. Zero-shot bootstrap proves the plumbing on real pixels.
- **Phase B — first fine-tune + honest eval:** ~**300–800 human-verified frames** from the target door,
  deliberately spread across the condition list (diversity **over** count), + a held-out test set from a
  **different** session/day. This is enough to know whether the concept holds.
- **Phase C — production hardening:** scale to a few **thousand carton instances**, adding examples of
  whatever the benchmark shows failing (stacked, backlit, occluded, damaged, people-without-cartons).
  Stop when held-out exactly-one-correct plateaus — not at a fixed number.

**The thesis this tests:** bootstrap the detector *largely* from open data, then make it ShopBot-specific
with a *relatively small* amount of real footage. Plausible — but only the Phase-B held-out numbers
confirm it.

## E. Export to edge hardware

Common path: fine-tuned weights → **ONNX (opset 12**, static input size, RKNN-safe ops).

| Target | Path | Format | Precision | Notes |
|---|---|---|---|---|
| **RK3588** | ONNX → `rknn-toolkit2` | `.rknn` | INT8 (default) / FP16 | **YOLOX/CNN maps to NPU cleanly**; RF-DETR needs backbone-on-NPU + head-on-CPU split |
| **Jetson Orin Nano** | ONNX → `trtexec` | `.engine` | FP16 (INT8 w/ calibration) | Both YOLOX & RF-DETR work; RF-DETR ~25 FPS reported |
| **Jetson Orin NX** | same as Nano | `.engine` | FP16 / INT8 | Same path, more headroom (more cameras/box) |
| x86 + accel / fallback | ONNX Runtime / PyTorch | `.onnx` | FP32/FP16 | Reference to validate quantisation drift vs the edge build |

**Portability consequence:** because we must run on *both* RK3588 and Jetson, **YOLOX is the safer
primary** (one CNN exports to both NPU and GPU). RF-DETR stays the accuracy-ceiling option for
Jetson-only sites. The benchmark on the actual boxes decides.

## F. Model versioning on the edge box

- Model is a **separate versioned artifact**, never baked into the app image:
  `./data/models/<model-version>/` = the per-target build (`.rknn` / `.engine` / `.onnx`) + a
  `manifest.json` recording `{model_version, dataset_version, training_commit, classes, input_size,
  export_target, license_refs, heldout_metrics}`.
- Config names the **active** `model_version`; the box reports it (health/heartbeat, when telemetry is
  built).
- **Swap = download a new model dir + health-gate (selftest + a canary clip) + promote**; keep the
  previous dir for **rollback**. A model update is decoupled from an app-image update — no full rebuild.
- Every deployed model traces to a dataset version + training commit + export target (reproducibility).
- This reuses the OTA/versioning shape from `docs/vision-edge-deployment-architecture.md §7` — **not
  built here**, just where the model plugs in.

---

## Recommendations (A–F)

- **A. Bootstrap datasets:** **Roboflow Universe carton/cardboard sets (CC BY 4.0)** for the carton
  class + **Open Images "Box" (CC BY 4.0 annotations)** for generic boxes/negatives + **LOCO (CC0)** for
  warehouse-domain realism. All commercially usable (attribution for the CC-BY ones; verify each
  Roboflow set individually). **Bootstrap only.**
- **B. Zero-shot / pre-label:** **Grounding DINO** (Apache) to propose carton boxes on our footage →
  human-verify into ground truth. Research/bootstrap only; never scored against itself.
- **C. Production detector:** **YOLOX** (Apache, CNN) as the **primary** — the only candidate that
  exports cleanly to *both* RK3588 (RKNN/NPU) and Jetson (TensorRT). **RF-DETR** (Apache base) as the
  **accuracy-ceiling alternative for Jetson-class sites**. Final pick = benchmark on the real boxes.
  **YOLO-World stays excluded (GPL).**
- **D. Our footage:** ~300–800 verified frames (diverse conditions) for the first fine-tune + honest
  eval; scale to a few thousand instances as the benchmark exposes failure modes. Held-out test from a
  different session. Count is not the target — the held-out metric is.
- **E. Export:** ONNX(opset 12) → RKNN (RK3588, INT8/FP16) / TensorRT `.engine` (Orin Nano & NX,
  FP16/INT8) / ONNX-CPU fallback. YOLOX for portability; RF-DETR TensorRT-only where it wins.
- **F. Versioning:** per-target model artifacts under `./data/models/<version>/` + manifest; config
  selects active version; health-gated swap with rollback; decoupled from app-image updates.

**Bottom line:** the thesis (bootstrap from open data, specialise with a small amount of real footage)
is **credible and commercially clean** — Roboflow + Open Images + LOCO are all usable, YOLOX/RF-DETR are
both Apache. It is **not proven** until Phase-B held-out numbers on our own warehouse footage exist.
That, again, is the experiment the hardware unblocks — not something any dataset can settle on paper.

_Sources checked 2026-08; re-verify at download/train time (licenses and releases change)._
