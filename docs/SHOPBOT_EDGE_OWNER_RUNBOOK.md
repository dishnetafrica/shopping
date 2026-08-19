# ShopBot Vision — Owner Runbook (edge box)

For **you** (ShopBot owner). Everything needed to build, publish, activate, and revoke a development
edge box. Anything marked **🔒 KEEP SECRET** must never reach China or a repo.

```
Generate license keypair → set GitHub variables/secrets → push edge-v0.1.0 →
CI builds amd64+arm64 → registry stores image → China runs one command →
claim code → vision:claim --tenant=22 --type=development → box activates → connect camera
```

## 0. One-time secrets & keys

**License signing keypair** (Ed25519). Generate once on the server:
```bash
php artisan tinker --execute='
$kp=sodium_crypto_sign_keypair();
echo "VISION_LICENSE_SECRET=".base64_encode(sodium_crypto_sign_secretkey($kp))."\n";
echo "VISION_LICENSE_PUBLIC=".base64_encode(sodium_crypto_sign_publickey($kp))."\n";'
```
- `VISION_LICENSE_SECRET` → **🔒 KEEP SECRET** — server `.env` only. Never in the image, repo, CI, or China's hands.
- `VISION_LICENSE_PUBLIC` → server `.env` **and** GitHub repo **variable** `SHOPBOT_LICENSE_PUBKEY` (safe to expose).

After editing server `.env`: `php artisan config:cache`. **The public key baked into the image MUST byte-match `config('vision.license_public')`** — if not, every license is rejected on the box.

## 1. GitHub configuration (for the `edge-image` workflow)

| Kind | Name | Value | Secret? |
|---|---|---|---|
| Variable | `REGISTRY` | e.g. `ghcr.io/dishnetafrica` | no |
| Variable | `SHOPBOT_LICENSE_PUBKEY` | the `VISION_LICENSE_PUBLIC` value | no (public key) |
| Secret | `REGISTRY_USER` | registry username | 🔒 |
| Secret | `REGISTRY_TOKEN` | registry push token (write:packages) | 🔒 |

The workflow **fails fast** if `SHOPBOT_LICENSE_PUBKEY` or `REGISTRY` is missing, and never echoes credentials.

## 2. Build & publish an image

```bash
git tag edge-v0.1.0 && git push origin edge-v0.1.0
```
CI (`.github/workflows/edge-image.yml`) builds `linux/amd64` + `linux/arm64` and pushes:
```
<REGISTRY>/shopbot-vision:edge-v0.1.0
```
The final workflow step prints the exact `IMAGE_REF`. **No `:latest`** — always a version tag.

## 3. Point the installer at the image

Edit `public/vision/bootstrap.sh` → `DEFAULT_IMAGE="<REGISTRY>/shopbot-vision:edge-v0.1.0"` and deploy
(it's served statically at `https://mycloudbss.com/vision/bootstrap.sh`). That single line is the only
per-release edit.

## 4. Registry access for the box — PRIVATE image, read-only pull credential

**Decision: keep the image PRIVATE.** The box authenticates with a dedicated **pull-only** credential —
never `REGISTRY_TOKEN`, never GitHub, never a cloud/server credential. The installer takes it at run time
(`REGISTRY_PULL_USER` / `REGISTRY_PULL_TOKEN`, host derived from `IMAGE_REF`) and `docker login`s via
stdin, so the token never hits logs or the process list. It is **not** baked into the public installer.

**Which registry?** Nothing is committed yet — the workflow reads `vars.REGISTRY`. Pick one and set it;
the pull-credential recipe differs:

| Registry | Read-only pull credential | Short-lived? | Revoke |
|---|---|---|---|
| **Self-hosted Harbor** (best) | **robot account**, `pull` on the `shopbot-vision` repo only, with an **expiry date** | ✅ native | delete the robot account |
| **AWS ECR** (best for short-lived) | `aws ecr get-login-password` (**12h** token); IAM policy limited to `BatchGetImage`/`GetDownloadUrlForLayer` on that repo | ✅ 12h | rotate IAM / deny |
| **Docker Hub** (simplest) | Account → Security → **Read-only** access token | revocable | delete the token |
| **GHCR** (github-native) | dedicated throwaway account or bot with a **classic PAT scoped ONLY `read:packages`**, granted **read** on the `shopbot-vision` package (package kept private) | not native (revocable) | delete the PAT / remove package access |

**Recommendation:** if you run **Harbor** or **ECR**, use their native **short-lived, scoped** tokens —
that's the ideal (pull-only + auto-expiring). If **GHCR/Docker Hub**, use a dedicated **read-only** token
you can delete after the test. In all cases the credential does **only** image-pull and nothing else.

**How the box uses it** (you run this during the remote session, or hand China the token privately):
```bash
curl -fsSL https://mycloudbss.com/vision/bootstrap.sh -o /tmp/sb.sh
sudo REGISTRY_PULL_USER=<pull-user> REGISTRY_PULL_TOKEN=<read-only-token> bash /tmp/sb.sh
```

## 5. Verify the image before shipping

```bash
docker pull <REGISTRY>/shopbot-vision:edge-v0.1.0
docker run --rm <REGISTRY>/shopbot-vision:edge-v0.1.0 python -c "import os;print('pubkey len', len(os.environ.get('SHOPBOT_LICENSE_PUBKEY','')))"   # non-zero
docker run --rm <REGISTRY>/shopbot-vision:edge-v0.1.0 sh -c 'ls /data 2>/dev/null; grep -rl PRIVATE / 2>/dev/null | head'   # no private key
```
Confirm the printed pubkey matches `config('vision.license_public')`.

## 6. Activate a box (claim)

When the box prints a claim code:
```bash
php artisan vision:claim <CODE> --tenant=22 --type=development
```
The box's next poll (≤10 s) receives its signed **development** license + per-box token and activates.
**Never pass `--type=production` for a supplier box.**

## 7. Health, revoke, update

- **Health:** on the box, `visionctl status` (or `curl -u admin:<pw> http://<ip>:8080/api/health`) shows
  `version / device_id / license {status,type,tenant,expires_at} / queue`.
- **Revoke:** `php artisan vision:revoke <box_uid>` — stops at next heartbeat (online) or at license
  expiry (offline). Then, to fully cut the supplier off: **revoke the read-only pull credential** (§4:
  delete the robot account / access token / PAT), remove their remote access (SSH keys / tunnel agent),
  and rotate the box admin password.
- **Update:** push a new `edge-vX.Y.Z` tag → edit `DEFAULT_IMAGE` → on the box re-run the installer (it's
  idempotent) or `cd /opt/shopbot-vision && SHOPBOT_EDGE_IMAGE=<new> docker compose pull && docker compose up -d`.

## 8. Troubleshoot failed activation

| Symptom | Cause / fix |
|---|---|
| license rejected on box | pubkey in image ≠ `config('vision.license_public')` — rebuild image with the right `SHOPBOT_LICENSE_PUBKEY` |
| box never claims | you didn't run `vision:claim`, or the claim code expired — read the current code on the box screen |
| refuses to start (enforced) | no valid license cached — check `vision_boxes` row status/expiry; `VISION_LICENSE_ENFORCE` gating |
| events 401 at ingest | box not `claimed`, or token mismatch — check the box row `status` and `webhook_token` |
| reconcile not running for 22 | `Tenant::find(22)->setting('vision_reconcile_enabled')` must be `true` |
| pull denied | registry package private + no pull token — see §4/§9 |

## 9. Honest limits (read before trusting)

- **A private registry does NOT make the image uncopyable.** A Docker image of Python app code is
  **inspectable and copyable by anyone with root on the box** — it is not "compiled" or hidden. Keeping
  the registry private raises the bar (China can't casually redistribute the image), but the **real
  protection is elsewhere**: the signed **license** + **cloud authorization** + **device identity** +
  server-side **reconciliation / business logic** + your **model & data pipeline** — none of which are on
  the box. Treat the private registry as friction, not a moat.
- **What stays a manual owner action (by design):** creating and revoking the read-only pull credential
  (§4) is a one-time step per registry. It is deliberately manual — it's a scoped secret, and auto-minting
  it would add a credential-issuing surface we don't need for one box. If you later want per-box
  auto-issued short-lived pull tokens (Harbor robots / ECR), say so and I'll design it.
- **Do not introduce auto-updates yet.** Pin `edge-v0.1.0` and prove one physical box end-to-end first.

## 🔒 KEEP SECRET (never to China)
`VISION_LICENSE_SECRET` · server `.env` · DB credentials · `REGISTRY_TOKEN` · GitHub/Git access · Laravel
source · other tenants' data · training datasets/pipeline · any production license or credential.
