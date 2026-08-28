# Model & License Manifest — ShopBot Vision detector candidates

**Purpose.** Record, per model, the *four separate* license/provenance facts before any model is used
— and especially before any model enters the commercial edge image. **Do not assume repository license
= model-weight license = training-data license.** A permissive repo can ship weights trained on data
with its own terms.

**Governing rules (owner):**
- The **zero-shot / open-vocabulary** model is a **research / pre-label tool only**. It **must not** be
  a production dependency and **must not** be included in the ShopBot commercial edge image.
- **YOLO-World is excluded from production** until its commercial licensing is explicitly resolved
  (its official repo is GPL-3.0).
- Grounding DINO **may** be used as a research/bootstrap candidate, subject to the provenance notes below.
- No model is chosen as the production detector yet. This manifest is a gate, not a decision.

Legend: ✅ confirmed (source cited) · ⚠️ needs verification before commercial use · 🚫 excluded from production.

---

## Candidates

### Grounding DINO — *research / pre-label candidate only*
| Field | Status | Notes |
|---|---|---|
| Repository | IDEA-Research/GroundingDINO | https://github.com/IDEA-Research/GroundingDINO |
| Code license | ✅ Apache-2.0 | LICENSE in repo |
| Weight license | ✅ Apache-2.0 (asserted by authors) | Confirm the *specific* checkpoint you download |
| Training-data provenance | ⚠️ | Authors state the original ~1.8M training images were drawn from public datasets "with commercial licenses"; **log the exact checkpoint + its dataset list** before relying on that claim. GroundingDINO **1.5** is a separate API product with its own terms — do **not** conflate with the open Apache model. |
| Commercial deployment | 🚫 in production by policy | Research/pre-label use only, regardless of license |
| Attribution required | Yes (Apache-2.0 NOTICE) | |

### YOLOX — *production candidate (Apache), needs fine-tuning on our cartons*
| Field | Status | Notes |
|---|---|---|
| Repository | Megvii-BaseDetection/YOLOX | https://github.com/Megvii-BaseDetection/YOLOX/blob/main/LICENSE |
| Code license | ✅ Apache-2.0 | LICENSE in repo |
| Weight license | ⚠️ | Widely treated as Apache-2.0, but the pretrained-weights license was **not stated separately** from the code (see issue #1865). If we ship official pretrained weights, get this in writing or **train our own from scratch on our data**. Fine-tuning on *our* footage reduces reliance on the COCO-pretrained checkpoint. |
| Training-data provenance | ⚠️ | Official checkpoints pretrain on **COCO** (images: mixed Flickr licenses; annotations: CC-BY-4.0). Document if used as a starting point. |
| Commercial deployment | ✅ permitted (code) | Weight caveat above must be closed first |
| Attribution required | Yes (Apache-2.0) | |

### RF-DETR — *production candidate (Apache), pin the base model + version*
| Field | Status | Notes |
|---|---|---|
| Repository | roboflow/rf-detr | https://github.com/roboflow/rf-detr/blob/develop/LICENSE |
| Code license | ✅ Apache-2.0 | Roboflow states models + code are free for commercial, closed-source use |
| Weight license | ⚠️ pin carefully | **Base "Apache-designated" models are Apache-2.0.** The **`rfdetr_plus` / RF-DETR-XL/2XL** components are **PML-1.0, NOT Apache** — do **not** use those in production. Also note a licensing change landed in the 1.4.0 release (issue #592): **pin an exact version** and record it. |
| Backbone provenance | ⚠️ | Built on a **DINOv2** ViT backbone — log DINOv2's own weight license for the checkpoint used (DINOv2 ≠ DINOv3; they differ). |
| Training-data provenance | ⚠️ | COCO / RF100-VL pretraining — document the exact base checkpoint |
| Commercial deployment | ✅ permitted (Apache base only) | Avoid Plus/PML tiers |
| Attribution required | Yes (Apache-2.0) | |

### YOLO-World — 🚫 EXCLUDED FROM PRODUCTION
| Field | Status | Notes |
|---|---|---|
| Repository | AILab-CVC/YOLO-World | https://github.com/AILab-CVC/YOLO-World |
| Code license | 🚫 GPL-3.0 | Copyleft; commercial licensing offered separately by the authors |
| Decision | Excluded from any production/edge image | Not wired into bench/ as a scored detector. Revisit only if commercial licensing is explicitly resolved. |

---

## Infrastructure (already in use)

| Component | License | Status | Notes |
|---|---|---|---|
| Supervision (ByteTrack + LineZone wiring) | MIT | ✅ | roboflow/supervision |
| ByteTrack (algorithm/impl) | MIT | ✅ | Original ifzhang/ByteTrack is MIT; supervision's is MIT. Deprecated in supervision ≥0.28 → isolated behind `MovementPipeline` for a one-file swap |
| OpenCV (cv2) | Apache-2.0 (opencv-python build) | ✅ | Verify the specific wheel/build shipped |

---

## Process

1. Before downloading any weight file, add/confirm its row here (all four fields).
2. Never let a ⚠️ weight/data row reach the commercial edge image — resolve it first (get terms in
   writing, or train from scratch on our own footage).
3. Keep the zero-shot model out of the deployed image entirely (it lives only in `bench/`).
4. Record the exact version/commit and checkpoint filename actually used in each benchmark run.

_Facts here were checked against the linked sources on 2026-08; re-verify at download time, since
licenses and releases change (e.g. RF-DETR issue #592)._
