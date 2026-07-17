#!/usr/bin/env bash
# scripts/boundary-cleanup.sh — Phase 2 of MODULE-BOUNDARY-AUDIT.md.
#
# Archives the duplicate/stale code homes (F1, F2) and indexes the 250+ root docs
# into docs/ (F3). MOVE-ONLY: nothing autoloaded is touched, no file content changes,
# so behaviour is identical before and after. ArchitectureBoundariesTest passes in
# both states by design.
#
#   bash scripts/boundary-cleanup.sh            # dry run (prints the plan)
#   bash scripts/boundary-cleanup.sh --apply    # executes with git mv, then commit yourself
#
# Run this INSIDE the git working copy (not via zip upload — zips cannot delete/move).
set -euo pipefail
cd "$(dirname "$0")/.."

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

run() {
  if [ "$APPLY" = 1 ]; then eval "$@"; else echo "DRY-RUN: $*"; fi
}

mv_git() { # mv_git <src> <dstdir>
  [ -e "$1" ] || return 0
  run "mkdir -p '$2'"
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    run "git mv -k '$1' '$2/'"
  else
    run "mv '$1' '$2/'"
  fi
}

echo "== F1: duplicate kernel copies (byte-identical to cloudbss-kernel/*) =="
# Safety: refuse if the duplicates have drifted — that would need a human eye first.
if [ -d src ] && ! diff -rq src cloudbss-kernel/src >/dev/null 2>&1; then
  echo "ABORT: src/ has DRIFTED from cloudbss-kernel/src — reconcile manually first." >&2; exit 1
fi
if [ -d modules ] && ! diff -rq modules cloudbss-kernel/modules >/dev/null 2>&1; then
  echo "ABORT: modules/ has DRIFTED from cloudbss-kernel/modules — reconcile manually first." >&2; exit 1
fi
mv_git src               docs/archive/legacy-code
mv_git modules           docs/archive/legacy-code
for f in tests/autoload.php tests/run.php tests/stress.php; do
  # identical copies live in cloudbss-kernel/tests/ — harness runs from there now
  if [ -f "$f" ] && cmp -s "$f" "cloudbss-kernel/tests/$(basename "$f")"; then
    mv_git "$f" docs/archive/legacy-code/tests
  fi
done
echo "   (kernel harness from now on: php cloudbss-kernel/tests/run.php)"

echo "== F2: stale root twins of app/ files =="
for d in Capability Console Http Jobs Services; do
  mv_git "$d" docs/archive/legacy-code
done

echo "== F3: index root docs into docs/ =="
run "mkdir -p docs/decisions docs/architecture docs/archive"
# decisions
for f in ADR-*.md CONSTITUTION.md; do
  [ -e "$f" ] && mv_git "$f" docs/decisions
done
# living architecture docs
for f in CloudBSS-Architecture-Report.md CloudBSS-Blueprint.md \
         ACCOUNTING-CONTROLS-FROZEN-v3.0.md MODULE-BOUNDARY-AUDIT.md \
         EMAIL-CENTER-DEPLOY.md; do
  [ -e "$f" ] && mv_git "$f" docs/architecture
done
# everything else at root: *.md and *.txt → archive
shopt -s nullglob
for f in *.md *.txt; do
  mv_git "$f" docs/archive
done
shopt -u nullglob

echo
if [ "$APPLY" = 1 ]; then
  echo "Done. Review with 'git status', run 'php artisan test --filter=Architecture', then commit."
else
  echo "Dry run only — re-run with --apply to execute."
fi
