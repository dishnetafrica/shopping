#!/usr/bin/env bash
# P0-1 mutants — break delivery on purpose and prove the suite notices.
#
# A green suite means nothing on its own. The bug this work exists for shipped past a
# green suite, a working dashboard and a healthy-looking event log. So: reintroduce the
# original defect and each of its neighbours, and require a failure every time.
#
#   bash tests/mutants.sh
set -u
cd "$(dirname "$0")/.."
SRC="$(pwd)"
survived=0; killed=0

mutate () { # name file find replace
  local name="$1" file="$2" find="$3" repl="$4"
  local tmp; tmp="$(mktemp -d)"
  cp -r "$SRC"/. "$tmp"/ 2>/dev/null
  rm -rf "$tmp/data" "$tmp/.venv" "$tmp/__pycache__" "$tmp/app/__pycache__" "$tmp/tests/__pycache__"
  if ! python3 - "$tmp/$file" "$find" "$repl" <<'PY'
import sys
path, find, repl = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path, encoding='utf-8').read()
if find not in s:
    sys.exit(2)
open(path, 'w', encoding='utf-8').write(s.replace(find, repl, 1))
PY
  then
    echo "  ⚠ stale mutant (pattern not found): $name"
    survived=$((survived+1)); rm -rf "$tmp"; return
  fi
  if (cd "$tmp" && python3 -m pytest tests/ -q -p no:warnings >/dev/null 2>&1); then
    echo "  ✗ SURVIVED: $name"
    survived=$((survived+1))
  else
    killed=$((killed+1))
  fi
  rm -rf "$tmp"
}

echo
echo "  P0-1 · delivery mutants"
echo "  $(printf '─%.0s' {1..58})"

# ── the original bug, put back exactly as it shipped ──────────────────────────────
mutate "the compose environment: block returns (THE bug)" docker-compose.yml \
  '    env_file: .env' \
  '    env_file: .env
    environment:
      - STORE_ID=family-shoppers-01
      - EVENT_WEBHOOK_URL=
      - DEMO=0'

mutate "compose stops loading .env at all" docker-compose.yml \
  '    env_file: .env' \
  '    # env_file removed'

mutate "the shipped .env pre-fills a customer name again" .env \
  'STORE_ID=' \
  'STORE_ID=family-shoppers-01'

# ── the refusal ───────────────────────────────────────────────────────────────────
mutate "startup stops refusing (runs with no webhook)" app/events.py \
  '    fatal: list[str] = []
    warn: list[str] = []' \
  '    return [], []
    fatal: list[str] = []
    warn: list[str] = []'

mutate "main.py logs the problem but starts anyway" app/main.py \
  '        raise RuntimeError(' \
  '        logging.error("(ignored)"); _unused = ('

mutate "a missing store id becomes acceptable" app/events.py \
  '    if not store_id():
        fatal.append(' \
  '    if False:
        fatal.append('

mutate "the old hardcoded store default comes back" app/events.py \
  '    return os.environ.get("STORE_ID", "").strip()' \
  '    return os.environ.get("STORE_ID", "family-shoppers-01").strip()'

mutate "a missing token stops being mentioned" app/events.py \
  '        if not token():
            warn.append(' \
  '        if False:
            warn.append('

# ── the wire ──────────────────────────────────────────────────────────────────────
mutate "the auth token stops being sent" app/events.py \
  '        headers = {"X-Vision-Token": token()} if token() else {}' \
  '        headers = {}'

mutate "the store id stops travelling in the payload" app/events.py \
  '        "store": store_id(),' \
  '        "store": "",'

mutate "events are marked delivered even when the POST fails" app/events.py \
  '        r.raise_for_status()' \
  '        pass'

mutate "delivery silently does nothing" app/events.py \
  '        r = requests.post(webhook_url(), json=payload, timeout=TIMEOUT, headers=headers)' \
  '        r = requests.Response(); r.status_code = 200'

mutate "the store-and-forward retry is dropped" app/events.py \
  '    rows = db.db().execute(' \
  '    return
    rows = db.db().execute('

mutate "hysteresis fires on the very first frame" app/detector.py \
  '    if pending_count >= CONFIRMATIONS or stored_state == "unknown":' \
  '    if True:'

# ── the installer ─────────────────────────────────────────────────────────────────
mutate "install.sh stops verifying the resolved config" install.sh \
  'RESOLVED="$(docker compose config 2>/dev/null || true)"' \
  'RESOLVED=""'

mutate "install.sh warns instead of failing" install.sh \
  '  exit 1
fi
echo "-- Config verified' \
  '  :
fi
echo "-- Config verified'

# ── re-running the installer on a configured box ──────────────────────────────────
mutate "a re-install stops reading the box's existing config" install.sh \
  'PREV_STORE="$(prev STORE_ID)"' \
  'PREV_STORE=""'

mutate "the previous store id is read but not used as the default" install.sh \
  'STORE_ID="${STORE_ID:-$PREV_STORE}"' \
  'STORE_ID="${STORE_ID:-}"'

mutate "the customer name is baked back into the installer" install.sh \
  'STORE_ID="${STORE_ID:-$PREV_STORE}"' \
  'STORE_ID="${STORE_ID:-family-shoppers-01}"'

mutate "an install with no store id proceeds anyway" install.sh \
  'if [ -z "$STORE_ID" ]; then' \
  'if false; then'

echo
echo "  $killed killed, $survived survived"
echo
[ "$survived" -eq 0 ] || exit 1
