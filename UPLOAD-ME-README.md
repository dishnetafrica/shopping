# Sell-mode toggle on the product edit screen

Lets your team switch a product between **"Buy now"** and **"Request a quote"** right from the product edit
dialog — no more editing a CSV and re-importing just to change how one product sells.

## What you'll see

Open a product in your dashboard (**Products → tap a product**). On solar (and manufacturer) shops the edit
dialog now has a new field:

> **How customers buy this**
> - **Buy now** — add to cart & checkout (everyday items)
> - **Request a quote** — customer enquires, you send a proposal (big systems & kits)

Pick one, hit **Save changes**, and it takes effect on the storefront immediately: "Buy now" products show
the normal add-to-cart button; "Request a quote" products show a *Request a quote* button that funnels the
customer to your team instead.

Products set to quote also get a small **📋 Quote only** tag on their card in the product list, so you can see
at a glance which items are quote-based.

The field only appears for shops where it makes sense (solar and equipment/manufacturer verticals). Grocery,
restaurant and snack shops don't see it, and their product edits are completely unaffected.

## Files in this zip

**Changed**

- `resources/panel/seller.html` — the product edit dialog gains the "How customers buy this" toggle, pre-set
  to the product's current mode; the product card shows a "Quote only" tag.
- `app/Http/Controllers/Panel/PanelApiController.php` — the products list now returns each product's sell mode
  (so the dialog can pre-select it); the save endpoint already accepted the value.

**Included for safety (no-ops if you already have them)**

- `app/Models/Product.php` — `sell_mode` is a fillable field.
- `database/migrations/2026_07_26_000001_add_sell_mode_to_products.php` — adds the `sell_mode` column
  (guarded — it skips itself if the column already exists).

**New test**

- `tests/Feature/Kernel/ProductSellModeEditTest.php` — proves the toggle switches the mode both ways, that a
  bad value falls back to "buy", that editing other fields never changes the mode, and that the products list
  exposes the mode.

## How to upload (GitHub, branch `main`)

Drag the `app`, `database`, `resources`, `tests` folders into your repo at the same paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (the new test runs there). Then open a solar product in your
dashboard — you'll see the "How customers buy this" toggle. Switch one to "Request a quote", save, and open
that product on your storefront: it now shows a *Request a quote* button instead of add-to-cart.
