# S1c-3 · Specs on documents (snapshot at creation) — upload to GitHub

Completes Product Specifications. When an invoice or quotation line is created, the line's product
specs are **frozen onto the line** (resolved by SKU on invoices, by name on quotes), and shown on the
**invoice** as a compact grey sub-line under the item (e.g. `550 W · LiFePO4 · IP65`).

## What to do
Upload everything here, keeping the folders. (S1a, S1b, S1c-1, S1c-2 already uploaded.)

## How you'll know it worked
- Green ✓ "Money kernel (must pass)" — the snapshot + render tests run inside that check.
- Create an invoice whose line matches a specced product (by SKU) → the specs print under the item.
  Old/already-issued invoices are unchanged.

## How it works (and why it's safe)
- **One observer** (`LineSpecSnapshotObserver`) snapshots specs onto invoice AND quote lines at
  creation — so none of the five document-creation paths were edited.
- The snapshot is **frozen**: a document keeps the specs as they were when issued (a reissued or
  historical invoice never silently changes). Guarded so it can never block document creation.
- Additive nullable column (`specs_summary`) on invoice/quote lines; lines with no product match or no
  specs stay null → those documents look exactly as before.

## What's inside
- migration `…add_specs_summary_to_document_items` — the nullable column on both line tables.
- `app/Observers/LineSpecSnapshotObserver.php` + registration in `CapabilityServiceProvider.php`.
- `ProductSpecService.php` — new `summaryForProduct()` compact-summary helper.
- `InvoiceItem.php` / `QuotationItem.php` — `specs_summary` fillable.
- `InvoiceRenderer.php` — prints the snapshot as a sub-line under the item (invoices only carry it).
- `tests/Feature/Kernel/DocumentSpecSnapshotTest.php` — snapshot-by-SKU, snapshot-by-name, no-match,
  and invoice render.

## One remaining piece
Quotes now **capture** the snapshot, but the quote PDF pipeline is indirect (a doc-generation service),
so printing specs on the quote PDF is a small follow-up (S1c-3b) once I trace that path — nothing is
lost, the data is already frozen on each quote line.

## Safety
Additive throughout. Rollback = revert these files + drop the `specs_summary` column.
