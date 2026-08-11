#!/usr/bin/env bash
# CloudBSS Vision Gateway — one-command installer for Ubuntu 22.04/24.04 (x86 industrial/mini PC).
#
#   sudo bash install.sh                          # interactive (asks store id + webhook)
#   sudo STORE_ID=family-shoppers-01 EVENT_WEBHOOK_URL=https://... bash install.sh   # unattended
#
# Installs Docker if missing, copies this folder to /opt/cloudbss-vision, writes .env,
# builds + starts the stack with restart-on-boot, installs `visionctl`. Idempotent — safe to re-run.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/cloudbss-vision

echo "== CloudBSS Vision Gateway installer =="

# ── what is this box already configured with? ─────────────────────────────────────
# Read this NOW, before the rsync below replaces .env with the packaged one (which
# ships empty on purpose). Without this, re-running the installer on a working box
# silently discards its store id, webhook and token — and an unattended re-run would
# then abort at the verification step, leaving a box whose next restart refuses.
# Parsed with sed rather than sourced: .env is data, not a script to execute.
prev () { [ -f "$DEST/.env" ] && sed -n "s/^$1=//p" "$DEST/.env" | head -1 || true; }
PREV_STORE="$(prev STORE_ID)"
PREV_HOOK="$(prev EVENT_WEBHOOK_URL)"
PREV_TOKEN="$(prev VISION_TOKEN)"

if ! command -v docker >/dev/null 2>&1; then
  echo "-- Installing Docker (docker.io + compose plugin)…"
  apt-get update -q
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker.io docker-compose-v2
fi
systemctl enable --now docker

# Environment wins (unattended installs), then whatever the box already had.
# There is deliberately no packaged default store id: the old one was a real
# customer's name, so pressing Enter on the second store filed its shelves under the
# first one's books.
STORE_ID="${STORE_ID:-$PREV_STORE}"
EVENT_WEBHOOK_URL="${EVENT_WEBHOOK_URL:-$PREV_HOOK}"
VISION_TOKEN="${VISION_TOKEN:-$PREV_TOKEN}"

if [ -t 0 ]; then
  [ -n "$PREV_STORE$PREV_HOOK" ] && echo "-- Existing configuration found. Press Enter to keep a value."
  ans=""; read -rp "Store ID${STORE_ID:+ [$STORE_ID]}: " ans || true
  STORE_ID="${ans:-$STORE_ID}"
  ans=""; read -rp "Event webhook URL (CloudBSS /api/vision/events)${EVENT_WEBHOOK_URL:+ [$EVENT_WEBHOOK_URL]}: " ans || true
  EVENT_WEBHOOK_URL="${ans:-$EVENT_WEBHOOK_URL}"
  if [ -n "$EVENT_WEBHOOK_URL" ]; then
    ans=""; read -rp "X-Vision-Token${VISION_TOKEN:+ [keep existing]}: " ans || true
    VISION_TOKEN="${ans:-$VISION_TOKEN}"
  fi
fi

if [ -z "$STORE_ID" ]; then
  echo "!! No store id. Re-run interactively, or: sudo STORE_ID=… EVENT_WEBHOOK_URL=… bash install.sh" >&2
  exit 1
fi

mkdir -p "$DEST"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete --exclude data --exclude .venv "$SRC_DIR"/ "$DEST"/
else
  cp -r "$SRC_DIR"/. "$DEST"/
fi
mkdir -p "$DEST/data/snapshots"

cat > "$DEST/.env" << EOF
STORE_ID=$STORE_ID
EVENT_WEBHOOK_URL=$EVENT_WEBHOOK_URL
VISION_TOKEN=$VISION_TOKEN
DEMO=0
ALLOW_NO_WEBHOOK=0
EOF

# NOTE: docker-compose.yml carries `env_file: .env` and deliberately declares NO
# `environment:` block. Compose gives `environment:` precedence over `env_file:` —
# including empty values — so a block there silently discarded everything asked for
# above. The sed patch that used to live here was papering over that: it added
# env_file while leaving the overriding block in place, so it never worked.

cd "$DEST"

# ── the config must survive into the container BEFORE we call this a success ──────
# This is the exact failure that shipped: the installer answered every question, the
# install reported "Done", and the box delivered nothing for weeks.
RESOLVED="$(docker compose config 2>/dev/null || true)"
fail=0
if [ -n "$EVENT_WEBHOOK_URL" ] && ! printf '%s' "$RESOLVED" | grep -qF -- "$EVENT_WEBHOOK_URL"; then
  echo "!! The webhook URL did not reach the container configuration." >&2; fail=1
fi
if ! printf '%s' "$RESOLVED" | grep -qF -- "$STORE_ID"; then
  echo "!! The store id did not reach the container configuration." >&2; fail=1
fi
if [ "$fail" = 1 ]; then
  echo "!! Aborting: something in docker-compose.yml is overriding .env." >&2
  echo "   Check for an 'environment:' block — it must not exist." >&2
  exit 1
fi
echo "-- Config verified: store '$STORE_ID', webhook ${EVENT_WEBHOOK_URL:-<none>}, token $([ -n "$VISION_TOKEN" ] && echo set || echo MISSING)"

docker compose up -d --build

cat > /usr/local/bin/visionctl << 'EOF'
#!/usr/bin/env bash
# visionctl — status | logs | restart | update | stop
cd /opt/cloudbss-vision || exit 1
case "${1:-status}" in
  status)  docker compose ps; echo; curl -sf localhost:8080/api/health && echo || echo "API not responding";;
  logs)    docker compose logs -f --tail=100;;
  restart) docker compose restart;;
  stop)    docker compose down;;
  update)  docker compose up -d --build;;
  *) echo "usage: visionctl {status|logs|restart|update|stop}";;
esac
EOF
chmod +x /usr/local/bin/visionctl

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo
echo "== Done =="
echo "Dashboard:  http://${IP:-<this-pc>}:8080"
echo "Health:     visionctl status     Logs: visionctl logs"
echo "Next: open the dashboard -> Discover ONVIF devices -> add your NVR channels."
echo
echo "Prove delivery end to end before you leave the shop:"
echo "  visionctl logs | grep -i delivery     # startup line naming the store and URL"
echo "  then confirm one row lands in CloudBSS vision_events."
echo "If the container is restarting, the gateway is refusing to run — visionctl logs says why."
