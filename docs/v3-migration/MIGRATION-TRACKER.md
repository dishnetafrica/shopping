# CloudBSS V3 Migration Tracker

**The single source of truth for the Panel → V3 migration (Constitution · Rule 9).**
Update this file whenever a module's status changes.

## Legend

- ✅ **Done** — real V3 home exists; safe to retire the legacy page (per Rule 10).
- 🚧 **Building** — partly in V3, not yet at full parity.
- 🟡 **Verify** — a V3 home appears to exist; needs a hands-on parity audit before retiring.
- ❌ **Not started** — no V3 home yet.

This first pass is derived from two authoritative sources in the code: the `ClassicPanelRedirects::MAP`
(a legacy page is only listed there when a *true* V3 home exists) and the V3 capability registry
(`app/Capability/**`). Items marked 🟡 still need a parity check before their legacy page is hidden.

## Status

| Module | Legacy | V3 | Status | V3 home / notes |
|--------|:------:|:--:|--------|-----------------|
| Dashboard | ✅ | ✅ | ✅ Done | `/v3#/briefing` (today) |
| Orders | ✅ | ✅ | ✅ Done | `salesOrders` |
| Customers | ✅ | ✅ | ✅ Done | `customers` (customers360) |
| Products | ✅ | ✅ | ✅ Done | `products` (+ specs, serials, warranty, kits — V3-native) |
| Inventory | ✅ | ✅ | ✅ Done | `warehouse` (inventory-count) |
| Purchasing | ✅ | ✅ | ✅ Done | `purchase.process`, `procurement.hub` |
| Suppliers | ✅ | ✅ | ✅ Done | `suppliers` (+ supplier-debt) |
| Accounting | ✅ | ✅ | ✅ Done | `cashbook`, `finance`, `invoices`, `payments`, `credit-notes` |
| Messaging | ✅ | ✅ | ✅ Done | `chats`, `communication` (email-center) |
| Campaigns | ✅ | ✅ | ✅ Done | `campaigns` (marketing) |
| Leads | ✅ | ✅ | ✅ Done | `leads` |
| Scheduling | ✅ | ✅ | ✅ Done | `scheduled` |
| Consignments | ✅ | ✅ | ✅ Done | `consignments` |
| Ads | ✅ | ✅ | ✅ Done | `ads` |
| Guide | ✅ | ✅ | ✅ Done | `guide` |
| Settings | ✅ | ✅ | 🟡 Verify | `settings` (setup/staff/system route here — confirm full parity) |
| Reports | ✅ | 🚧 | 🟡 Verify | `finance`, `pnl-report` cover much; confirm dedicated reports parity |
| Business Profile | ✅ | 🚧 | 🟡 Verify | likely under `settings`; confirm it's a first-class V3 screen |
| WhatsApp (connect/QR) | ✅ | 🚧 | 🟡 Verify | chats live; confirm number connect/QR + channels in V3 |
| Team | ✅ | 🚧 | 🟡 Verify | staff routes to `settings`; confirm a first-class Team screen |
| Roles / Permissions | ✅ | ❓ | ❌ Not started | no dedicated V3 home identified yet |
| Billing | ✅ | ❌ | ❌ Not started | `/panel/billing` has no V3 redirect home yet |
| Module Manager | ✅ | ❓ | ❌ Not started | `/panel/settings-manager`; no V3 home in the redirect map |

## Onboarding

| Item | Status | Notes |
|------|--------|-------|
| New tenant lands in V3 | ✅ Done | `Tenant::booted()` defaults `order_system_enabled=true`; self-serve signup already redirects to `/v3` |
| Legacy page auto-redirect | 🚧 Per-tenant | `v3_redirects` (default off) + `php artisan v3:retire {tenant}` |

## Next actions

1. **Firm up the 🟡 rows** — a hands-on parity audit of Settings, Reports, Business Profile, WhatsApp
   connect, and Team, so each can move to ✅ (or drop to 🚧/❌ honestly).
2. **Start the ❌ rows** — Billing, Roles/Permissions, Module Manager are the true remaining gaps.
3. Per module, follow Constitution Rule 5 (audit → build → parity → route → hide → delete) and Rule 10
   (Definition of Done) before retiring the legacy page.
