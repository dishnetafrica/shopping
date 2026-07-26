# S8b — the Project Quote builder in V3 (Phase 2 complete)

This makes project quotes clickable in CloudBSS V3 — and it's the **final piece of Phase 2**. There's
no new screen to learn: it enhances your existing **Quotations** screen.

## What you can do from V3

- On the **Quotations** screen, a **"New project quote"** button appears (once you have at least one
  kit). Pick a system/kit and enter the customer — it builds a full sectioned quotation through the
  normal quote engine (priced, numbered, PDF, WhatsApp — all as usual).
- Open that quotation and instead of a flat line list you see **one card per section** (e.g. "3kW Solar
  Kit") with each section's **subtotal**, then the grand total. Ordinary quotes are unchanged.

No new sidebar item, no new capability — it rides on the Quotations screen you already have, so there's
nothing extra to find.

---

## ⚠️ Upload S8a first

S8b is the UI on top of the **S8a** engine. Upload S8a and see it green before this. As a safety net,
this zip re-includes the S8a engine (byte-identical), so nothing breaks if part of S8a didn't upload
cleanly. (Also needs S4 kits, already in your repo.)

## Files in this zip

**S8b (the UI)**

- `app/Capability/Process/QuotationListCapability.php` *(replace)* — adds the "New project quote" action
- `app/Capability/Object/QuotationCapability.php` *(replace)* — sectioned view on project quotes
- `tests/Feature/Kernel/ProjectQuoteWorkspaceTest.php` *(new)* — proves the action + sectioned view
- `docs/v3-migration/MIGRATION-TRACKER.md` *(replace)* — Project Quotes now ✅ Done

**S8a engine — safety net (identical to S8a)**

- `app/Services/Sales/ProjectQuoteService.php`, `app/Http/Controllers/Panel/ProjectQuoteController.php`,
  and `routes/web.php`.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `routes`, `tests`, `docs`) into your repo at the same paths. If the new test
doesn't appear, use this link (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ProjectQuoteWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 → **Quotations** → click **New project quote**,
pick a kit and a customer, and open the resulting quote to see the sections and subtotals.

---

## 🎉 Phase 2 is complete

Installation Work Orders, Service Visits (+ recurring maintenance), Warranty Claims, and Project Quotes
are all live in V3 — on top of Phase 1 (specs, serials, warranties, kits). The Asset Lifecycle
extension now covers the full solar journey: quote → sell → install → warranty → service → claim.
