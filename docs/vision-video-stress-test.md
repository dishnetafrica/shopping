# ShopBot Vision — Video Stress-Test Track

**Two separate reports (the owner's A/B split), because failures live in different places:**
- **A. Vision performance** — did we *detect* the objects? (detector precision/recall/mAP on real
  pixels.) → needs a real detector + real footage = **Track B / physical box**. Not this harness.
- **B. Movement performance** — *given* detections, did the pipeline turn them into correct movements?
  → **`bench/stress/` exercises this now**, in software, isolated from detection.

Knowing which is failing matters: *detector recall 92% + movement 99%* is a completely different problem
from *detector 99% + movement 85%*. This track nails down **B** so that, once real footage arrives, any
remaining error is attributable to **A**.

## What was tested (software replay — Report B)

Every scenario × every perturbation through the **real** `sv.ByteTrack + sv.LineZone +
MovementStateMachine`, scoring the operational invariant:

> **N real stock-object crossings → N movement events, each with a distinct uid; 0 crossings → 0 events.**

**Scenarios:** single crossing · mixed simultaneous (carton+bag+sack) · multiple workers · worker
carrying nothing · stationary object near the line · long-running (20 sequential crossings).
**Perturbations (deterministic):** baseline · drop 1-in-5 / 1-in-3 · duplicate 1-in-5 · timestamp jitter
· reorder (out-of-order) · replay speed 2× / 4× · corrupt 1-in-7 (frame decodes to nothing).

**Result: 54/54 scenario×perturbation combinations hold the invariant** (`bench/stress/run.py`), and the
non-negotiable subset is locked at **32/0** (`bench/stress/selftest.py`), including:
- mixed simultaneous → 3 events, **3 distinct uids** (identity independent of person attribution — the
  fix we just made, verified under stress);
- worker-carrying-nothing and stationary-near-line → **0 events under every perturbation**;
- single crossing survives drop/dup/jitter/reorder/4×/corrupt → still exactly 1;
- long-running 20 → 20 distinct events, no drift.

The movement logic is robust to degraded frame streams. That is a real, if narrow, result: **it holds
for clean detections** — it does not yet say the detector produces clean detections on real pixels.

## Metric coverage: measured now vs physical-box only

| Metric | Software replay (here) | Physical edge box (fill on hardware) |
|---|---|---|
| processed FPS | ✅ measured (host, scripted) | needs real decode+detector |
| per-frame latency (pipeline) | ✅ p50/p95/max | — |
| duplicate / missed / false events | ✅ (invariant scoring) | re-confirm on real footage |
| exactly-one-event rate | ✅ 54/54 | re-confirm |
| input FPS · decode latency | ❌ | ✅ real H.264/H.265 decode |
| detector latency · total latency | ❌ | ✅ real model on the box |
| CPU / RAM | ❌ (host not representative) | ✅ docker stats |
| GPU / NPU utilisation | ❌ (no accelerator) | ✅ tegrastats / RKNN |
| decoder failures / reconnects | ❌ (no RTSP) | ✅ RtspFrameSource reconnect count |
| resolution 720p/1080p/4MP/5MP · codec · motion-blur | ❌ (no pixels) | ✅ real footage |
| long-running stability (hours) | partial (20 crossings) | ✅ hours of replay |

**The ❌ rows are physical-box-only and were NOT fabricated.** `bench/stress/run.py` prints a template
for them; they are captured on the sample box, and separated from these software numbers.

## Honest boundary

- These are **scripted detections** — deliberately clean — so the harness isolates **Report B
  (movement)**. It proves the movement pipeline is stream-robust; it says **nothing** about detecting
  real cartons/bags/sacks in a real warehouse (**Report A**), which remains the big unknown.
- Real decoder behaviour (corrupt H.265 bitstreams, ByteTrack on genuinely jumpy real boxes, RTSP
  reconnects) can differ from the software model and must be measured on the box.
- Primary operational metric unchanged and re-verified under stress: **1 crossing → 1 event, 0 → 0**,
  with **object-track identity independent of person attribution**.

## Scope

Additive under `bench/stress/` + this doc. No reconciliation, ERP, shelf-vision, or detector-architecture
change. The next real step is unchanged: real footage on the sample box → Report A (detection) alongside
Report B (movement), so any error is correctly attributed.
