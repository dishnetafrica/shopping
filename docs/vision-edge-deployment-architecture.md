# ShopBot Vision — Edge Box Deployment Architecture (design, pre-hardware)

**Scope:** how ShopBot software installs, enrols, updates, and reports from a Linux AI edge box
(RK3588 / Jetson Orin / x86+accelerator) at fleet scale — designed *before* the first box arrives.
**No production code in this document.** It is grounded in the **actual** existing Python vision
service; every "already exists" below cites a real file. Laravel and the reconciliation engine are
**not** touched.

> Honest headline: the **offline event pipeline and LAN camera-config are already built and good.**
> The gaps for a 100-box fleet are **device identity, remote update/rollback, and box→cloud
> telemetry** — none of which exist yet — plus two blockers in the current tree (below).

## 0. What already exists vs what must be added (read this first)

| Capability | State today | Evidence / gap |
|---|---|---|
| Container packaging | ✅ compose model | `docker-compose.yml` (`env_file: .env`, `network_mode: host`, `./data:/data`) |
| One-command install (x86) | ✅ mature | `install.sh` → docker, `.env`, build, **verifies config reached container**, selftest, `visionctl` |
| Offline store-and-forward outbox | ✅ robust | `db.py` (`outbox`, `events.delivered`), `events.drain_outbox`/`retry_undelivered` (budget, backoff, quarantine), dedup `event_uid` |
| `carton.move` uses the same outbox | ✅ | `app/movement/worker.py` sink = `events.send` → same queue as shelf events |
| Camera config without SSH/Python | ✅ | `main.py` `/api/discover` (ONVIF), `/api/cameras`, `/api/zones`, `/api/zones/{id}/enroll`, web dashboard on `:8080` |
| Refuse-to-run-silent safety | ✅ | `events.config_problems()` + `auth.config_problems()` crash boot if unconfigured |
| LAN auth | ✅ (proportionate) | `auth.py` HTTP Basic on every route; `test_security.py` enforces |
| Health (queue + camera) | ⚠️ partial | `/api/health` has camera/zone health + queue depth; **no box CPU/RAM/GPU/temp/disk/version/FPS** |
| **Device identity** | ❌ **shared token** | one `VISION_TOKEN` (`X-Vision-Token`) typed per box — exactly the shared-key model to replace |
| **Remote update / rollback** | ❌ none | `visionctl update` = local `docker compose up --build`; no registry pull, no version, no rollback |
| **Box→cloud telemetry (push)** | ❌ none | `/api/health` is pull-only on the LAN; cloud can't reach a box behind customer NAT/4G |
| **Multi-arch vision image** | ❌ **blocker** | root `Dockerfile` is the **Laravel** image (`php:8.3`); there is **no Python edge Dockerfile or `requirements.txt`** in the repo |
| **Camera capture driver** | ❌ **blocker** | `main.py`/`scheduler.py` `import from .cal` but **`app/cal` does not exist** — the service can't capture as-is |
| Streaming capture for movement | ❌ | current loop is 10-min **snapshot-pull** shelf detection; `carton.move` needs a **continuous RTSP** loop |

The two ❌ **blockers** must be closed before any box runs; they are pre-existing, not introduced here.

---

## 1. Recommended installation architecture

Keep the container model that already exists; make the image multi-arch and registry-pulled.

```
AI Edge Box (RK3588 / Jetson Orin / x86+accel)
├── Ubuntu 22.04 LTS (host)  — unattended-security-upgrades on
├── Docker + compose plugin
│    └── shopbot-vision  (one container; multi-arch image, PULLED not built on-box)
│         ├── FastAPI + uvicorn (LAN dashboard/API :8080, Basic auth)
│         ├── capture (RTSP via FFmpeg) → detector → Supervision/ByteTrack/LineZone
│         │                              → MovementStateMachine → carton.move
│         └── outbox drain (store-and-forward → ShopBot Cloud over TLS)
├── Local NVMe (bind-mounted ./data:/data, chmod 700)
│    ├── vision.db (SQLite WAL: config, outbox, logs index)   ← config + event outbox
│    ├── models/   (versioned detector weights, pulled separately)
│    ├── snapshots/ + clip buffer (RING-BUFFERED, capped)      ← temp video/buffer
│    └── device identity (client cert/key, chmod 600)          ← per-device credential
└── Network
     ├── IP cameras via RTSP/ONVIF on the LAN
     └── Internet → ShopBot Cloud (TLS out only; NO inbound required)
```

**Principle:** the box needs **no inbound connectivity**. Everything cloud-bound is an outbound TLS
POST (events today; heartbeat + update-checks added). This is what makes it work behind a shop's 4G
router with no port-forwarding.

## 2. Exact software stack

**Mandatory (every box, in the image or host):**
- Host: Ubuntu 22.04 LTS, Docker + compose plugin (`install.sh` already installs these).
- Image (Python 3.11): FastAPI, uvicorn, requests, numpy, **OpenCV (headless)**, **Supervision**,
  **FFmpeg** (RTSP decode), the detector inference wrapper, pydantic. → **must be pinned in a new
  `requirements.txt` + a new Python edge `Dockerfile`** (neither exists today).

**Optional, hardware-specific (NOT in the base image — see §8):**
- **Jetson Orin:** NVIDIA JetPack on host, `nvidia-container-toolkit`, TensorRT runtime.
- **RK3588:** Mali/NPU drivers on host, RKNN toolkit/runtime.
- **x86+GPU:** CUDA/onnxruntime-gpu as applicable.

These are a **hardware base layer**; the movement pipeline and `carton.move` never import them.

## 3. First-time provisioning flow

Mapped to what exists (`install.sh`) with the missing enrolment step called out:

```
Factory box (clean Ubuntu)
  → Install ShopBot agent            ✅ install.sh (docker + image + compose + visionctl)
  → Register device with cloud       ❌ NEW: claim-code enrolment (§4)
  → Device receives unique identity   ❌ NEW: per-device client cert / device token (§4)
  → Configure cameras (ONVIF)        ✅ /api/discover + /api/cameras (web UI)
  → Test RTSP                        ✅ /api/cameras/{id}/test
  → Configure door/line + direction  ⚠️ EXTEND: zones has polygon; add line+direction (§5)
  → Start vision service             ✅ compose restart-on-boot
  → Send test carton.move / selftest ✅ /api/selftest (proves the real uplink)
  → Cloud confirms ONLINE            ❌ NEW: first heartbeat flips device ONLINE (§9)
```

Target operator experience: **plug in → claim device (scan/enter code) → confirm cameras → confirm
doors → selftest → DONE.** No Python editing, no SSH (already true for cameras/zones).

## 4. Device enrolment (replace the shared token)

**Problem today:** one `VISION_TOKEN` per box, typed by the installer, sent as `X-Vision-Token`
(`events.post_payload`). That is the shared-key anti-pattern to remove.

**Proposed identity model** (net-new; edge + a small cloud enrolment API — *design only here*):
- **Claim code**: cloud mints a short-lived, single-use code bound to `{tenant, site}` (owner
  generates it in ShopBot). Printed/QR on the box or entered in the box's setup screen.
- **First-boot enrolment**: box generates a keypair locally, POSTs a CSR + claim code to
  `cloud /vision/enroll`. Cloud verifies the code, issues a **device-scoped credential** carrying
  `{tenant_id, site_id, device_id}` — **mTLS client certificate** (preferred) or a device-scoped
  signed token if mTLS is too heavy for v1.
- **Storage**: credential + key in `./data` at `chmod 600`, never in `.env`, never in the image.
- **Every event** (incl. `carton.move`) authenticates with the device credential; cloud derives
  tenant/site/device from it — the box no longer *claims* its store id, it *proves* it.
- **Rotation**: short-lived tokens (e.g. hours) auto-refreshed using the long-lived device cert; certs
  rotated on a schedule.
- **Revocation**: cloud marks a device revoked → rejects its events **and** returns a stop/quarantine
  signal on the next heartbeat; a stolen or decommissioned box is cut off centrally.
- **TLS**: already outbound HTTPS (`requests`); enforce cert validation and, ideally, mTLS.

This maps cleanly onto the existing outbox: the credential just changes what header/cert
`post_payload` presents — the store-and-forward machinery is unchanged.

## 5. Camera & door configuration flow

**Cameras: already solved** without SSH/Python — ONVIF discovery (`/api/discover`), add camera with
IP/RTSP/user/pass/name (`POST /api/cameras`), test RTSP (`/api/cameras/{id}/test`). Credentials are
scrubbed from all outputs (`db.row_to_dict`, `redact`).

**Doors/lines: extend the existing zone model.** `zones` stores a `polygon` (shelf zones) with a
permanent `zone_uid` and optional aisle/shelf/face/skus. For movement, add per-door config —
**line = two points, direction mapping (in/out), carton/person class ids, confidence + cooldown** —
surfaced in the **same web dashboard** the operator already uses to draw zones. `MovementConfig`
(`app/movement/config.py`) is exactly this shape; the config just needs a table + a small UI + push to
the running pipeline. Keep `zone_uid` immutable (the cloud join key), as shelf zones already do.

**Direction of travel (asset-light):** eventually host this config in **ShopBot Cloud** and push it to
the box, so a customer configures doors from the cloud panel rather than the LAN box — but the LAN
dashboard stays as the offline fallback.

## 6. Offline / outbox flow (verify what exists)

**This is already built and correct** — the camera → `carton.move` → local SQLite → retry-on-return
path the owner drew is essentially the current `events` pipeline:

- `events.send()` **never touches the network** — it `db.enqueue_outbox()` and returns "accepted, not
  delivered." All emitters run off the capture thread (the P0-10 fix).
- The scheduler's **budgeted drain** (`retry_undelivered` + `drain_outbox`) sends within a hard time
  budget, **stops on first failure** (uplink down), orders by *next-due* not age (so one bad row can't
  block the queue), backs off exponentially, and **quarantines** after `MAX_ATTEMPTS` — loudly, in
  `/api/health`, never silently dropped.
- **Dedup**: deterministic `event_uid` → a delivery that landed but lost its local flag isn't
  double-counted. (`carton.move` has its own stable `uid` in `app/movement/contract.py`.)
- **Never loses evidence**: `prune_events`/`prune_outbox` refuse to delete undelivered or quarantined
  rows.

**Gaps to add:** (a) a **disk-full guard / ring buffer** on the NVMe clip+snapshot buffer (events are
bounded, raw video is not); (b) a `last_delivered_ts` so "internet returned, queue draining" is
visible; (c) confirm the continuous-movement path enqueues at the same discipline (it uses the same
`events.send`, so it inherits it).

## 7. Remote update strategy (net-new — the fleet-critical piece)

Today there is **no** remote update: `visionctl update` rebuilds locally. For 100–1,000 boxes:

- **Immutable, versioned images** in a registry, tagged `semver + arch`. Boxes **pull**, never build
  (removes the on-box build blocker and makes every box identical). Bake a `VERSION` into the image.
- **Detector model as a separate versioned artifact** pulled to `./data/models/<model-version>/`,
  referenced by config — a model swap is a small download, not a full image.
- **Update controller on the box**: on heartbeat, cloud replies with a *target* image/model/config
  version. The box: pulls → starts the new container → **health-gates** it (`/api/health` green +
  `/api/selftest` delivers) for N minutes → promotes; **on failure, rolls back** to the retained
  last-known-good image/model. Keep the previous tag on disk always.
- **Config & security patches** ride the same channel; host OS via `unattended-upgrades`.
- **Staged rollout**: canary a small % of the fleet, watch heartbeats/quarantine counts, then widen.
  **Never update the whole fleet at once.**
- **Signed images + pinned registry** (supply-chain, §11).

## 8. Hardware abstraction strategy

**Hardware-specific code must live in exactly three places, and nowhere else:**
1. **Container base layer** — JetPack/TensorRT (Jetson) vs RKNN/Mali (RK3588) vs CUDA (x86). Separate
   base images; the app layer on top is identical.
2. **Detector inference adapter** — a `Detector`/`CallableDetector` implementation wrapping the
   runtime (TensorRT engine, RKNN model, or PyTorch/ONNX). Selected at runtime by config/env.
3. **Capture/decode** — FFmpeg hardware-accel flags per platform.

**Everything else stays hardware-independent — and already is:** `MovementStateMachine` is pure (no
supervision/camera/network imports), ByteTrack is isolated in `MovementPipeline._ensure()`, and the
`carton.move` contract is stdlib-only. The same app image runs on all targets; only the base layer and
the detector adapter change. Build multi-arch with `docker buildx` (`linux/arm64` + `linux/amd64`).

## 9. Monitoring requirements (box → cloud)

**Delivery mechanism:** the box **pushes** a periodic `device.heartbeat` through the **same outbox**
(offline-tolerant, no inbound needed); `/api/health` stays for LAN diagnostics. Cloud infers
online/offline from missed heartbeats.

| Signal | State | Source |
|---|---|---|
| box online/offline | ❌ add | missed-heartbeat inference (cloud) |
| camera online/offline | ✅ exists | `cameras.health_state`, `/api/health`, `camera.online/offline` events |
| outbox queue size / quarantined | ✅ exists | `events.queue_health()` |
| FPS | ❌ add | movement pipeline; reuse `bench/metrics/system.py` |
| CPU / RAM | ❌ add | `psutil` (new dep) |
| GPU/NPU utilisation | ❌ add | tegrastats/nvidia-smi (Jetson) · RKNN NPU load (RK3588) — hardware-specific |
| temperature | ❌ add | thermal zones |
| disk usage | ❌ add | `shutil.disk_usage` on `./data` |
| software version | ❌ add | baked `VERSION` → health + heartbeat |
| model version | ❌ add | from `./data/models/<ver>` |
| last successful event | ⚠️ partial | add `last_delivered_ts` (have `oldest_pending_ts`) |

## 10. Factory provisioning: first 10 vs 100+

- **First 10 — provision ourselves after receiving.** Flash Ubuntu, run `install.sh`, claim. Maximum
  control while the image, drivers, and claim flow are still stabilising; a bug is a re-run, not a
  batch recall. This is where the golden image gets *built and validated*.
- **100+ — give the OEM a validated golden image / first-boot script.** Preloaded Ubuntu + Docker +
  the pulled image + a first-boot claim-code enrolment, so boxes arrive **plug-and-claim**. Only hand
  the OEM an image **we** produced and proved on the first 10 — never let the OEM assemble the stack.

Rule of thumb: **build the golden image on the first 10, ship that same image via the OEM for the
rest.**

## 11. Security risks

- **Shared `VISION_TOKEN`** (current): one leaked token ≈ fleet impersonation → **§4 per-device
  identity + revocation** is the fix.
- **Basic auth over plain HTTP on the LAN**, box has no TLS: opportunistic-attacker mitigation only
  (`auth.py` says so). The box holds **recorder credentials** (SQLite) and **shop-floor imagery**
  (NVMe) → keep `./data` `chmod 700`, `.env` `chmod 600` (installer does), consider disk encryption
  for the camera-credential + identity material.
- **Physical theft of a box** → device credential must be **revocable** and ideally short-lived; no
  long-lived cloud secret at rest beyond the device cert.
- **OTA supply chain** → **sign images, pin the registry, verify signatures before promote**; staged
  rollout limits blast radius.
- **`network_mode: host`** (needed for ONVIF multicast) gives the container broad LAN reach — document
  and constrain.
- **Privacy** — real people on camera; retention limits, access control, and the standing rule:
  **physical observation → ledger reconciliation → exception → human review, never an automated
  accusation.**

## 12. Estimated install time & what can be automated

- **Today (x86, `install.sh`, build-on-box):** ~15–30 min/box (OS assumed present) — dominated by the
  Docker build, ONVIF discovery, drawing zones, and the selftest.
- **Target (prebuilt multi-arch image *pulled*, claim-code, cloud-pushed config):** **~5–10 min/box** —
  plug in → claim → cameras auto-discovered → confirm doors → selftest → ONLINE.

**Automatable:** image pull (vs build), ONVIF discovery (exists), claim enrolment, config push,
selftest (exists), heartbeat/telemetry, staged updates. **Stays manual (human judgment):** physically
mounting/cabling cameras, and drawing/confirming the door line and direction.

---

## The critical path to "deploy 100 times"
1. **Unblock:** add a Python edge `Dockerfile` + pinned `requirements.txt` (multi-arch), and build the
   missing `app/cal` capture driver. *(Nothing runs on a box until these exist.)*
2. **Identity:** per-device enrolment + revocation (kills the shared token).
3. **Push telemetry:** `device.heartbeat` through the existing outbox → cloud online/offline + health.
4. **OTA + rollback:** registry-pull images, versioned models, health-gated promote, staged rollout.
5. **Movement capture mode:** continuous RTSP loop feeding the pipeline (distinct from snapshot-pull).

Items 3–5 build directly on machinery that already exists (the outbox, `/api/health`, the movement
pipeline). Items 1–2 are the genuine new foundations. None of this requires changing Laravel or the
reconciliation engine.
