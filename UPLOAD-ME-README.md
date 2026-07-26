# S7b — the Warranty Claims screen in V3

This puts **Warranty Claims** on screen in CloudBSS V3, completing S7. A **Warranty Claims** item
appears in the V3 sidebar (under **Operations**).

## What you can do from V3

- **See every claim** with its status (Open / Approved / Rejected / Resolved) and — crucially — whether
  the warranty is **in coverage** (Active / Expired / Void), so you can decide correctly at a glance.
  KPI tiles show open / approved / rejected, with filters by status and coverage.
- **Open a new claim** with the "New claim" button — pick the warranty (the claim inherits its serial &
  customer) and describe the fault.
- **Open a claim** to decide it: **Approve** (repair / replace / refund + notes), **Reject** (with a
  reason), or **Mark resolved** once an approved claim is done.

Built the V3-native way (the sidebar builds itself from the capability registry); every write re-checks
owner/manager permission on the server.

With this, **Warranty Claims are fully done** — end-to-end from Phase 1's warranties.

---

## ⚠️ Upload S7a first

S7b is the screen on top of the **S7a** engine. Upload S7a and see it green before this. As a safety
net, this zip re-includes the S7a claims engine (byte-identical), so nothing breaks if part of S7a
didn't upload cleanly.

## Files in this zip

**S7b (the screen)**

- `app/Capability/Process/WarrantyClaimListCapability.php` *(new)* — the claims list
- `app/Capability/Object/WarrantyClaimCapability.php` *(new)* — one claim (approve / reject / resolve)
- `app/Capability/Registry/CoreCapabilityProvider.php` *(replace)* — registers both
- `resources/views/shell.blade.php` *(replace)* — Warranty Claims in the V3 sidebar
- `tests/Feature/Kernel/WarrantyClaimWorkspaceTest.php` *(new)* — proves the screen
- `docs/v3-migration/MIGRATION-TRACKER.md` *(replace)* — Warranty Claims now ✅ Done

**S7a engine — safety net (identical to S7a)**

- `app/Models/WarrantyClaim.php`, `app/Kernel/Events/WarrantyClaimEvents.php`,
  `app/Services/Warranty/WarrantyClaimService.php`,
  `app/Http/Controllers/Panel/WarrantyClaimController.php`, the `warranty_claims` migration, and
  `routes/web.php`.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `routes`, `database`, `tests`, `docs`) into your repo at the same
paths. If a new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/WarrantyClaimListCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Object/WarrantyClaimCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/WarrantyClaimWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 — **Warranty Claims** is in the left sidebar
under Operations. Open a claim against a warranty, approve it as "replace," and see the status change.

---

## What's next

The final Phase 2 piece: **S8 — Project Quotes** (multi-section quotes for full solar projects).
