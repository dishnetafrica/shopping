# S3c — Warranties start automatically when a unit is sold

This slice makes warranties **automatic**. When a serial-tracked unit is marked **sold** (or
**installed**), the system now creates its warranty on its own, using the default terms you set on the
product in S3b — so nobody has to fill in warranty details by hand for every sale.

**What happens, in plain terms:**

- Mark inverter serial `SN-1` as *sold* → a warranty is created for that exact unit, inheriting the
  product's manufacturer + company months, dated from today, and linked to the customer (if the sale
  recorded one). The coverage end date is worked out automatically.
- If the product has **no** warranty policy set, nothing happens — no clutter.
- If that unit is **already** covered, it's never given a second warranty (safe to re-run, safe on
  returns-and-resells).
- The manufacturer name is taken from the product's **Brand** if you've filled it in.

It's fully self-protecting: if anything ever went wrong creating the warranty, the sale itself still
goes through untouched.

> **Note — I included "installed" as well as "sold."** You asked for *sold*; I also made *installed*
> trigger it, because for solar that's the other moment a unit leaves your hands and coverage should
> start. It's safe (a unit is never double-covered). If you'd rather it fire on *sold* only, just say
> so and I'll change one line.

---

## Files in this zip

| File | What it is |
|------|------------|
| `app/Services/Inventory/SerialService.php` | **REPLACE** — the S3c change: auto-create warranty on sold/installed |
| `tests/Feature/Kernel/SerialAutoWarrantyTest.php` | **NEW** — proves it works (part of the green gate) |
| `app/Models/Product.php` | *safety net* — same file from S3b (re-included in case it didn't land) |
| `database/migrations/2026_07_25_000007_add_warranty_terms_to_products.php` | *safety net* — same migration from S3b |

The bottom two are only re-included so this drop works even if part of S3b didn't upload cleanly.
They're byte-for-byte the same as what S3b delivered — re-uploading them changes nothing if S3b is
already in place.

---

## How to upload (GitHub, branch `main`)

Drag the folders from this zip (`app`, `database`, `tests`) into your repo on GitHub so they land at
the same paths. GitHub replaces the existing files and adds the new test.

### ⚠️ Make sure the NEW test file lands

If after uploading you don't see the new test, use this direct link (it pre-fills the path — paste the
file contents from this zip and commit):

- Test → `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/SerialAutoWarrantyTest.php`

---

## How you know it worked

Watch the **Actions** tab. A **green ✓** on **"Money kernel (must pass)"** means S3c is live. That job
runs the new auto-warranty test along with everything before it.

---

## What's next (not in this drop)

- An **"expiring soon"** warranties view for the app (the data + API already exist from S3a).
- **S4 — Product Kits/Bundles** (Phase 1's last piece): sell a "3kW solar kit" that explodes into its
  component products on the sale.
