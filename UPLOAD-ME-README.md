# S4a — Kits / Bundles (the engine)

This is the foundation for **kits**: a product that's really a bundle of other products — e.g. a
**"3kW Solar Kit" = 1 inverter + 8 panels + 4 batteries**.

This slice is deliberately **safe and additive** — it changes nothing in your working sale flow. It
teaches the system what a bundle is made of, shows a bundle's parts on its product page, and can build
bundles from a **CSV** (the option you chose). Selling a kit and having it draw down the parts is the
*next* step (S4b), which I'll plan and get your OK on before touching the sale engine.

## How you'll build a kit (CSV)

1. In the app, download the starter sheet from **`/papi/kits/template`** (or just make a sheet with
   these columns): **Kit SKU, Kit Name, Part SKU, Part Name, Quantity**.
2. One row per part. Example:

   | Kit SKU | Part SKU | Quantity |
   |---------|----------|----------|
   | KIT-3KW | INV | 1 |
   | KIT-3KW | PNL | 8 |
   | KIT-3KW | BAT | 4 |

3. Import it (owner/manager only). It matches products by **SKU** (falls back to the product name),
   groups the rows by kit, and saves each kit's parts. Re-importing a kit cleanly replaces its parts.
   Unknown SKUs are reported, not guessed. A first pass runs as a **preview** (nothing saved) unless
   you confirm/apply.

Once imported, open the kit's product page and you'll see its parts listed under **Kit contents**, plus
a **"Bundle of N parts"** tile.

---

## Files in this zip (10)

**New**

- `database/migrations/2026_07_25_000008_create_kit_components_table.php`
- `database/migrations/2026_07_25_000009_add_is_kit_to_products.php`
- `app/Models/KitComponent.php`
- `app/Kernel/Events/KitEvents.php`
- `app/Services/Catalog/KitService.php`
- `app/Http/Controllers/Panel/KitController.php`
- `tests/Feature/Kernel/KitTest.php`

**Replace**

- `app/Models/Product.php` (adds the `is_kit` flag + a kit-parts relation)
- `app/Capability/Object/ProductCapability.php` (shows a kit's contents on its page)
- `routes/web.php` (adds the `/papi/kits...` routes)

---

## How to upload (GitHub, branch `main`)

Drag the folders (`database`, `app`, `routes`, `tests`) into your repo so they land at the same paths.
GitHub replaces the three existing files and adds the seven new ones.

### ⚠️ Make sure the NEW files land

New files in deep folders are the ones that sometimes go missing on upload. If any don't appear, use
these direct "create file at this exact path" links (paste the file's contents from this zip, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000008_create_kit_components_table.php`
- `github.com/dishnetafrica/shopping/new/main?filename=database/migrations/2026_07_25_000009_add_is_kit_to_products.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Models/KitComponent.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Kernel/Events/KitEvents.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Services/Catalog/KitService.php`
- `github.com/dishnetafrica/shopping/new/main?filename=app/Http/Controllers/Panel/KitController.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/KitTest.php`

---

## How you know it worked

Watch the **Actions** tab. A **green ✓** on **"Money kernel (must pass)"** means S4a is live. That job
runs the new kit test with everything before it.

---

## What's next (not in this drop)

- **S4b** — selling a kit actually draws down the parts on the invoice/quote (touches the sale engine;
  I'll plan it and get your OK first).
- **Preassembly** — build a batch of kits ahead of time (parts down, kit stock up).
