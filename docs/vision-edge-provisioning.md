# ShopBot Vision — First-Time Edge-Box Provisioning (design, no code)

**Scope:** the initial install/bring-up only — box arrives → connects to internet → one bootstrap
command → ShopBot Vision downloads, installs, starts, reports online → we configure the camera.
**NOT in scope (explicit design boundaries):** OTA/auto-update, fleet management, device certificates/
enrolment endpoint, billing. **Architecture first — no code until approved.**

## Grounded starting point (what exists vs what must change)

| Requirement | Today (inspected) | Change needed |
|---|---|---|
| Install without customer pip/OpenCV/etc. | `edge/Dockerfile` bakes all deps (fastapi/opencv-headless/supervision) | ✅ keep — customer never installs Python deps |
| Get software over the internet | `install.sh` **rsyncs local source + `docker compose up -d --build`** | ❌ **must PULL a versioned registry image**, not build on an ARM box |
| Versioned image | compose `image: shopbot-vision:phase1` **with a `build:` stanza** | ❌ pull `registry/shopbot-vision:<semver>`, drop `build:` |
| Model separate from image | Dockerfile copies no weights; `/data/models` planned | ✅ keep — model downloaded separately |
| Persistent config/data | `../data:/data` bind mount; `VISION_DB=/data/vision.db` | ✅ already survives container replacement |
| Offline outbox | `events.send`→`enqueue_outbox` (no network), budgeted drain w/ backoff | ✅ already offline-tolerant |
| Local health page | `/api/health` (JSON) + dashboard `:8080`, Basic auth | ✅ keep |
| Repeatable/safe | `install.sh` idempotent, config-verify, restart-on-boot, `visionctl` | ✅ keep, adapt to pull |
| No shared secret in installer | `.env` carries `VISION_TOKEN` written by installer | ⚠️ **public bootstrap must be secret-free** (see I) |

**Bottom line:** ~70% is already built; the real new pieces are a **registry-pulled image**, a **bootstrap
script**, a **versioned model store**, and keeping the **secret out of the public installer**.

## A. Installation architecture

```
OUR SERVER SIDE                                   THE AI BOX (customer site)
─────────────────                                 ──────────────────────────
install.shopbot.africa   ──curl──▶  bootstrap.sh runs (as root):
  (static bootstrap script)           1. detect arch/OS, check Docker/RAM/disk/net
                                       2. install Docker + compose plugin if missing
container registry       ──pull──▶    3. write /opt/shopbot-vision/{compose.yml,.env}
  shopbot-vision:<semver>             4. docker compose pull  (multi-arch: right arch auto)
  (multi-arch arm64+amd64)            5. download model → /data/models/<ver>/  (separate)
model store (object      ──GET───▶    6. docker compose up -d   (restart-on-boot)
  storage) v0_1.<target>              7. health-gate: /api/health + /api/selftest
  + manifest + checksum               8. print Device ID + http://<lan-ip>
                                      ┌─────────────────────────────────────┐
                                      │ /opt/shopbot-vision/  (install dir)  │
                                      │   compose.yml · .env(600)            │
                                      │ /data/  (persistent, host bind:700)  │
                                      │   vision.db · models/<ver>/ · outbox │
                                      │   snapshots/                         │
                                      └─────────────────────────────────────┘
```

The box **only pulls and runs** — it never builds and the customer never touches Python.

## B. Server vs box

| Runs on OUR server | Runs on the AI box |
|---|---|
| Static bootstrap script (`install.shopbot.africa`) | Docker + compose |
| **Multi-arch image build** (CI `docker buildx --platform linux/arm64,linux/amd64`, push) | Pulled `shopbot-vision:<semver>` container |
| Container registry (image, pinned by digest) | Vision engine + detector + Supervision + movement + outbox + `/api/health` |
| **Versioned model store** (object storage) + checksums | Downloaded model in `/data/models/<ver>/` |
| (future) enrolment/claim endpoint | Local dashboard `:8080`, `/data` persistent config |

**Building never happens on the box.** That's what makes ARM64 support reliable — the box consumes a
prebuilt artifact matched to its architecture.

## C. Bootstrap command

Eventual:
```bash
curl -fsSL https://install.shopbot.africa | sudo bash
```
First controlled experiment (safer): pin a version + verify, and pass config by env, **never a baked
token**:
```bash
curl -fsSL https://install.shopbot.africa/v1/install.sh -o sb-install.sh
# (optionally) sha256sum -c sb-install.sh.sha256
sudo STORE_ID=wh-juba-01 CLAIM_CODE=ABC123 bash sb-install.sh
```
The script is **idempotent** (re-run safe — preserves existing `.env`/`/data`, same as today's
`install.sh`).

## D. Docker image download & versioning

- CI builds a **multi-arch manifest** (`buildx`, arm64 + amd64), tags **immutable semver**
  (`registry/shopbot-vision:1.0.0`, plus `:1.0`), pushes to the registry.
- The box's compose pins a **specific version** (or better, a **digest** `@sha256:…`) — never `latest` —
  so an install is reproducible and a re-run pulls exactly the same bytes.
- `docker compose pull` auto-selects the box's architecture from the manifest list.
- Rollback (a future OTA concern) = pinning the previous tag; the previous image stays in the local
  store. Not built now.

## E. Model download (separate from the image)

- The image contains **no weights** (keeps it small, and a model swap ≠ image rebuild).
- Bootstrap downloads the versioned model artifact for the box's target
  (`stock_object_v0_1.rknn` / `.engine` / `.onnx`) + `manifest.json` into `/data/models/<model-version>/`,
  **verifying a checksum** (later a signature).
- Config records the **active `model_version`**; the container loads it from the mounted `/data`.
- A different/updated model is just another download into a new `/data/models/<ver>/` — the app image is
  untouched.

## F. Configuration survival across container updates

Everything mutable lives **outside** the container, on the host:
- `/opt/shopbot-vision/.env` — store id, webhook, credentials (chmod 600).
- `/data` (bind-mounted) — SQLite config (cameras, zones/doors), the **outbox**, models, snapshots.

`docker compose pull && docker compose up -d` replaces the container in place; `.env` and `/data` are
**never touched**. This is already how `edge/` is built (`../data:/data`, `env_file: ../.env`, no
`environment:` override) — the provisioning design just pulls the image instead of building it.

## G. Internet disconnected after install

Already handled by the existing outbox (`app/events.py`):
- `events.send()` **never hits the network** — it `enqueue_outbox()`s locally; the vision engine keeps
  detecting and producing `stock.move` events into SQLite on `/data`.
- The budgeted drain retries with exponential backoff, **stops on first failure**, and **quarantines
  loudly** (surfaced in `/api/health`) rather than dropping — nothing is silently lost.
- The LAN dashboard + `/api/health` keep working. When the link returns, the drain resumes
  automatically.
- **Caveat:** the *initial bootstrap* needs internet (to pull the image + model). After that, operation
  is offline-tolerant. A future "offline install" bundle (image + model on USB) is out of scope now.

## H. Exact customer installation sequence

```
1. Box arrives → connect power + Ethernet/Wi-Fi → confirm internet
2. curl -fsSL https://install.shopbot.africa | sudo bash
       ✓ ARM64 detected            ✓ Docker ready
       ✓ Downloading Vision Engine ✓ Downloading model
       ✓ Starting services         ✓ /api/health green + selftest delivered
       ✓ Edge box online   Device ID: SB-UG-00123   Open: http://192.168.1.50
3. Open the dashboard → add IP camera (ONVIF discover or RTSP URL) → Test
4. Configure the door line + direction → Selftest → box reports ONLINE to cloud
```

## I. Security — no permanent shared token in the installer

**The public bootstrap script must be secret-free** (anyone can `curl` it). It installs software; it does
**not** carry the customer's webhook token.
- **Recommended (design boundary, not built now):** the box **claims/enrols** — a short-lived, single-use
  **CLAIM_CODE** is exchanged with ShopBot Cloud for the box's **own device-scoped credential**, stored
  in `/data` (600). The permanent secret is never in the script and never shared across boxes.
- **First manual experiment:** the operator enters `STORE_ID`/token **on the box** (root-only `.env` or
  the local dashboard) *after* install — on the device, never embedded in the public URL.
- Also: `.env` 600, `/data` 700 (already), Basic-auth dashboard (already), TLS-out with cert validation,
  **pin the image by digest**, **verify the model checksum**, secrets scrubbed from logs (`redact`
  already). **Device ID is non-secret.** Per-device credential rotation/revocation is the future
  enrolment step — explicitly deferred.

## J. Minimum hardware requirements

- **Arch/OS:** ARM64 (RK3588 / Jetson Orin Nano/NX) or x86_64; **Ubuntu 22.04 LTS**.
- **RAM:** ≥ 8 GB. **Storage:** NVMe, ≥ 32 GB free (image + model + outbox + snapshot buffer).
- **Network:** Gigabit Ethernet (or Wi-Fi); internet reachable for bootstrap; outbound HTTPS.
- **Power:** 12 V; **HDMI** for local setup; **watchdog** desirable.
- **Accelerator:** NPU/GPU optional — the detector runtime (RKNN/TensorRT) is a hardware-specific
  overlay, never in the base image (keeps one image portable).
- **Software:** Docker + compose plugin (bootstrap installs if missing).

## What must be built when you approve code

1. **Pull-based edge compose** — `image: registry/shopbot-vision:<version>`, drop the `build:` stanza.
2. **Bootstrap script** — arch/OS/Docker/RAM/disk/net checks → install Docker → write compose+`.env` →
   `compose pull` → model download+verify → `up -d` → health-gate → print Device ID + LAN URL. Idempotent.
3. **CI multi-arch build+push** (`buildx`) to a registry; version tags/digests.
4. **Versioned model store** + checksums + a small download step.
5. **Device ID generator** (non-secret, e.g. `SB-<cc>-<n>` or hardware-derived).
6. (Deferred) enrolment/claim endpoint for per-device credentials.

**Honest note:** none of this is testable in the ShopBot sandbox (no ARM box, no registry). When
approved, only the script/compose can be lint/validate-checked here; the real bring-up is measured on the
sample box — same boundary as the rest of the edge track.
