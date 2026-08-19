# ShopBot Vision — Owner Runbook (edge box)

For **you** (ShopBot owner). Everything needed to build, publish, activate, and revoke a development
edge box. Anything marked **🔒 KEEP SECRET** must never reach China or a repo.

## Owner quick checklist (GHCR)

```
GitHub   → set variable SHOPBOT_LICENSE_PUBKEY (§0–§1); keep the package Private
Registry → GHCR via built-in GITHUB_TOKEN (nothing to create); make a read-only pull PAT (§4)
Build    → git tag edge-v0.1.0 && git push origin edge-v0.1.0   (§2)
Verify   → docker buildx imagetools inspect … (digest) + pubkey present, no private key (§5)
China    → curl -fsSL https://mycloudbss.com/vision/bootstrap.sh -o sb.sh; sudo bash sb.sh
           (installer prompts China for the read-only pull token)                (§6, China guide)
Claim    → php artisan vision:claim <CODE> --tenant=22 --type=development         (§6)
Camera   → box dashboard → add RTSP/ONVIF camera → configure door                (first-box guide H–I)
Test     → synthetic + real movement, internet off/on                            (first-box guide J–M)
Revoke   → php artisan vision:revoke <box_uid> + delete the pull PAT + cut access (§7, first-box N–P)
```

### What you MUST configure manually (nothing else is automatic)
**On GitHub:** (1) repo **Variable** `SHOPBOT_LICENSE_PUBKEY`; (2) keep the `shopbot-vision` package
**Private**; (3) create the **read-only `read:packages` PAT** for pulling (§4). *No push secret needed —
GHCR uses the built-in `GITHUB_TOKEN`.*
**On mycloudbss.com:** (1) generate + set `VISION_LICENSE_SECRET` (🔒 server `.env`) and
`VISION_LICENSE_PUBLIC`; (2) `php artisan config:cache`; (3) tenant 22 `vision_reconcile_enabled=true`
(already done); (4) run `php artisan vision:claim …` when a box shows its code; (5) deploy the branch so
`/vision/bootstrap.sh` is served. **`VISION_LICENSE_PUBLIC` on the server must equal the GitHub variable
`SHOPBOT_LICENSE_PUBKEY`** (same keypair) or every license is rejected.

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

Registry = **GHCR** (GitHub Container Registry). The push uses the **built-in `GITHUB_TOKEN`** — there is
**no registry secret to create.** The only thing you set here is one variable:

| Kind | Name | Value | Secret? |
|---|---|---|---|
| **Variable** | `SHOPBOT_LICENSE_PUBKEY` | the `VISION_LICENSE_PUBLIC` value (base64) | no (public key) |

Set it at: repo → Settings → Secrets and variables → Actions → **Variables** → New variable. The workflow
**fails fast** if it's missing, and never echoes credentials. (`REGISTRY_USER`/`REGISTRY_TOKEN` are **not**
needed on GHCR.)

## 2. Build & publish the image

```bash
git tag edge-v0.1.0 && git push origin edge-v0.1.0
```
CI (`.github/workflows/edge-image.yml`) builds `linux/amd64` + `linux/arm64` and pushes:
```
ghcr.io/dishnetafrica/shopbot-vision:edge-v0.1.0     (PRIVATE by default)
```
The final workflow step prints `IMAGE_REF` and `DIGEST`. **Make sure the package stays Private:** repo →
Packages → `shopbot-vision` → Package settings → visibility = **Private**.
The final workflow step prints the exact `IMAGE_REF`. **No `:latest`** — always a version tag.

## 3. Point the installer at the image

Edit `public/vision/bootstrap.sh` → `DEFAULT_IMAGE="<REGISTRY>/shopbot-vision:edge-v0.1.0"` and deploy
(it's served statically at `https://mycloudbss.com/vision/bootstrap.sh`). That single line is the only
per-release edit.

## 4. The read-only pull credential (GHCR, for the box)

The image is **private**. The box pulls it with a **read-only PAT** that has **only `read:packages`** —
no repo write, no admin, not `GITHUB_TOKEN`, not a cloud/server credential. The installer prompts for it,
uses it once, and **`docker logout`s** — nothing is stored on the box afterward.

**Create the pull PAT** (ideally on a **dedicated/throwaway GitHub account** you add as a read-only
collaborator on the package, so it isn't tied to your main account):
1. GitHub → Settings → Developer settings → **Personal access tokens (classic)** → Generate new.
2. Scope: check **only `read:packages`**. Nothing else. Set a short expiry.
3. If using a separate account: repo → Packages → `shopbot-vision` → Package settings → **Manage Actions
   access / invite** → add that account with **Read**.
4. The **username** is that GitHub account's login; the **token** is the PAT (`ghp_…`).

**Manual login test** (optional, on any machine):
```bash
echo <PAT> | docker login ghcr.io -u <github-username> --password-stdin
docker pull ghcr.io/dishnetafrica/shopbot-vision:edge-v0.1.0
docker logout ghcr.io
```
**The installer does this for you** — it prompts for the username + token, logs in, pulls, and logs out.
You give China only this read-only token (or run the install yourself over remote access).

## 5. Verify the image (digest + no secrets) before shipping

```bash
IMG=ghcr.io/dishnetafrica/shopbot-vision:edge-v0.1.0
# Digest — record it; the box can pin by digest (…@sha256:…) so it pulls exactly this build:
docker buildx imagetools inspect "$IMG" | grep -i digest
# Pubkey baked in is present + matches config('vision.license_public'):
docker run --rm "$IMG" python -c "import os;print('pubkey', os.environ.get('SHOPBOT_LICENSE_PUBKEY','')[:16],'…')"
# No signing private key anywhere in the image:
docker run --rm "$IMG" sh -c "grep -rl PRIVATE / 2>/dev/null | head || echo 'no private key ✓'"
```
Confirm the printed pubkey matches `php artisan tinker --execute='echo config("vision.license_public");'`.

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
