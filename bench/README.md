# `bench/` — ShopBot Vision carton-crossing benchmark (research, not deployed)

Answers one question on real footage: **can a camera reliably observe a carton crossing one dispatch
door and emit exactly one correct `carton.move`, and on what hardware?**

`bench/` lives outside `app/` on purpose — the Docker edge image copies `app/`, never `bench/`, so the
zero-shot / research deps here can never reach production. Dependency direction is one-way:
`bench → app.movement`.

## The one command

```bash
python3 bench/run.py labels/*.json --detector mydetectors:make_yolox --video-root . --out out/bench
# → out/bench.json (machine) + out/bench.md (human report)
```

Everything except the detector and the labelled clips is already built. You provide:
1. **verified label manifests** (`labels/*.json`) — see `docs/vision-footage-collection-guide.md`
2. a **detector factory** `module:function` returning something with `.detect(frame, ts) -> sv.Detections`

## Wiring a detector (~15 lines)

Any model becomes a benchmark detector via `CallableDetector`:

```python
# mydetectors.py
from bench.detectors.base import CallableDetector, PERSON_CLASS_ID, CARTON_CLASS_ID

def make_yolox():
    model = load_your_yolox()  # your fine-tuned, license-cleared weights (see bench/MODEL_LICENSES.md)
    def infer(frame):
        for det in model.predict(frame):          # adapt to your model's output
            cls = CARTON_CLASS_ID if det.label == "carton" else PERSON_CLASS_ID
            yield (det.xyxy, cls, det.confidence)  # (box, class_id, confidence)
    return CallableDetector(infer, name="yolox-cartons-v1")
```

Class ids follow `app.movement.config.MovementConfig`: person = 0, carton = 1.

## What you get (four groups, kept separate)

- **Detector** — precision, recall, mAP@0.5, TP/FP/FN over verified frame boxes
- **Tracking** — distinct tracks, continuity, ID switches, tracking-loss events
- **Movement** — true / missed / false crossings, duplicate `carton.move`, direction accuracy, and the
  headline **exactly-one-correct rate**
- **System** — FPS, latency p50/p95/max, peak RSS, GPU (honest "none" if absent), decode load

Train vs held-out (`"split":"test"`) are aggregated separately; only held-out shows generalisation.

## Zero-shot is a pre-label tool, not a detector

`bench/prelabel.py` uses an open-vocabulary model (Grounding DINO) to *propose* boxes for human
correction. It is **never** accepted by `run.py` as a scored detector, and its predictions are **never**
ground truth. Rules and licenses: `bench/MODEL_LICENSES.md`.

## Prove the harness itself (no footage needed)

```bash
python3 bench/selftest.py     # 20 checks: metric math + a real scripted-pipeline pass
```

The self-test proves the *metric math* and that a real `sv.ByteTrack + sv.LineZone` pass feeds the
metrics correctly. It **cannot** prove real detection/FPS/reliability — those need real warehouse
footage on the real box. That is the whole point of collecting footage next.
