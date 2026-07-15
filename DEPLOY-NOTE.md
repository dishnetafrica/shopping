# CloudBSS — Ready-made Kits (one-tap bundle → quotation)

Curated bundles a customer drops into a quotation in **one tap** — e.g. a
"Restaurant Starter Kit" (foil + cling + containers + gloves + tissue). Makes a
*set* feel like a hero product, lifts average quote value, and turns curation
into bigger baskets. Reuses the quotation engine and the cart — no new checkout,
no engine/ERP change.

> **Cumulative zip.** These 5 files also contain the "Shop for Every Need"
> merchandising build. If you haven't deployed that yet, this includes it — deploy
> these files and you get both. Same config keys/endpoints; nothing is lost.

## How it works
- A **kit** = name + description + photo + a list of products with quantities.
- On the storefront it shows as a card inside a collection's view ("Ready-made
  kits"), or in **every** collection if left unassigned. The card shows the item
  count and live total (priced from your catalogue).
- Tapping **Add kit to quote** adds every product to the cart — each line
  respecting that product's **MOQ** — and opens the quotation request. Missing or
  out-of-stock lines are skipped automatically; a kit needs ≥2 available items to
  show.

## Files (additive, repo-relative)
1. `app/Http/Controllers/Storefront/StorefrontController.php` — emits `kits` in
   the catalogue feed.
2. `app/Http/Controllers/Panel/PanelApiController.php` — `kitsGet` + `kitsSave`
   (whitelisted, size-capped).
3. `routes/web.php` — authed papi `GET kits`, `POST kits-save`.
4. `resources/storefront/shop.html` — kit cards + one-tap `addKit` (MOQ-aware).
5. `resources/panel/seller.html` — **Ready-made Kits** admin page: name, photo,
   description, which collection to show it in, product lines (with quantity and a
   name autocomplete from your catalogue), active toggle. Reuses `/papi/upload-image`.

## Set one up
Panel → **Ready-made Kits** → **+ Add kit** → name it → pick the collection (or
leave blank for all) → add products by name (autocompletes) with quantities →
upload a photo (optional) → **Save kits**. It appears on the storefront within
~5 min (feed cache).

## Safety
- A kit renders only when it has ≥2 available products, so it never shows broken.
- Quantities honour MOQ; unavailable lines are skipped at add time.
- No migration/schema change (tenant settings). Remove a kit or untick Active to
  hide it.

Verified: `php -l` clean; shop.html + seller.html JS compile; Playwright —
storefront renders kits in the right collection, skips a missing line, prices the
total exactly, and one tap adds MOQ-aware quantities and opens the quotation; the
admin editor adds a kit with lines and saves a correct payload. No console errors.
