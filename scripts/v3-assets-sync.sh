#!/usr/bin/env bash
#
# v3-assets-sync.sh — copy the V3 shell assets from the source of truth to the served copy.
#
#   resources/v3/{shell.js,shell.css}   (source of truth — edit these)
#        │  copy
#        ▼
#   public/assets/v3/{shell.js,shell.css}   (generated/served copy — never edit by hand)
#
# V3 assets are hand-authored, not compiled — there is deliberately NO frontend build system.
# public/assets/v3 must stay byte-identical to resources/v3; nginx serves the public copy and the
# /assets/v3/{file} route falls back to the resources copy. Run this after changing anything under
# resources/v3, then commit the updated public/assets/v3 files. Safe, deterministic, idempotent.
#
set -euo pipefail

cd "$(dirname "$0")/.."   # repo root, regardless of where this is invoked from

src="resources/v3"
dst="public/assets/v3"

mkdir -p "$dst"
for f in shell.js shell.css; do
  if [ ! -f "$src/$f" ]; then
    echo "error: source file missing: $src/$f" >&2
    exit 1
  fi
  cp -f "$src/$f" "$dst/$f"
done

echo "V3 assets synced: $src -> $dst (shell.js, shell.css)"
