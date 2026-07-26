# CloudBSS V3 Migration Constitution

**Status: ADOPTED — architecture freeze in effect.**
**Applies to: every future change to this repository.**

This is the governing document for the frontend migration. Any session (human or Claude) working on
this codebase must follow it. It supersedes ad-hoc decisions about "where should this go."

---

## Decision (Architecture Freeze)

Effective immediately, **stop building features into the existing Panel.**

- The existing `/panel` application is now classified as **Legacy UI (V2)**.
- All new development targets **CloudBSS V3** (`/v3`).
- V3 is **the product**, not a redesign of the panel.
- The legacy panel exists only to support migration until every capability has moved.

---

## The Rules

**Rule 1 — Ask the right question.** Never "Where does this go in Panel?" Always "How does this fit
into V3?"

**Rule 2 — Everything migrates.** Every panel module moves to V3: Dashboard, Orders, Customers,
Products, Inventory, Purchasing, Suppliers, Accounting, Messaging, WhatsApp, Campaigns, Leads,
Scheduling, Team, Roles, Billing, Reports, Business Profile, Settings, Module Manager. Nothing is left
behind. Track it in `MIGRATION-TRACKER.md`.

**Rule 3 — One active version.** Once a feature exists in V3, the Panel equivalent becomes hidden,
read-only, or redirects into V3. Never maintain two active versions.

**Rule 4 — New tenants start in V3.** `Create Tenant → Launch V3 → onboard → never visit Legacy`.
(Implemented: `Tenant::booted()` defaults `order_system_enabled = true` for every new tenant;
`LandingController` sends those owners to `/v3`. Self-serve signup already lands on `/v3`.)

**Rule 5 — Migration strategy, per module.** (1) Audit legacy → (2) Design V3 → (3) Build V3 →
(4) Verify feature parity → (5) Switch routing → (6) Hide legacy page → (7) Delete legacy code when
safe.

**Rule 6 — No duplicate development.** Fix bugs once, in V3. Patch legacy only when business-critical.
Legacy receives maintenance only — no enhancements, redesigns, or new workflows.

**Rule 7 — Navigation.** Eventually `mycloudbss.com` opens V3, not `/panel`. The panel becomes "Legacy,"
reachable only for migration/support.

**Rule 8 — Shared backend.** The backend stays shared; only the UI changes. V3 keeps using the existing
APIs (`/papi/*`), events (BOS spine), database, authentication, permissions, queue, and services until
individual services are modernized. Do not rewrite working backend systems just because the UI changes.

**Rule 9 — Migration tracker is the single source of truth.** See `MIGRATION-TRACKER.md`.

**Rule 10 — Definition of Done.** A module is complete only when: every legacy capability exists in V3,
UX is improved, mobile-first, tested, routed from V3, legacy page hidden, and docs updated. Only then
may the legacy version be retired.

---

## Final Principle

> **CloudBSS V3 is now the product. The current Panel is only a temporary migration bridge. Every
> change should reduce the legacy footprint. No new customer should ever build their business around
> the old Panel again.**

---

## How this is already wired in the code (existing V3-DEFAULT-MIGRATION-PLAN, Phases A–D)

The migration is already underway. These are the real, reversible switches — use them, don't reinvent:

- **Land on V3** — per-tenant setting `order_system_enabled`. When true, `LandingController` redirects
  owners (and staff, per `v3_all_roles`) from `/panel` and `/panel/m` to `/v3`. **Now defaulted true
  for every new tenant** (Rule 4).
- **All roles on V3** — setting `v3_all_roles` (default true). `php artisan v3:staff {tenant} [--off]`.
- **Retire legacy pages** — setting `v3_redirects` (default OFF). When on, `ClassicPanelRedirects`
  302-redirects legacy page routes to their `/v3#/{workspace}` homes. `php artisan v3:retire {tenant}
  [--off]`. The redirect MAP is the authoritative "V3 home exists" list (see the tracker).
- **Escape hatch** — `?classic=1` bypasses every redirect for deep-links and support. Kept through the
  migration, removed in the final phase.

All switches are per-tenant and flip at runtime with no deploy — a tenant can be moved to V3 or rolled
back instantly.
