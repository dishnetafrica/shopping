# ShopBot Vision — Device Identity & Licensing (design, no code)

**A minimal license gate before any software reaches the Chinese hardware supplier — not a fleet
platform.** Goal: make copying the runtime **commercially useless**, not impossible. Design only;
**stop for approval before code.** Reconciliation, movement engine, shelf detector, and ERP layer are
untouched.

## Grounded finding: ~60% already exists — this is an EXTENSION

`app/Models/VisionBox.php` + `vision_boxes` + `VisionClaimCommand` + `VisionEventController` already give
us most of the device-credential spine:

| Requirement | Already built | To add |
|---|---|---|
| Per-device id | `box_uid` (machine-id) | **layered fingerprint** (not single-source) |
| Per-device credential (not a global token) | `webhook_token` (Str::random(64)) + `secret_sha256` | keep — it becomes the delivery credential |
| No permanent secret in installer | single-use, time-limited `claim_code` + `claim_expires_at` | device-facing `/activate` (claim is manual `artisan` today) |
| Registry (tenant/site/status/versions) | `tenant_id`, `store`, `status` (pending→claimed→**revoked**), `fw_version`, `hw_model`, `last_seen_at`, `meta` | license columns (below) |
| Revocation | `status=revoked` → `VisionEventController` rejects | expiry-bounded offline revocation |
| Per-box auth at ingest | `VisionEventController` prefers per-box `webhook_token`, global token only legacy | optional license-validity check |
| Runtime startup gate | `main.py` `check_security_config`/`check_delivery_config` **refuse to start** on fatal | add `check_license()` at the same seam |
| Offline resilience pattern | the outbox (store-and-forward, backoff, grace) | reuse for heartbeat + signed-license grace |

**Missing pieces are small:** a layered fingerprint, a signed license (for OFFLINE grace), device-facing
activate/heartbeat endpoints, license columns on `vision_boxes`, an edge startup license gate, and a
clearly-scoped development license.

## A. Device identity architecture

- Keep `box_uid` as the stable handle, but **bind the license to a layered fingerprint**, computed by
  the RUNTIME at boot (never an editable `.env` value): a quorum hash over several stable signals —
  `/etc/machine-id`, DMI `product_uuid` (where present), CPU serial (`/proc/cpuinfo`), root-disk serial,
  board id; **MAC only as a weak secondary, never alone**. **Quorum match (e.g. ≥N of M)** so a single
  disk swap doesn't brick a box but a copy to a different machine fails.
- The box generates a secret at first boot; the server stores only `secret_sha256` (exists). The
  server-issued `device_id`/`webhook_token` is the handle+credential.
- **Hardware-backed attestation (TPM / secure element) is the production-hardening step — deferred.**

## B. License API architecture

Extend `vision_boxes` (migration) with: `license_type` (development|sample|trial|production),
`issued_at`, `expires_at`, `revoked_at`, `runtime_version_authorized`, `model_version_authorized`,
`fingerprint_hash`, `grace_seconds`. (`status`, `webhook_token`, `claim_code`, `tenant_id` already exist.)

**Signed license** (the offline enabler): the cloud signs a compact JSON license with ShopBot's
**private key** (Ed25519); the box bundles only the **public key** and verifies offline. Payload:
`{device_id, box_uid, fingerprint_hash, license_type, runtime_authorized, model_authorized, issued_at,
expires_at, grace_seconds, nonce}`. Short `expires_at` (7–30d), renewed by heartbeat.

Device-facing endpoints (NEW `VisionActivationController`, separate from the ingest controller):
- `POST /api/vision/activate` {claim_code, box_uid, fingerprint, hw_model} → verify claim (pending,
  unexpired) → bind `fingerprint_hash`, mint `webhook_token` (exists) → return {device_id,
  webhook_token, signed_license}.
- `POST /api/vision/heartbeat` (auth: webhook_token) {fingerprint, runtime_version, model_version} →
  verify claimed + not revoked + fingerprint quorum-matches → return {status, renewed signed_license,
  authorized versions}; update `last_seen_at`.
- Admin (extend `vision:claim`; add `vision:provision` / `vision:revoke`): create pending box + claim
  code, set `license_type`, revoke.

## C. First-boot activation flow

```
boot → runtime computes layered fingerprint → read single-use CLAIM_CODE (operator/env)
     → POST /api/vision/activate → receive webhook_token + signed_license
     → store both in /data (chmod 600) → verify signature + fingerprint quorum → ACTIVATE
```
The public installer carries **no permanent secret** — only the single-use, expiring claim code, exchanged
for the box's own credential. (This replaces the shared `VISION_TOKEN` the current `bootstrap.sh` writes.)

## D. Offline / grace-period behaviour

- License verified **offline** against the bundled public key. `now < expires_at` → normal.
- `expires_at ≤ now < expires_at + grace` → **GRACE**: keep operating, warn, surface "grace, N days left"
  in `/api/health`. Warehouse ops must not stop on a blip — mirrors the outbox ethos.
- `now ≥ expires_at + grace` → **stop commercial inference**, but keep outbox/health/dashboard alive so
  the box is recoverable on reconnect. Heartbeat (when online) renews `expires_at`.
- Grace per `license_type` (production ~14–30d; development ~90d so a supplier box never dies mid-dev).
- **Clock-rollback guard:** persist the highest server time seen; a system clock earlier than that is
  treated as untrusted (see threat model).

## E. Revocation flow

- Admin sets `status=revoked` / `revoked_at`. **Online** box: next heartbeat returns revoked → stop
  inference + delete cached credential/license. **Offline** box: bounded by `expires_at + grace` — a
  revoked box stops when its short license can no longer renew. **Short expiry IS the offline revocation
  lever.** Ingest already rejects non-claimed boxes, so a revoked box's events are refused too.

## F. Development / supplier license flow

- `license_type=development`: generous expiry (~90d, renewable), bound to an **isolated DEV tenant**,
  capability-limited (dev-only; optional throughput cap / watermark). Supplier receives **hardware + one
  single-use dev claim code + the runtime image** — activates → dev license → runs during development.
- The box **cannot self-upgrade to production** — `license_type` is server-controlled. The supplier never
  holds production credentials or another tenant's data.

## G. Production / customer license flow

- `license_type=production`: a per-sale claim code → activate → bound to the customer's tenant/site →
  short expiry + heartbeat renewal → full capability. Eventually gated on **subscription-active** ($49/mo)
  — **billing deferred**, not in this build.

## H. Threat model — Chinese supplier with root

**Honest baseline:** root can read the image, extract the runtime, read the bundled **public** key and any
cached license, inspect the fingerprint code, and **patch the runtime to skip the license check and run
locally.** Licensing cannot stop that. So we don't try to.

**What licensing DOES stop (the commercial goal):**
- casual copy to another box → different fingerprint → **activation fails, ingest rejects**;
- one license, many boxes → fingerprint/heartbeat anomalies → **revoke**;
- an unlicensed/cloned box reaching the **production cloud** → needs a per-box, revocable credential it
  can't mint.

**The real moat stays server-side and off the box:** cloud API, **reconciliation engine**, ERP
integrations, customer data, review workflow, **model-improvement pipeline + training dataset**, analytics,
master keys. The edge gets the **minimum**: a device-scoped revocable credential + one model + the ability
to emit events. A cloned, patched runtime still **can't reconcile, can't reach other tenants, can't get
model updates, and can be cut off**.

**Mitigations:** only the public key ships on the box (private key in cloud secret store/HSM); credentials
are device-scoped, never master; short license expiry; the supplier is isolated on a **dev tenant**; the
image contains **no Git repo, no dataset, no training pipeline, no master keys, no other tenants' creds**
(already true — `edge/Dockerfile` copies only the runtime; `training/`, `bench/`, and Git are never in the
image); anomaly detection (many fingerprints per credential, impossible travel, version mismatch); **TPM
attestation** as production hardening. Obfuscation is weak — we do not rely on it.

**Supplier isolation (req 9) → enforced:** receives hardware + dev claim code + runtime image only; the
dev tenant scoping + single-use dev claim + secret-free/dataset-free image are the enforcement.

## I. Exact files / components to change (reuse-max; NOT built yet)

**Cloud (extend, don't redesign):**
- Migration: license columns on `vision_boxes`.
- NEW `app/Http/Controllers/Api/VisionActivationController.php` (activate, heartbeat) + `routes/api.php` —
  **separate from `VisionEventController`** (reconciliation ingest untouched).
- NEW `App\Services\Vision\LicenseSigner` (Ed25519 sign) + private-key management in cloud secrets.
- Extend `VisionClaimCommand`; add `vision:provision` (create pending box + dev claim code + license_type)
  and `vision:revoke`.
- `VisionEventController`: OPTIONAL additive check that the box's license is valid (not expired/revoked),
  alongside the existing `status=claimed` — no reconciliation-logic change.

**Edge (reuse the startup-gate + outbox patterns):**
- NEW `app/licensing/`: `fingerprint.py` (layered quorum), `license.py` (offline verify vs bundled public
  key + grace), `activation.py` (claim→activate→store), `heartbeat.py` (resilient renew, outbox-style
  backoff).
- `app/main.py` startup: add `check_license()` beside `check_security_config`/`check_delivery_config`
  (same refuse-to-start seam) → gate commercial inference; dev-license allowance.
- `edge/Dockerfile`: bundle ShopBot license **public** key.
- `public/vision/bootstrap.sh`: replace the shared-token write with **claim-code activation**, storing the per-box
  credential + signed license in `/data` (600). Delivery then uses the per-box `webhook_token` (already the
  server's preferred `X-Vision-Token`) — likely **no `events.py` change**.

**Do NOT change:** `ReconciliationEngine`, `MovementStateMachine`, `carton.move` contract, shelf detector,
ERP layer.

## Implementation notes (as built — edge core done, cloud extended)

**Approved changes folded in:** (#5) the **device-generated Ed25519 keypair is the primary identity**
(`app/licensing/identity.py`, private key in `/data/device_key` 0600); (#4) the **hardware fingerprint
is an additional binding signal**, quorum-matched (`app/licensing/fingerprint.py`), never sole identity.
A license binds BOTH → a fresh-keypair clone fails the keypair check, a storage-copy clone fails the
fingerprint check.

**Wording correction (H):** *not* "licensing makes copies commercially useless" as an absolute. A root
attacker can patch a local runtime. The correct statement: **the edge box is only one component; the
production cloud, credentials, reconciliation, ERP integrations, review system, model lifecycle and data
remain ShopBot-controlled — so extracting or patching the edge runtime does not obtain the ShopBot
business.**

### Conflict with the existing `vision_boxes` mechanism (the reuse decision)
Inspecting the code found that **`VisionBoxController` (`/api/vision/box/hello` + `/poll`) already IS the
activation + heartbeat flow**: the box invents a secret at first boot, `hello` registers it and returns a
claim code, `poll` returns config when claimed **and doubles as the heartbeat** ("future hook for signed
OTA"); `vision:claim` binds tenant + mints the per-box `webhook_token`; revocation already 403s at ingest.
**So a separate `/activate` endpoint would duplicate it.** Resolution — **extend, don't parallel:**
- `hello` now also accepts the box's `device_pubkey` + `fingerprint` (optional → legacy boxes unaffected),
  stored write-once alongside the existing secret.
- `claimedResponse` (returned by `hello`/`poll`) now also returns the **signed license**, renewing expiry
  on each poll — the existing poll IS the license heartbeat. No new device-facing endpoint, no new routes.
- `vision:claim` gains `--type/--model/--runtime/--grace` (default **development** — a supplier box is
  naturally dev-scoped). `vision:revoke <box_uid>` sets `status=revoked`.
- The box's existing per-box `webhook_token` stays the delivery credential (already the server's preferred
  `X-Vision-Token`), so **`events.py` is unchanged** and the global token is only a legacy fallback.

### Verified in the sandbox (real, not claimed)
- edge licensing tests **9/9** (real PyNaCl): device keypair persistence + 0600, device-signature proof,
  valid/grace/expired, tampered payload → INVALID_SIG, forged signer → INVALID_SIG, **clone via fresh
  keypair → WRONG_DEVICE**, **clone via copied storage on different hardware → WRONG_DEVICE**, fingerprint
  quorum (disk-swap survives, HW-copy rejected).
- **Cross-language crypto interop proven:** PHP `LicenseSigner` (libsodium) signs → edge PyNaCl verifies;
  canonical JSON **byte-matches** including nested objects/lists.
- All cloud PHP `php -l` clean; `app/main.py` compiles with the guarded `check_license()` (nacl lazy).

### What still needs the box + cloud (reported, not claimed)
Activation/heartbeat/revocation against the real Laravel DB; the physical clone/offline/revoke tests; the
Ed25519 keypair generation in `edge/Dockerfile`-bundled runtime; wiring `bootstrap.sh` to do
hello-with-keypair. None are sandbox-runnable (no cloud DB / no box).

## Edge wiring (finished — the box half of hello/poll)

`app/licensing/enroll.py` does first-boot enrollment through the EXISTING hello/poll: generates the
device keypair (→ `/data/device_key`, 0600), invents a box secret (→ `/data/box_secret`, 0600), computes
the fingerprint, POSTs `hello` with `device_pubkey`+`fingerprint`, prints the claim code, polls until
claimed, caches the signed license (→ `/data/license.json`) + per-box token (→ `/data/credential`, 0600),
and verifies the license offline. `public/vision/bootstrap.sh` runs it when `SHOPBOT_CLOUD_URL` is set.
`edge/Dockerfile` bakes only the **public** verification key (`ARG SHOPBOT_LICENSE_PUBKEY`); the CI
build passes it as a repo **variable** (not a secret). No signing key, no production credential in the image.

## Physical acceptance test (run on the sample box + staging)

| # | Test | Expected |
|---|---|---|
| 1 | claim → license → start → camera → event → cloud | ✅ licensed box operates, event lands in staging |
| 2 | internet disconnected | ✅ cached license valid → vision continues, events queue in the outbox |
| 3 | internet restored | ✅ outbox drains to cloud |
| 4 | copy the whole install to another machine | ❌ device identity mismatch (keypair or fingerprint) → refused |
| 5 | revoke in cloud (`vision:revoke <box_uid>`) | ❌ box stops licensed inference at next heartbeat (online) / at expiry (offline); outbox/evidence remain |
| 6 | development license expires | ❌ past grace → no licensed inference (dev TTL auto-expires; `--type production` for prod) |

## Supplier receives / does NOT receive

- **Receives:** AI edge box · Ubuntu/Linux · temporary internet + remote access · **one development claim
  code** · camera credentials for testing. Effectively **hardware provider + test-site operator.**
- **Does NOT receive:** Git repository · source · training datasets · training pipeline · production API
  keys · **production license signing key** · other customer data · production credentials. Enforced by:
  dev-tenant scoping, a single-use dev claim code, and an image that contains **no secret, no signing key,
  no dataset** (only the public verification key).

## Honest boundary — 9/9 crypto tests ≠ the commercial chain

The **12/12** edge tests (incl. clone/expiry/enforcement) and the proven PHP↔Python interop verify the
**cryptographic mechanism**, not the end-to-end commercial licensing chain. The milestone that matters is
**a real physical AI box activating against the real staging Laravel server** (Tests 1–6). Only that
proves we can remotely provision commodity hardware without handing the supplier our source or production
credentials. None of it is sandbox-runnable (no box, no cloud DB, no HSM) — it is the on-hardware step.
