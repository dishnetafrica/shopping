# S1c-1 · Spec-sheet export — upload to GitHub

Adds a **separate** product specifications export (products × their spec values) as a CSV. It is kept
apart from your round-trip catalogue CSV, so the export → edit → re-import flow is untouched.

## What to do
Upload these files, keeping the folders. (S1a + S1b already uploaded.)

## How to use it
Download URL (owner/manager): **`/api/v1/doc/spec-sheet`** — returns
`Specifications-<Business>-<date>.csv`. One row per product, one column per spec (Wattage (W),
Battery Chemistry, IP Rating, …). A download button in the product screen comes with S1c-2.

## How you'll know it worked
Green ✓ "Money kernel (must pass)" — the export test runs inside that check.

## What's inside / safety
- `CatalogExport.php` — new `specSheetCsv()` method (the existing catalogue CSV is unchanged).
- `DocumentController.php` — new `specSheet()` download action (mirrors the products export).
- `CapabilityServiceProvider.php` — one new route: `GET /api/v1/doc/spec-sheet`.
- `tests/Feature/Kernel/ProductSpecExportTest.php` — proves columns + values export.
- Additive only; nothing existing changed. Rollback = revert these files.

## Next
S1c-2 (filter products by spec in the list, + a download button for this export), then
S1c-3 (specs on quotes & invoices via snapshot-at-creation).
