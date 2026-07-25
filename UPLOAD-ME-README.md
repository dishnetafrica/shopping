# S1c-2 · Filter products by spec + spec-sheet download button

Builds on S1c-1. The V3 **Products** list now:
- offers **filter dropdowns for your specs** (Wattage, Battery Chemistry, …) right beside the existing
  Category/Status filters, and
- has a **"Specifications (CSV)"** item in its Export / download menu (the S1c-1 export).

All of it is delivered through the product-list payload — **no change to the V3 front-end**.

## What to do
Upload these two files, keeping the folders. (S1a, S1b, S1c-1 already uploaded.)

## How it behaves
- Spec values ride along as hidden row data, so filtering works with **no extra table columns**.
- A spec filter only appears when it's actually useful (two or more different values among the shown
  products) — so the toolbar never fills with empty dropdowns.
- Only specs marked "filterable" are offered (all solar-seeded specs are, by default).

## How you'll know it worked
- Green ✓ "Money kernel (must pass)" — the new test runs inside that check.
- In the app: open **Products**. With specs set, you'll see spec filter chips; the Export menu now
  lists **Specifications (CSV)**.

## What's inside / safety
- `ProductListCapability.php` — adds hidden spec cells + declares spec filters + one export item.
  The existing columns, KPIs, category drill-through and other actions are unchanged.
- `tests/Feature/Kernel/ProductSpecListFilterTest.php` — proves the filters, hidden cells, and export item.
- Additive; a tenant with no specs sees the list exactly as before. Rollback = revert this one file.

## Next
S1c-3 — specs on quotations & invoices via snapshot-at-creation (planned carefully; it edits document
generation). That completes Product Specifications end to end.
