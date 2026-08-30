# V3 Maintainability Standard

**Status:** active · **Scope:** the V3 seller panel (`/v3`) and its assets, endpoints and change process.

V3 is the sole strategic frontend for ShopBot/CloudBSS. V2 (`/panel/*`, `resources/panel/*.html`)
is frozen legacy. This document is the standing rulebook for keeping V3 maintainable as it grows.
It is derived from the completed V2→V3 maintainability audit; it does **not** authorise a rewrite.

---

## 1. V3-only development policy

- **All new UI functionality is built in V3** (`resources/v3/*` + `app/Capability/**`). Nothing new
  goes into V2.
- **Do not modify V2 unless the user explicitly asks** — and only for a critical fix required by the
  remaining classic-role users (purchaser / field). When in doubt, build it in V3 and leave V2 alone.
- Default rule when picking up any feature: **"V3 only. Do not modify V2 unless explicitly requested."**

## 2. Source of truth vs generated assets

| Path | Role | Rule |
|------|------|------|
| `resources/v3/shell.js`, `resources/v3/shell.css` | **Source of truth** | Edit these. |
| `public/assets/v3/shell.js`, `public/assets/v3/shell.css` | **Generated / served copy** | Never edit by hand. |

The two copies must stay **byte-identical**. nginx serves `public/assets/v3/*`; the `/assets/v3/{file}`
route (`routes/web.php`) falls back to `resources/v3/*` if the public copy is missing (self-healing).
There is deliberately **no frontend build system** — the assets are hand-authored, so "build" is a copy.

## 3. Sync procedure

After changing anything under `resources/v3/`:

```bash
scripts/v3-assets-sync.sh      # copies resources/v3 -> public/assets/v3 (idempotent)
git add resources/v3 public/assets/v3
```

To check locally without modifying anything:

```bash
scripts/v3-assets-sync-check.sh   # exits non-zero if the copies differ
```

Workflow: **edit `resources/v3` → run sync → commit both → CI verifies → deploy.**

## 4. CI enforcement

`.github/workflows/php.yml` runs a small, independent **`v3-assets`** job (no PHP/DB) that runs
`scripts/v3-assets-sync-check.sh` and **fails the PR** if `resources/v3` and `public/assets/v3` differ.
This is the hard guarantee: it is impossible to merge out-of-sync V3 assets. No pre-commit hook is
used (CI is the single, reliable enforcement point).

## 5. Architecture — keep the UI thin

New business logic belongs in the capability stack, **not** in `shell.js`:

```
V3 UI (shell.js)
      ↓  calls an endpoint, renders the result
Capability            app/Capability/Object · app/Capability/Process
      ↓
Command / Process     app/Capability/Command · CommandBus
      ↓
Shared business logic app/Services/** , cloudbss-kernel (money), domain services
      ↓
API / database / external service
```

- A screen should **call a capability/endpoint and render** — it should not embed pricing, tax,
  accounting or inventory rules. Those live behind a Capability → Command/Process → shared service so
  they are testable and reused by the bot, jobs and any future surface.
- When you add a feature (e.g. AI product identification, WhatsApp abandoned-cart, eFRIS, Vision),
  put the logic in a service/capability and keep the V3 UI a thin caller.

## 6. Which API to call — `/papi` vs `api/v1/workspace`

- **`api/v1/workspace` + CommandBus (capabilities)** — the V3-native path. **Prefer this for new V3
  features**; it is the structured Object/Process/Command layer the platform is built on.
- **`/papi/*` (`PanelApiController`)** — the shared legacy API used by both V2 and V3 (~40 routes,
  CSRF-exempt; v3's `post()`/`getQuery()` helpers already send session + CSRF). Fine to **reuse an
  existing** `/papi` endpoint from V3, but **do not add new business endpoints there** — new server
  behaviour goes through a capability. Treat `/papi` as a stable shared surface, not a growth area.

## 7. Incremental modularization only — never a big `shell.js` rewrite

`shell.js` is large (~300 KB, single file). **Do not rewrite, split, or regenerate it as a broad
refactor** — that is high risk for little immediate benefit and can destabilise production.

Instead: **when you are already touching a part of the app, extract that part carefully** into a
logical module/component, keeping existing behaviour identical. Over many normal changes V3 becomes
easier to test and maintain, with no single risky rewrite. Any extraction must keep the sync rule
(§2–3) intact.

## 8. Testing expectations for a V3 change

- **Assets:** run `scripts/v3-assets-sync.sh`; confirm `resources/v3` and `public/assets/v3` are
  byte-identical (`cmp`), and that `scripts/v3-assets-sync-check.sh` passes. CI enforces this.
- **JS:** `node --check resources/v3/shell.js` (and `.css` visually) for syntax before pushing.
- **Backend:** if the change touches a capability/service, run the relevant tests
  (`php artisan test tests/Unit/Kernel tests/Feature/Kernel` is the money gate; add capability tests
  where they exist). Financial writes follow the verification bar in the root `CLAUDE.md`.
- **Live:** the V3 SPA cannot be exercised in the sandbox — a UI change needs a click-test on the
  deployed `/v3` panel. State that plainly in the PR; do not claim a UI change "works" without it.
- **Diff hygiene:** confirm `git diff` contains **no V2 changes** and that `shell.js` was not
  wholesale rewritten (a feature change should be a focused diff, not a re-emit of the file).

---

*Companion docs: `V3-DEFAULT-MIGRATION-PLAN.md` (strategy), `docs/v3-migration/CONSTITUTION.md`
(migration rules), `docs/v3-migration/MIGRATION-TRACKER.md` (per-module status).*
