# S7a — Warranty Claims (engine)

This adds **warranty claims** — when a customer's covered equipment fails, you open a claim against its
warranty and then approve or reject it. It plugs straight into the warranties from Phase 1.

## How it works

- **Open a claim** against a warranty and describe the fault. The claim automatically inherits the
  warranty's product, serial, and customer — nothing is re-typed.
- **Approve** it with a resolution — **repair**, **replace**, or **refund** — plus notes, or **reject**
  it with a reason. Approved claims can later be **resolved** (closed out once the work is done). Every
  decision is stamped with who decided and when.
- Each claim shows whether the warranty was actually **in coverage** (active) — so an expired warranty
  is obvious at a glance when deciding.

Decisions only act on an **open** claim, so a claim can't be flip-flopped or double-decided by accident.

---

## Files in this zip (8)

**New**

- `database/migrations/2026_07_25_000013_create_warranty_claims_table.php`
- `app/Models/WarrantyClaim.php`
- `app/Kernel/Events/WarrantyClaimEvents.php`
- `app/Services/Warranty/WarrantyClaimService.php`
- `app/Http/Controllers/Panel/WarrantyClaimController.php`
- `tests/Feature/Kernel/WarrantyClaimTest.php`

**Replace**

- `routes/web.php` — adds `/papi/warranty-claims...`
- `docs/v3-migration/MIGRATION-TRACKER.md`

> **Depends on Phase 1 warranties** (S3a). Those are already in your repo from the earlier drops.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`database`, `app`, `routes`, `tests`, `docs`) into your repo at the same paths. If a
new file doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000013_create_warranty_claims_table.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Models/WarrantyClaim.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Kernel/Events/WarrantyClaimEvents.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Services/Warranty/WarrantyClaimService.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/WarrantyClaimController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/WarrantyClaimTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — it runs the new warranty-claim test (open inherits from the
warranty, approve/reject, in-coverage flag) with everything before it.

---

## What's next

- **S7b** — the V3 **Warranty Claims** screen (list by status + open/approve/reject from the UI).
- Then the final Phase 2 piece: **S8 — Project Quotes**.
