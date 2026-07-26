# Product importer upgrade — multiple photos + auto-fill specifications

This one update teaches your catalogue importer two new tricks, so a single import can bring a product
in **complete**: price, stock, up to **4 photos**, and its **specifications** — no hand-typing afterward.

## 1. Up to 4 photos per product

Your product CSV can now have these optional columns:

- `Gallery 1`, `Gallery 2`, `Gallery 3`

Each holds either a **filename inside the ZIP** (like the `Image` column) or a **direct image URL**.
The main photo still lives in `Image`. Blank cells are ignored.

## 2. Specifications fill in automatically

If a CSV column heading matches one of your product **spec fields**, the importer fills that spec in.
For your solar catalogue that means columns like:

- `Wattage`, `Voltage`, `Current`, `Power Rating`, `Capacity`, `Battery Chemistry`, `Lumens`
  (also `Colour Temperature`, `Cable Length`, `IP Rating`)

So instead of opening each product and typing specs into the "Edit specifications" screen, you put them
in the CSV once and they import with the product.

This is **tenant-generic and safe**: it reads whatever spec catalogue a tenant already has (solar seeds
one), it reuses your existing, tested spec service, it needs **no database change**, and a CSV with no
spec columns imports **exactly as before**. Nothing outside the importer is touched.

## Files in this zip (3)

**Replace**

- `app/Http/Controllers/Panel/PanelApiController.php` — importer now reads `Gallery 1/2/3` and any
  columns matching your spec fields (both the ZIP import and the CSV-only import).

**New**

- `tests/Feature/Kernel/ProductMultiPhotoImportTest.php` — proves the 4-photo import.
- `tests/Feature/Kernel/ProductSpecImportTest.php` — proves specs fill from matching columns, numbers
  are stored for filtering, blanks are skipped, and a no-spec CSV writes no specs.

> This **supersedes** the earlier "multi-photo" bundle — it contains that change plus the spec-import
> change. If you already uploaded the multi-photo one, uploading this simply replaces the same file and
> adds one more test; nothing is lost.

## How to upload (GitHub, branch `main`)

Drag the `app` and `tests` folders into your repo at the same paths (replaces one file, adds two tests).
If a new test doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ProductMultiPhotoImportTest.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ProductSpecImportTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** in GitHub Actions. After that, import a product with the new
columns (use the **solar template** I sent) and it comes in with photos and specs already set.
