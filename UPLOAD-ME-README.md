# Module Manager in V3 (legacy-panel migration)

This migrates the **Module Manager** to CloudBSS V3 — the screen where you turn a tenant's feature
modules on or off. It's the first of the remaining legacy-panel gaps the tracker flagged.

## What you get

A new **Modules** item in the V3 sidebar (under **Settings**). It lists every feature module — Sales,
Purchases, Stock, Money, Reports, Messaging, Communication, Restaurant — with an **On/Off** status and a
one-tap toggle. Turning a module off hides all its screens from the menu (for everyone) until it's
turned back on; turning it on brings them back.

- It reuses the **existing** backend: the tenant's `settings.modules` map — the exact same setting the
  menu and the API guards already read. No new data, no new concepts.
- **Owner-only**: only an owner can flip a module (matches the existing modules.manage rule). Managers
  and staff can view but not change.
- Following the Migration Constitution, the legacy page **`/panel/system` now redirects to the V3
  Modules screen** (for tenants with the `v3_redirects` flag on — the same phased switch used for the
  other retired pages).

---

## ⚠️ Prerequisite

This bundle ships the **cumulative** capability registry (`CoreCapabilityProvider.php`) and V3 shell
(`shell.blade.php`), which include all the Phase 2 screens (Jobs, Maintenance, Warranty Claims). Make
sure **S5b–S8b are already uploaded** (they are, if you've been uploading each drop in order) — this
file supersedes them with the latest, so nothing is lost.

## Files in this zip (8)

**New**

- `app/Capability/Process/ModulesCapability.php` — the Modules screen
- `app/Http/Controllers/Panel/ModuleController.php` — the owner-only toggle API
- `tests/Feature/Kernel/ModulesWorkspaceTest.php` — proves it

**Replace**

- `app/Capability/Registry/CoreCapabilityProvider.php` — registers the Modules screen
- `resources/views/shell.blade.php` — Modules in the V3 sidebar (Settings)
- `app/Http/Middleware/ClassicPanelRedirects.php` — `/panel/system` → V3 Modules
- `routes/web.php` — `/papi/modules` + `/papi/modules/toggle`
- `docs/v3-migration/MIGRATION-TRACKER.md` — Module Manager now ✅ Done

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `routes`, `tests`, `docs`) into your repo at the same paths. If a
new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/ModulesCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/ModuleController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ModulesWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 → **Settings → Modules**, and try toggling a
module off and on (as the owner).

---

## What's next (remaining legacy-panel gaps)

- **Billing** in V3 (plan/subscription — I'd plan-first, it touches payments).
- **Roles & Permissions** in V3 (team access control — plan-first, high-stakes).
