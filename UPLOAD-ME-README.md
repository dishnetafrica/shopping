# S3b — Warranty terms on the product page

This is the next small, additive slice of the **Asset Lifecycle** extension. It brings a product's
**default warranty policy** onto the product detail page in the app:

- Every serialized product (inverters, batteries, panels, etc.) now shows **Manufacturer warranty**
  and **Company warranty** (in months), and a manager can set them right there with a new
  **"Set warranty terms"** button.
- The product page also shows a live **"Active warranties"** count for that product.
- Ordinary products (bread, groceries — not serialized, no policy) look **exactly the same as before**.

Nothing about accounting, inventory, or the front-end changes. This is all payload-driven, like specs
and serials were.

---

## 4 files in this zip

| File | What it is |
|------|------------|
| `database/migrations/2026_07_25_000007_add_warranty_terms_to_products.php` | **NEW** — adds two nullable columns to `products` |
| `app/Models/Product.php` | **REPLACE** — the two new columns are now fillable + cast to integer |
| `app/Capability/Object/ProductCapability.php` | **REPLACE** — shows the terms, the count, and the "Set warranty terms" button |
| `tests/Feature/Kernel/ProductWarrantyTermsTest.php` | **NEW** — proves it works (part of the green gate) |

---

## How to upload (GitHub, branch `main`)

Drag the **four folders** from this zip (`database`, `app`, `tests`) into your repo on GitHub so they
land at the same paths. GitHub will replace the two existing files and add the two new ones.

### ⚠️ Make sure the two NEW files actually land

New files inside deep folders are the ones that sometimes go missing on upload. If after uploading
you don't see them in GitHub, use these direct "create file at this exact path" links (they pre-fill
the path — just paste the file contents from this zip and commit):

- Migration → `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000007_add_warranty_terms_to_products.php`
- Test → `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/ProductWarrantyTermsTest.php`

---

## How you know it worked

Watch the **Actions** tab. When the run finishes with a **green ✓** on the job
**"Money kernel (must pass)"**, S3b is live and safe. That job runs the new warranty-terms test along
with everything before it.

---

## What's next (not in this drop)

- **S3c** — when a serialized unit is marked *sold*, auto-create its warranty using these default
  terms (so the shop doesn't have to fill it in by hand each time).
- An **"expiring soon"** warranties view (the data/API already exists from S3a).
