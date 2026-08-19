# ShopBot Vision — single-box bring-up (Phase 1)

**Goal:** one clean Linux edge box + one IP camera → one correct `carton.move` in the outbox → ShopBot
staging. No NVR, no fleet system, no OTA. Just: **can the box turn one real RTSP stream into a
`carton.move`?**

This folder is the **reproducible edge image** (the missing Blocker 1) and it drives the two blockers
this phase closed:
- **Dockerfile + `requirements.txt`** — pinned, multi-arch, hardware-neutral (no NVIDIA/RKNN).
- **`app/cal`** (reconstructed capture layer) + **`app/movement/sources.py:RtspFrameSource`** +
  **`app/movement/run_door.py`** — real RTSP capture feeding the movement pipeline.

---

## What runs

One image, two commands:
- **`vision-api`** — the dashboard/API (`app.main:app`) on `:8080`: add the camera, test RTSP,
  `/api/selftest`, `/api/health`. (Camera config without SSH/Python — already existed; `app/cal` now
  makes it importable.)
- **`vision-door`** — `app.movement.run_door`: RTSP → detector → ByteTrack → LineZone →
  MovementStateMachine → `carton.move` → the existing outbox.

## Exact steps (on the box)

```bash
# 0. Host: Ubuntu 22.04 LTS (arm64 for RK3588/Jetson, amd64 for x86), Docker + compose plugin.
#    (The existing install.sh already installs Docker; or: apt-get install docker.io docker-compose-v2)

# 1. Config (same .env discipline as the gateway) — at the repo root, NOT in edge/:
cat > ../.env <<EOF
STORE_ID=wh-juba-01
EVENT_WEBHOOK_URL=https://<staging>/api/vision/events
VISION_TOKEN=<staging token>
ADMIN_USER=admin
ADMIN_PASSWORD=<generated>
ALLOW_NO_WEBHOOK=0
EOF

# 2. Build + start (build context is the repo root; see edge/Dockerfile):
cd edge && docker compose up -d --build

# 3. Prove the OUTBOX path end-to-end WITHOUT a camera or model (vision-door runs this by default):
docker compose logs vision-door         # → "emitted one synthetic carton.move to the outbox"
#    …and confirm one carton.move row lands in ShopBot staging.

# 4. Add the real camera (dashboard on http://<box-ip>:8080, Basic auth from .env):
#    - ONVIF Discover, or add by RTSP URL directly
#    - "Test" proves the stream opens

# 5. Run the REAL door (replace vision-door's command — see docker-compose.yml comment):
docker run --rm --network host --env-file ../.env -v ../data:/data shopbot-vision:phase1 \
  python -m app.movement.run_door \
    --rtsp "rtsp://user:pass@<cam>/Streaming/Channels/101" \
    --store wh-juba-01 --zone "D->EXIT" --line 0,540,1920,540 \
    --detector <module:function> --max-fps 8
```

## The detector — the one honest gap for "correct"

There is **no carton detector in the repo yet** (that is Experiment 1's benchmark output). `run_door`
takes `--detector module:function` (same seam as `bench/`). For **first light on real pixels** before a
model is trained, wire the **research zero-shot bootstrap** (Grounding DINO) as the detector — it
detects "carton"/"person" from a text prompt. It is **research/bootstrap only, never production**
(`bench/MODEL_LICENSES.md`). Until a real carton detector exists, the box can prove the *plumbing*
(RTSP → pipeline → `carton.move` → outbox) but not detection *accuracy*.

---

## On-box measurement template (fill these from the real box — NOT measurable in the dev sandbox)

The dev environment has no cv2/ffmpeg/camera/GPU, so the following **must be captured on the physical
box** and were deliberately **not** guessed here:

| Item | How to measure | Value |
|---|---|---|
| OS | `lsb_release -d` | Ubuntu 22.04 LTS expected |
| Docker | `docker --version` / `docker compose version` | |
| Image size | `docker images shopbot-vision:phase1 --format '{{.Size}}'` | |
| RAM (idle/running) | `docker stats shopbot-vision-door` (MEM USAGE) | |
| CPU | `docker stats` (CPU %) at `--max-fps 8` | |
| RTSP performance | door log FPS; `ffprobe rtsp://…` for stream fps/codec | |
| Camera compatibility | which brands/URLs opened via `--rtsp` (Hikvision/Dahua/…) | |
| GPU/NPU | `tegrastats` (Jetson) / RKNN NPU load (RK3588) — only when a real detector runs | |
| carton.move correctness | crossings observed vs correct events (bench methodology) | |

## Remaining blockers for a **second** box (explicitly out of Phase-1 scope)
- **Carton detector** — a trained/bootstrapped model so `carton.move` is *correct*, not just emitted.
- **Per-device identity** — replace the shared `VISION_TOKEN` (Experiment 3 / fleet).
- **OTA + telemetry + provisioning** — none of it built yet, by design.
- **Streaming vs snapshot** — `vision-api`'s scheduler is the 10-min shelf loop; the movement door is a
  separate continuous process (this design keeps them separate — shelf vision untouched).

## Scope honoured
No Laravel, reconciliation, or shelf-**detection** logic changed. `app/cal` is a faithful
reconstruction of the missing capture layer (its documented contract is exercised by the existing
shelf tests). The movement pipeline and `carton.move` contract remain hardware-independent; the
detector/runtime and any NVIDIA/RKNN deps are injected, never baked into the base image.
