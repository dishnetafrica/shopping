# ShopBot Vision — North-Star Architecture (and what is / isn't frozen)

The system-architecture diagram (Cameras → Edge AI Box → movement detection → `carton.move` →
ShopBot Cloud → inventory reconciliation → exception → human review → WhatsApp) is the **north-star
product target and visualisation.** It is **not** a finalised hardware bill of materials or a frozen
technical spec. This doc records that boundary so the picture is never mistaken for a commitment.

## The core product
**The ShopBot Vision software / reconciliation layer is the product.** The Edge Box is *replaceable
deployment hardware*, not the product. Intended commercial model: **~$49/month software.** Support
**existing customer CCTV** wherever possible; don't force a camera replacement.

## FROZEN — the conceptual architecture (do not drift)
1. **Physical observation → Ledger reconciliation → Exception → Human review.**
   A vision observation is **never** turned directly into an accusation of theft. The camera reports
   that stock physically moved; the *ledger* decides whether that movement is accounted for; a *human*
   reviews exceptions.
2. **The `carton.move` event contract** (`app/movement/contract.py`) — the boundary between the edge
   and ShopBot. It is stable and regression-tested; detector, runtime, and hardware may all change
   beneath it without touching it.
3. **Reconciliation lives only in ShopBot (Laravel).** The edge emits observations; it never reconciles.
   Do **not** change the Laravel reconciliation architecture based on the diagram.
4. **The pipeline shape:** `Detector → tracking (ByteTrack) → LineZone → MovementStateMachine →
   carton.move → outbox (store-and-forward) → ShopBot`. Offline-tolerant edge buffering is intrinsic.

## NOT FINALISED — implementation choices (experimental, decided by benchmark)
Everything the diagram shows at the component level is a **candidate**, not a mandate:

| Shown in diagram | Status | Decided by |
|---|---|---|
| IP camera model, MP, FPS | candidate | field trial on real footage |
| RK3588 vs Jetson Orin vs other edge HW | **not finalised** | the workload benchmark (`bench/`) |
| YOLOX vs RF-DETR (detector) | **not finalised** | detector precision/recall on verified GT |
| Inference runtime (PyTorch / ONNX / RKNN / TensorRT) | candidate | FPS/RAM/GPU on the target box |
| Stream capacity (cameras per box) | **not finalised** | measured FPS headroom |
| Ubuntu + Docker + FFmpeg + Python + Supervision + PyTorch | candidate stack | modularity below keeps it swappable |

**Do not treat the edge software stack in the diagram as mandatory final dependencies.**

## Why the swaps are safe — the seams that already exist
Modularity here is real code, not a promise. Each experimental choice sits behind an interface, so it
changes without touching the frozen `carton.move` contract:

- **Detector / model / inference runtime** → `Detector` protocol (`app/movement/detector.py`,
  `detect(frame, ts) -> sv.Detections`). A YOLOX, an RF-DETR, or an RKNN/TensorRT-compiled model is
  just a different object with a `.detect()` — `bench/detectors/base.py:CallableDetector` wraps *any*
  `infer(frame)` callable. Swapping the runtime is a new adapter, nothing else.
- **Tracker (ByteTrack)** → isolated in **one place**, `MovementPipeline._ensure()`
  (`app/movement/pipeline.py`). It's the only file that imports `supervision`. ByteTrack is deprecated
  in Supervision ≥0.28; replacing it is a one-file change.
- **Camera / capture** → `FrameSource` protocol (`app/movement/sources.py`): `ListFrameSource`
  (tests), `VideoFileSource` (recorded footage), and a future RTSP/ONVIF source all yield `(frame, ts)`.
- **Decision logic** → `MovementStateMachine` is **pure** (no supervision, camera, or network imports),
  which is why the nine acceptance scenarios are deterministic and hardware-independent.
- **Delivery** → the outbox `events.send()` seam is unchanged and shared with the shelf detector.

Result: **the detector, inference runtime, tracker, camera, and edge hardware can all be replaced
without changing the movement/event contract or the ShopBot reconciliation side.**

## Current development phase (the only thing in scope now)
```
recorded video → detector → Supervision → ByteTrack → LineZone → MovementStateMachine → carton.move
```
Built and tested (`app/movement/`, `tests/test_movement.py`, `tests/movement_harness.py`). The
benchmark harness (`bench/`) measures this workload on real footage. **Benchmark the actual workload
before choosing production hardware.** No detector, camera, or edge box is chosen yet.

> Note: the diagram's "ShopBot Vision Pipeline" box exists as code but is **not yet wired to run on a
> device** — `app/movement/worker.py` is an unwired seam. Phase-0 stops at the replay pipeline +
> deterministic tests by design.

## One-line rule
**The conceptual architecture is frozen; the implementation choices are experimental.** Treat the
diagram as the north star, and `bench/` numbers on real footage as the thing that turns candidates
into decisions.
