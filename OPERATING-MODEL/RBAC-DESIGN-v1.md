# RBAC — Deny-by-Default Design v1

**Status:** DESIGN + dormant Phase-0 code shipped · Constitution v1.0 · P0 Security
**Goal:** a logged-in staff user can perform ONLY what their role allows — enforced at the route, deny-by-default — without a risky big-bang cutover.

---

## 1. Where we are (grounded in the code)

Authorization today is three overlapping, mostly-inert layers:

- **Menu visibility** — `User::effectiveMenus()` decides what the nav SHOWS. Hidden ≠ protected.
- **Action allow-list** — `App\Support\Access::MATRIX` (action → roles, **fails closed** on unknown action). This is good and is our foundation. But its enforcement wrapper `Access::blocks()` only acts when a tenant sets **`perm_enforce` (default false)**, and it's wired into **~10 of ~250** mutating endpoints.
- **Inline ad-hoc checks** — ~117 `isOwnerLike()/in_array($role,[...])` checks across ~30 controllers, with **inconsistent** allowed-sets and gaps (e.g. `addProduct` is gated, `deleteProduct` and `updateProduct` are not).

Consequences (all confirmed):
- The `/papi/*` group (CSRF-exempt) has **auth + tenant only — no role middleware**. A non-owner can directly `GET /papi/delete-product`, `POST /papi/expenses`, `POST /papi/cashbook/add`, `POST /papi/record-payment`, `GET /papi/update-status`, `POST /papi/suppliers`.
- **Unknown/unset role is allowed everything by default** (both layers fail open unless `perm_strict`/`perm_enforce` are switched on).
- Role strings are **un-enum'd** and diverge: `accounts` vs `accountant`, `purchaser` vs `procurement`, and the staff-editor (`normStaffRole`) can't even assign several roles the logic references.
- The v3 command layer collapses every action on a capability to **one menu-level permission**; several capabilities have an **empty permission** (open to any tenant user).

## 2. Target model

Four moves, each building on what exists (Principle Zero — no parallel system):

1. **One role vocabulary** — `App\Support\Roles` (shipped). Canonical set + `normalize()` folding `admin→owner`, `accounts→accountant`, `purchaser→procurement`. Every layer (User, staff editor, Access) reads roles through it.
2. **One action allow-list** — keep `Access::MATRIX` as the single source; **extend** it to cover the sensitive endpoints that have no action yet (below).
3. **One enforcement point** — `App\Http\Middleware\PermissionGate` (shipped, dormant): resolves the route's required Access action from `config/permissions.php` and calls `Access::allows()`. Applied to the whole `/papi` and `/api/v1/workspace` groups → deny-by-default for everything mapped, with an owner/super-admin bypass. This replaces the scattered inline checks over time.
4. **Per-action command permissions** — give v3 commands their own permission key instead of reusing the menu key, and fill the empty-permission capabilities.

Deny-by-default becomes the **global default** (via `permissions.mode`), not a per-tenant opt-in — `perm_enforce`/`perm_strict` are retired once rollout completes.

## 3. Permission catalogue

Existing `Access::MATRIX` actions stay. **Add these in Phase 0** (and update `tests/Unit/AccessTest.php` to include them):

| Action (NEW) | Roles allowed (besides owner) | Module |
|---|---|---|
| `expense.book`    | manager, accountant            | finance |
| `cashbook.write`  | manager, cashier, accountant   | finance |
| `product.delete`  | manager, warehouse             | inventory |
| `product.edit`    | manager, warehouse, product_staff | inventory |
| `order.status`    | manager, sales, field          | sales |
| `supplier.manage` | manager, procurement           | purchases |

`config/permissions.php` already maps the concrete routes to these. Routes → actions live in that config; roles → actions live in `Access::MATRIX`. Two small tables, one purpose each.

## 4. Rollout — safe by construction

The middleware ships in mode **off** (pure pass-through). Nothing changes until you move the global `RBAC_MODE`.

- **Phase 0 — canonicalize (no enforcement).** Wire `Roles::normalize()` into User role reads + the staff editor (replace `normStaffRole`). Extend `Access::MATRIX` (table above) + update `AccessTest`. Register `PermissionGate` and attach it to the `/papi` and `/api/v1/workspace` groups **while `RBAC_MODE=off`** (dormant). Ship. *Zero behaviour change.*
- **Phase 1 — report.** Set `RBAC_MODE=report`. The gate now LOGS (never blocks) every request a real RBAC would deny (`rbac.would_deny` / `rbac.unmapped`). Run for 1–2 weeks. The logs tell you exactly which legitimate accesses the matrix would wrongly deny (fix the matrix) and which routes still need mapping.
- **Phase 2 — enforce the crown jewels.** Once report is quiet, set `RBAC_MODE=enforce`. Deny-by-default is now live for every mapped route — money, deletes, admin first (they're already in the map). Owner/super-admin always pass; a bug fails open. Keep report logs on.
- **Phase 3 — complete + retire opt-in.** Map the remaining sensitive routes, give v3 commands per-action permissions, fill empty-permission capabilities, and delete the `perm_enforce`/`perm_strict` opt-in so deny-by-default is simply the default. Remove the now-redundant inline `isOwnerLike` checks the middleware supersedes.

## 5. Test strategy

- **Deny-by-default endpoint test** — extend `tests/Feature/EndpointAuthTest.php`: with `RBAC_MODE=enforce`, a `staff` user gets 403 on each mapped sensitive route; an allowed role gets through; owner always passes.
- **Route-coverage guard** (like the tenant-isolation test) — a test that every mutating `/papi` route is either in `config/permissions.php` or on an explicit "public/self-serve" allow-list. Fails the build when a new sensitive endpoint is added unmapped — closes the "forgot to gate it" hole permanently.
- **Matrix ↔ config consistency** — assert every action referenced in `config/permissions.php` exists in `Access::MATRIX`.

## 6. Risks & mitigations

- **Locking real users out.** Mitigated by report-first (observe before enforce), owner/super-admin bypass, fail-open on gate errors, and a global kill-switch (`RBAC_MODE=off`).
- **Parameterised routes** (`/papi/suppliers/{id}/pay`). The gate keys on `route()->uri()` (the pattern), not the concrete path — so params match. Named routes are preferred where present.
- **Role divergence.** `Roles::normalize()` is the single fold; Phase 0 aligns the staff editor and Access to it. Decide `accounts`→`accountant` and `purchaser`→`procurement` are permanent (recommended).
- **Public/token endpoints** (`/papi/track`, `/papi/pending`, storefront) stay OUTSIDE the gated groups — the token is their auth. Do not map them.

## 7. Shipped now (dormant) vs needs your decision

**Shipped, safe, dormant** (this bundle): `app/Support/Roles.php`, `app/Http/Middleware/PermissionGate.php`, `config/permissions.php`. All verified (php -l + a decision-matrix simulation). None is wired or active yet.

**Needs your go-ahead** (Phase 0 activation, in the deploy note): register + attach the middleware (dormant), extend `Access::MATRIX` + `AccessTest`, wire `Roles::normalize()`. Then flip `RBAC_MODE=report` when you're ready to observe.
