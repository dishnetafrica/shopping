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

## 4. Registry access for the box — **DECISION NEEDED (see §9)**

The box must `docker compose pull` the image. Pick one:
- **(recommended for the dev box) PUBLIC image** — mark the registry package public. The image holds
  only app code + the **public** key + no secrets, so a public pull is safe; **licensing (not registry
  secrecy) is what protects the business.** China needs no registry credential.
- **(private) short-lived pull token** — keep the package private and give the box a **read-only,
  pull-scoped, short-lived** token (never `REGISTRY_TOKEN`, never a GitHub/cloud/production credential).
  This needs a token-minting step we have **not** automated — see §9.

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
  expiry (offline). Then remove the supplier's remote access + rotate the box admin password.
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

## 9. What is NOT automated yet (STOP / owner decision)

The **registry pull credential** for a private image is **not** minted automatically. I did not invent a
token mechanism. Choose: **make the dev image public** (simplest, safe — recommended) or tell me you want
a private-registry short-lived-pull-token flow and I'll design it (it needs a registry that supports
scoped tokens, e.g. a GHCR PAT with only `read:packages`, or a registry-native robot account). Until then,
the box can only pull if the image is public or you hand it a pull token manually.

## 🔒 KEEP SECRET (never to China)
`VISION_LICENSE_SECRET` · server `.env` · DB credentials · `REGISTRY_TOKEN` · GitHub/Git access · Laravel
source · other tenants' data · training datasets/pipeline · any production license or credential.
