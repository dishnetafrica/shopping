#!/usr/bin/env bash
#
# v3-assets-sync-check.sh — fail if the served V3 assets are out of sync with the source of truth.
#
# Compares public/assets/v3/{shell.js,shell.css} against resources/v3/{shell.js,shell.css}.
# READ-ONLY: it never modifies any file. Used by CI (the `v3-assets` job) and runnable locally.
# Exits non-zero when the copies differ or a served file is missing.
#
set -euo pipefail

cd "$(dirname "$0")/.."   # repo root

src="resources/v3"
dst="public/assets/v3"
status=0

for f in shell.js shell.css; do
  if [ ! -f "$src/$f" ]; then
    echo "MISSING source: $src/$f"
    status=1
  elif [ ! -f "$dst/$f" ]; then
    echo "MISSING served copy: $dst/$f"
    status=1
  elif ! cmp -s "$src/$f" "$dst/$f"; then
    echo "OUT OF SYNC: $dst/$f differs from $src/$f"
    status=1
  fi
done

if [ "$status" -ne 0 ]; then
  echo ""
  echo "V3 assets are out of sync. resources/v3 is the source of truth."
  echo "Fix it with:"
  echo "    scripts/v3-assets-sync.sh"
  echo "then commit the updated public/assets/v3 files."
  exit 1
fi

echo "V3 assets in sync (shell.js, shell.css)."
