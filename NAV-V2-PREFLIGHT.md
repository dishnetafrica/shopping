# NAV-V2-PREFLIGHT.md — "If nav_v2 is enabled today, what exactly breaks?"

Date: 2026-07-18 · Method: static verification of the entire flag path —
config/navigation.php → NavController (menuFor gates, RETIRED_ROUTES, menuIssues) →
`GET /papi/nav` → the seller.html rebuild script (lines ~5426–5453) → every linked
route → seller.html's CSS/#side structure. Fixes included in this zip are marked ✅.

## Bottom line
**Flipping the flag today would have broken discoverability of 7 live screens and
nothing else.** Those 7 are fixed in this zip's `config/navigation.php`. After that,
the flip is low-risk, per-tenant, and instantly reversible (`nav:v2 {id} --off` — the
flag is read live on every request; rollback is one command, no deploy).

---

## 1. Pages affected
Exactly **one page**: `seller.html` (the main shell). Its `<nav>` inside `#side` is
rebuilt from `/papi/nav` groups when `enabled=true`. The 60 satellite panel pages have
their own headers/back-buttons and do not render a sidebar — they are untouched.
Filament (`/app`, Admin) and the storefront are untouched. Blast radius: one file's
sidebar, per tenant.

## 2. Broken links — **NONE**
All **26 routes** referenced by the nav config (items + Cashbook tabs) are registered
in `routes/web.php`. Verified programmatically, zero 404s. Setting-gated items
(`order_system_enabled`) are hidden by `menuFor` with the same predicate as the routes'
own `abort(404)` guards, so the menu can never offer a link that 404s.

## 3. Missing permissions — **NONE**
All 11 `menu` keys used by the config exist in `User::allMenuKeys()`
(dashboard, orders, customers, purchases, products, finance, cash, whatsapp, marketing,
settings, staff — plus `approvals`, now used by the new Approvals item). Role mapping
verified in `effectiveMenus()`: owner-like → all; manager → all minus staff;
purchaser (Jayesh) → dashboard/orders/ordercosts/purchases/cash; product_staff →
dashboard/products; per-user `allowed_menus` overrides win. **QA note**: for the
`purchaser` role the Money group collapses to Cashbook only (no `finance` key) — that
is correct per the role design, but eyeball it on the preview (see §9) so nobody is
surprised.

## 4. Coverage loss — **THE real finding (7 screens, now fixed ✅)**
The legacy seller.html sidebar links 34 panel screens; the nav config covered 27 of the
live ones. Diff:

| Legacy link missing from nav | Verdict | Fix |
|---|---|---|
| `/panel/approvals` (Cost Approvals) | **live screen, orphaned** | ✅ added → Purchases group, `menu: approvals`, gated `order_system_enabled` |
| `/panel/sourcing` | **orphaned** | ✅ added → Purchases |
| `/panel/supplier-debt` | **orphaned** | ✅ added → Purchases, gated |
| `/panel/partners` | **orphaned** | ✅ added → Money |
| `/panel/audit` (Accounting Controls) | **orphaned** | ✅ added → Money |
| `/panel/scheduled` (Scheduled messages) | **orphaned** | ✅ added → Messaging |
| `/panel/ads` (Ads console) | **orphaned** | ✅ added → Messaging |
| `/panel/cashbook`, `/panel/book-cost`, `/panel/books`, `/panel/invoiced`, `/panel/ledger` | **retired BY DESIGN** (`NavController::RETIRED_ROUTES` — merged/duplicate screens; deep links still resolve) | none — this removal is the point |

Post-fix diff re-run: **full coverage** — every non-retired legacy link now exists in
the grouped nav. Duplicate-label check: clean. Retired-route check: clean
(`menuIssues()` = []).

## 5. Missing icons — **NONE**
Every item (including the 7 additions) carries an emoji icon; the rebuild script
renders `it.icon` verbatim. No icon font/asset dependency, nothing to load.

## 6. Layout regressions — **none expected**, verified statically
- The rebuild targets `document.querySelector('#side nav')` — `#side` exists and
  contains a `<nav>`. If the selector ever misses, `build()` returns false and the
  legacy menu stays (graceful no-op by construction).
- The generated markup uses classes `.navsec`, `.nav`, `.i` — **all three have style
  rules in seller.html already** (the markup was written for this stylesheet). The new
  sidebar renders styled, not naked.
- Fetch failure / non-JSON / `enabled=false` → silent catch → legacy menu. The OFF
  path is a literal no-op.
- **Active-state highlighting**: the rebuilt links don't mark the current page — but
  neither does the legacy menu (0 `active`-class references). No regression; noted as
  a nice-to-have.

## 7. Module ordering
Rendered top-to-bottom from config: Home → Sales → Purchases → Stock → Money → Reports
→ Messaging → Communication → Settings. Sensible daily-work order (transactional first,
admin last). Two notes for QA taste, not correctness: (a) Stock's "Deliveries" item
points at `/panel/operations` — label vs destination mismatch worth a rename decision;
(b) Settings has two items both routing to `/panel/setup` (Business profile, WhatsApp) —
intentional (same screen, two entries) but shows as two highlighted rows for one page.

## 8. Responsive / mobile
The rebuild replaces only the `<nav>` innerHTML — the mobile drawer mechanics (the one
`@media` rule touching `#side`, and the open/close toggle) live outside `<nav>` and
survive untouched. Longer groups mean a taller menu on phones; `#side` scrolls. Risk:
low. The separate mobile landing (`/panel/m`) is unaffected.

## 9. Rollout plan (per-tenant, reversible)
This zip adds **`php artisan nav:v2 {tenant}`** — no panel toggle on purpose (it's a
rollout flag, not a preference). The command:
1. runs the config self-audit (`menuIssues`) and **refuses to enable** if it fails;
2. prints the exact menu **every role on that tenant** will see (items per group,
   with the tenant's real module flags + `order_system_enabled`) — QA in the same
   breath as the flip;
3. flips the setting. `--dry` previews without flipping; `--off` is the instant
   rollback (flag is read live per request — no deploy, no cache).

Sequence: `nav:v2 1 --dry` → eyeball each role's line (especially purchaser/field) →
`nav:v2 1` → owner clicks through all 9 groups on desktop + phone → leave legacy code
path in place for ≥1 week → repeat per tenant. "Remove legacy sidebar" (the reviewer's
Sprint 1 step 2) should wait until every tenant is flipped — the dead branch costs
nothing meanwhile.

## 10. Estimated fixes
| Item | Effort | Status |
|---|---|---|
| 7 orphaned screens → nav config | small | ✅ done in this zip |
| Flip/rollback/preview tooling | small | ✅ done (`nav:v2`) |
| Active-page highlight in rebuilt sidebar | ~10 lines JS | optional, post-flip |
| "Deliveries"/operations label decision, double `/panel/setup` entry | trivial config edits | QA-taste, post-flip |
| Runtime QA (click-through per role, mobile drawer) | 1–2 hours human | required before each tenant flip |

**Answer to the reviewer's question**: after this zip, the list is not just manageable —
it's done, minus the human click-through. Enabling `nav_v2` for `fs` is now a
one-command change with a one-command rollback.
