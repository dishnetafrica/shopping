# CloudBSS — Category Order Reseed (one-shot, food-first)

Reorders an EXISTING tenant's storefront departments to food-first without a
re-import. Pairs with the storefront department-ordering patch (which makes the
storefront read `Category.sort`).

## What it is
`POST /papi/category-reseed` — authed panel endpoint (same auth + tenant
context as `/papi/quotation-intel`; papi/* is CSRF-exempt). It only UPDATES the
`sort` column of the tenant's existing categories. It never creates, renames,
deletes a category, and never touches products. Idempotent — re-running
re-applies the same order.

## Files (additive, repo-relative)
1. `app/Http/Controllers/Panel/CategoryOrderController.php`  (new)
2. `routes/web.php`  — one line added in the authed papi group:
   `Route::post('category-reseed', [CategoryOrderController::class, 'reseed']);`

## How to run (once, after deploy)
Logged in as the tenant owner (so the session cookie is present), from the
panel/browser console on familyshoppers.net:

    fetch('/papi/category-reseed', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'})
      .then(r=>r.json()).then(console.log)

With an empty body it applies the canonical Family Shoppers food-first order:
Whole Spices -> Ground Spices -> Recipe Mixes & Masalas -> Dal & Pulses ->
Grains/Flours/Staples -> Snacks & Namkeen -> Pickles/Chutneys/Pastes ->
Sauces & Condiments -> Rice/Biryani/Ready Mixes -> Disposables/Foil/Wraps ->
Cleaning & Hygiene -> Non-Food & General -> Flooring & Paint (Industrial).

Any department NOT in the list keeps its relative order but is pushed below the
listed ones (non-food/legacy settle at the bottom).

To use a custom order, pass `{ "order": ["Cat A","Cat B", ...] }`.

### Response
    { "ok": true, "reseeded": 14, "order": [{"name":"Whole Spices","sort":0}, ...],
      "missing": [] }
`missing` lists any requested names with no matching category (typo / not
imported) — expect `[]` if the taxonomy matches.

## Safety / rollback
Only `categories.sort` changes. Storefront config is `Cache-Control: no-store`
and the catalogue feed caches 5 min, so the new order shows within 5 minutes.
To revert, call again with your previous order, or ignore it — the storefront
falls back to product-count sort only when `category_order` is empty.

Verified: `php -l` clean on both files; reorder algorithm unit-tested
(food-first applied, unlisted pushed to bottom).
