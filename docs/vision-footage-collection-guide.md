# ShopBot Vision — Footage Collection & Ground-Truth Guide (Phase 0.1)

**Goal of the first footage:** answer one question — *can a camera reliably observe a carton crossing
**one** dispatch-door boundary and produce **exactly one** correct `carton.move`?* This is a discovery
experiment, **not** a dataset-scale exercise. **10–30 short clips from one real dispatch door are
enough to start.** Do not wait for a "perfect" warehouse; capture one real door as soon as possible.

> The valuable asset is **AI proposal → human correction → verified ground truth.** The zero-shot
> pre-label pass only *proposes* boxes; a human makes them true. We never score accuracy against the
> zero-shot model's own predictions.

---

## 1. What to record

- **One dispatch door**, camera mounted **above and inside**, looking down at the threshold so a
  carton's bottom edge clearly crosses the doorway line. Fixed mount (no PTZ), stable exposure.
- **10–30 clips, 10–40 seconds each.** Prioritise **variety of conditions over image count:**
  - real Kampala/Juba warehouse lighting (daylight, overhead, mixed, backlit/silhouette)
  - real carton sizes and states — standard, taped/mixed, **stacked**, **damaged**
  - a worker **blocking** the carton mid-crossing (occlusion)
  - **multiple** cartons crossing close together
  - two workers at the door at once
  - a carton **approaching but not crossing**, and a **stationary** carton in view
  - both directions — OUT (dispatch) and IN (return/receiving)
- A few "boring" clips of normal traffic — these expose false crossings.

## 2. Preserve the ORIGINALS + context (this is the part people skip)

Keep the **original video files**, not just extracted frames, plus a metadata sheet per clip. This
context is what makes the corpus reusable across Kampala / Juba / Rwanda later:

- camera height (m), lens (mm / FOV), mounting angle, resolution, fps
- lighting description and time of day
- door geometry (type, width, camera position relative to threshold)
- carton types present
- **date/time and location**
- recording **session id** (e.g. `juba-2026-08-20-am`) — needed for held-out splits (§5)

These map directly onto the manifest `capture` block (see `bench/manifest.py` → `EXAMPLE_MANIFEST`).

## 3. Pre-label (research helper, optional)

Turn raw clips into *proposed* boxes to speed up human labelling:

```bash
# research venv only — needs torch + groundingdino (NOT in the production image)
python3 bench/prelabel.py clips/juba_door2_0007.mp4 \
    --store wh-juba-01 --zone "D->EXIT" --line 0,540,1920,540 --every 10 \
    --out labels/juba_door2_0007.draft.json
```

This writes a **draft** manifest: every frame `verified:false`, boxes tagged `proposed_by:zeroshot`.
**It proposes boxes only — never crossings.** See `bench/MODEL_LICENSES.md` before downloading weights.

## 4. Human verification → ground truth

Open each draft and **correct it into truth**. For the frames you choose to label, verify:

- **carton bounding boxes** (fix every proposed box; add missed ones; delete false ones)
- **carton present / not present**
- **occlusion** (is the carton partly hidden this frame?)
- **continuously visible** (or did tracking have to bridge a gap?)

Then add the **`crossings`** array by hand — the operational truth the zero-shot model cannot give:

- **crossing direction** (`in` / `out`)
- **crossing timestamp** (`ts`, seconds) and/or **frame index**
- **number of cartons** crossing in that event
- **occlusion** during the crossing

Set each labelled frame's `verified:true`. A clip is not ground truth until this is done. You do **not**
need to label every frame — a handful of frames around each crossing is enough for detection/tracking
metrics; the `crossings` list is what the headline metric needs.

Manifest schema and a filled example live in `bench/manifest.py` (`EXAMPLE_MANIFEST`).

## 5. Keep a held-out test set

Reserve some clips from a **different recording session/day** and mark them `"split": "test"`. The
benchmark reports train vs held-out separately; **only the held-out numbers indicate generalisation.**
Never tune against the test split.

## 6. Run the benchmark

```bash
python3 bench/run.py labels/*.json --detector mydetectors:make_yolox --video-root . --out out/bench
# writes out/bench.json + out/bench.md
```

`--detector` is a `module:function` returning a detector (a fine-tuned YOLOX or RF-DETR wrapped in
`bench.detectors.base.CallableDetector`). The zero-shot model is **not** valid here — it is a
pre-label tool only. See `bench/README.md` for the ~15-line detector wrapper recipe.

## 7. Read the result

The headline is **carton crossing → exactly one correct `carton.move`** on the held-out split. Detector
precision/recall, tracking continuity, and system FPS/RAM/GPU tell you *why* and *on what hardware*.
**No number is "production accuracy"** until it is over human-verified GT with a held-out split.

---

### Data handling
This is real warehouse footage with people in it. Store originals securely, restrict access, and treat
worker imagery per local privacy norms. The system's stance is **physical observation → ledger
reconciliation → human review** — never an automated accusation. Label and talk about the data the
same way.
