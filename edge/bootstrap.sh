#!/usr/bin/env bash
# ShopBot Vision — first-sample PULL-BASED bootstrap installer (single AI box).
#
#   curl -fsSL https://install.shopbot.africa | sudo bash                 # (eventual, config prompted/env)
#   sudo STORE_ID=wh-juba-01 EVENT_WEBHOOK_URL=https://staging/api/vision/events \
#        VISION_TOKEN=... IMAGE_REF=ghcr.io/dishnetafrica/shopbot-vision:v0.1 bash bootstrap.sh
#
# Pulls a versioned multi-arch image from our registry (NEVER builds on the box), keeps config + data on
# a persistent host dir, downloads the AI model separately (checksum-verified), starts the services and
# proves the uplink to ShopBot. Idempotent — safe to re-run; existing .env/data are preserved.
#
# SECURITY: this script contains NO secret. The webhook token is supplied at run time (env or, later, a
# claim-code enrolment) and written to a root-only .env on the box — it is never baked into the public
# installer and never shared across boxes. Fleet/OTA/enrolment/billing are deliberately NOT here.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo/root."; exit 1; }

DEST="${SHOPBOT_DIR:-/opt/shopbot-vision}"
DATA="$DEST/data"
IMAGE_REF="${IMAGE_REF:-ghcr.io/dishnetafrica/shopbot-vision:v0.1}"   # pin by :semver or @sha256 digest
SB_COUNTRY="${SB_COUNTRY:-UG}"
MIN_RAM_MB="${MIN_RAM_MB:-7000}"        # ~8GB nominal
MIN_DISK_GB="${MIN_DISK_GB:-20}"

say()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1" >&2; }
die()  { printf '  \033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

echo "== ShopBot Vision installer =="

# ── 1. preflight checks ───────────────────────────────────────────────────────────────────────
ARCH="$(uname -m)"
case "$ARCH" in
  aarch64|arm64) say "ARM64 detected ($ARCH)";;
  x86_64|amd64)  say "x86_64 detected ($ARCH)";;
  *) die "unsupported architecture: $ARCH (need arm64 or x86_64)";;
esac

if command -v lsb_release >/dev/null 2>&1; then
  say "OS: $(lsb_release -ds 2>/dev/null)"
else
  warn "lsb_release absent — assuming a Debian/Ubuntu base"
fi

RAM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
[ "$RAM_MB" -ge "$MIN_RAM_MB" ] && say "RAM ${RAM_MB}MB" || warn "RAM ${RAM_MB}MB is below ${MIN_RAM_MB}MB recommended"

DISK_GB="$(df -Pk "$([ -d "$DEST" ] && echo "$DEST" || dirname "$DEST")" 2>/dev/null | awk 'NR==2{print int($4/1048576)}')"
[ "${DISK_GB:-0}" -ge "$MIN_DISK_GB" ] && say "Free disk ${DISK_GB}GB" || warn "Free disk ${DISK_GB:-?}GB is below ${MIN_DISK_GB}GB recommended (image+model+outbox)"

if curl -fsS -o /dev/null --max-time 10 https://github.com 2>/dev/null; then
  say "Internet reachable"
else
  die "no internet — the bootstrap needs to pull the image + model (operation AFTER install is offline-tolerant)"
fi

# ── 2. delivery config must be present (no silent, unreportable box) ──────────────────────────
prev() { [ -f "$DEST/.env" ] && sed -n "s/^$1=//p" "$DEST/.env" | head -1 || true; }
STORE_ID="${STORE_ID:-$(prev STORE_ID)}"
EVENT_WEBHOOK_URL="${EVENT_WEBHOOK_URL:-$(prev EVENT_WEBHOOK_URL)}"
VISION_TOKEN="${VISION_TOKEN:-$(prev VISION_TOKEN)}"
ADMIN_USER="${ADMIN_USER:-$(prev ADMIN_USER)}"; ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(prev ADMIN_PASSWORD)}"

[ -n "$STORE_ID" ] || die "STORE_ID is required (identifies the store). Pass it as an env var."
[ -n "$EVENT_WEBHOOK_URL" ] || die "EVENT_WEBHOOK_URL is required (ShopBot /api/vision/events). Pass it as an env var."
[ -n "$VISION_TOKEN" ] || warn "VISION_TOKEN empty — staging will reject events until it is set in $DEST/.env"
if [ -z "$ADMIN_PASSWORD" ]; then
  ADMIN_PASSWORD="$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)"
  NEW_ADMIN_PW=1
fi

# ── 3. Docker ─────────────────────────────────────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
  say "Installing Docker…"
  apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker.io docker-compose-v2 \
    || die "Docker install failed — install docker + compose plugin manually and re-run"
fi
systemctl enable --now docker >/dev/null 2>&1 || true
docker compose version >/dev/null 2>&1 || die "docker compose plugin missing"
say "Docker ready"

# ── 4. install dir, persistent data, device identity ─────────────────────────────────────────
mkdir -p "$DATA/snapshots" "$DATA/models"
chmod 700 "$DATA" 2>/dev/null || true

DEVID_FILE="$DATA/device_id"
if [ -f "$DEVID_FILE" ]; then
  DEVICE_ID="$(cat "$DEVID_FILE")"
else
  HW="$(cat /etc/machine-id 2>/dev/null || cat /var/lib/dbus/machine-id 2>/dev/null || head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  SUF="$(printf '%s' "$HW" | sha256sum | cut -c1-6 | tr '[:lower:]' '[:upper:]')"
  DEVICE_ID="SB-${SB_COUNTRY}-${SUF}"        # non-secret, stable per box
  echo "$DEVICE_ID" > "$DEVID_FILE"
fi

# .env — root-only, no secret ever came from the public script (only from the operator's env)
cat > "$DEST/.env" <<EOF
STORE_ID=$STORE_ID
EVENT_WEBHOOK_URL=$EVENT_WEBHOOK_URL
VISION_TOKEN=$VISION_TOKEN
ADMIN_USER=$ADMIN_USER
ADMIN_PASSWORD=$ADMIN_PASSWORD
ALLOW_NO_WEBHOOK=0
ALLOW_NO_AUTH=0
DEVICE_ID=$DEVICE_ID
EOF
chmod 600 "$DEST/.env"

# compose — PULL-based (image pinned), no build stanza, config + data on the host bind mount
cat > "$DEST/docker-compose.yml" <<EOF
services:
  vision-api:
    image: $IMAGE_REF
    container_name: shopbot-vision-api
    restart: unless-stopped
    network_mode: host
    env_file: .env
    volumes:
      - ./data:/data
  vision-door:
    image: $IMAGE_REF
    container_name: shopbot-vision-door
    depends_on: [vision-api]
    restart: unless-stopped
    network_mode: host
    env_file: .env
    volumes:
      - ./data:/data
    # Default = plumbing selftest (emits one test event to staging). Swap to the real door once a
    # camera + model are configured:  --rtsp rtsp://… --detector module:fn --line ax,ay,bx,by
    command: ["python", "-m", "app.movement.run_door", "--selftest", "--store", "$STORE_ID", "--zone", "D->EXIT"]
EOF
say "Config written ($DEST) — Device ID $DEVICE_ID"

# ── 5. pull the image (matched to this arch by the registry manifest) ─────────────────────────
cd "$DEST"
say "Downloading Vision Engine ($IMAGE_REF)…"
docker compose pull || die "image pull failed — check IMAGE_REF and registry access"

# ── 6. AI model (separate from the image, checksum-verified) ─────────────────────────────────
if [ -n "${MODEL_URL:-}" ]; then
  MODEL_VERSION="${MODEL_VERSION:-v0_1}"
  MODEL_DIR="$DATA/models/$MODEL_VERSION"
  mkdir -p "$MODEL_DIR"
  say "Downloading model ($MODEL_VERSION)…"
  curl -fsSL "$MODEL_URL" -o "$MODEL_DIR/model.bin" || die "model download failed"
  if [ -n "${MODEL_SHA256:-}" ]; then
    echo "${MODEL_SHA256}  $MODEL_DIR/model.bin" | sha256sum -c - || die "model checksum mismatch — refusing to use it"
    say "Model checksum verified"
  else
    warn "MODEL_SHA256 not provided — model integrity unverified"
  fi
else
  warn "No MODEL_URL — starting the plumbing selftest only (no carton detection until a model is installed)"
fi

# ── 7. start ──────────────────────────────────────────────────────────────────────────────────
say "Starting services…"
docker compose up -d || die "compose up failed — see: docker compose -f $DEST/docker-compose.yml logs"

# ── 7b. licensed activation (enrollment) ──────────────────────────────────────────────────────
# When SHOPBOT_CLOUD_URL is set, the box generates its Ed25519 keypair (private key stays in
# /data/device_key, 0600), sends its public key + fingerprint through the EXISTING hello/poll, prints a
# claim code, and waits until an operator runs `php artisan vision:claim CODE --tenant=<DEV>
# --type=development` on ShopBot Cloud — then caches the signed license. No permanent secret is baked.
if [ -n "${SHOPBOT_CLOUD_URL:-}" ]; then
  say "Activating device against ShopBot Cloud…"
  docker compose run --rm vision-api python -m app.licensing.enroll --cloud "$SHOPBOT_CLOUD_URL" \
    || warn "enrollment did not complete — run vision:claim on the cloud, then: (cd $DEST && docker compose run --rm vision-api python -m app.licensing.enroll --cloud $SHOPBOT_CLOUD_URL)"

  # Wire the per-box delivery credential (minted at claim, cached by enroll) into the event-delivery
  # service as VISION_TOKEN. China never receives or types a permanent token — the box holds its OWN
  # per-box webhook_token, and event delivery uses it. The token is written root-only and the services
  # are recreated to pick it up.
  if [ -f "$DATA/credential" ]; then
    TOK="$(cat "$DATA/credential")"
    if grep -q '^VISION_TOKEN=' "$DEST/.env"; then
      sed -i "s|^VISION_TOKEN=.*|VISION_TOKEN=$TOK|" "$DEST/.env"
    else
      echo "VISION_TOKEN=$TOK" >> "$DEST/.env"
    fi
    chmod 600 "$DEST/.env"
    docker compose up -d --force-recreate
    say "Per-box delivery credential wired into VISION_TOKEN (no token was ever typed)"
  fi
fi

# ── 8. health-gate + uplink proof ─────────────────────────────────────────────────────────────
ok=0
for _ in $(seq 1 30); do
  curl -fsS -u "$ADMIN_USER:$ADMIN_PASSWORD" http://localhost:8080/api/health >/dev/null 2>&1 && { ok=1; break; }
  sleep 2
done
[ "$ok" = 1 ] || die "the API did not come up — the box may be refusing to start: docker compose logs"
say "Container healthy (/api/health)"

RESULT="$(curl -fsS -u "$ADMIN_USER:$ADMIN_PASSWORD" -X POST http://localhost:8080/api/selftest 2>/dev/null || true)"
case "$RESULT" in
  *'"ok":true'*) say "Test event delivered to ShopBot staging" ;;
  *) warn "Test event was NOT accepted by staging: $RESULT — fix VISION_TOKEN/URL in $DEST/.env then: (cd $DEST && docker compose restart)" ;;
esac

# ── 9. done ───────────────────────────────────────────────────────────────────────────────────
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "== ShopBot Vision online =="
echo "Device ID: $DEVICE_ID"
echo "Open:      http://${IP:-<box-ip>}:8080   (login: $ADMIN_USER / ${NEW_ADMIN_PW:+$ADMIN_PASSWORD}${NEW_ADMIN_PW:+  <- generated, write it down})"
echo "Next:      dashboard → add IP camera (RTSP/ONVIF) → configure door line → switch vision-door to the real --rtsp/--detector command."
