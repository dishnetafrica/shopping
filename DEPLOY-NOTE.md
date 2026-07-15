# CloudBSS — Hide & Reorder Categories from v3

Two additive fixes so category order and visibility set in the admin panels
actually take effect on the storefront. No engine changes, mobile unchanged,
no new routes.

## 1. Hide a department from the storefront  (NEW capability)
`Category.active` existed and was toggleable in the `/app` (Filament) panel, but
the storefront never read it — so the toggle did nothing. Now the storefront
catalogue feed honours it:

- A category set **Active = off** disappears from the storefront: its chip AND
  all its products drop out of browsing and search.
- Products stay live in the backend — quotations, orders and direct product
  links are unaffected. It's a storefront-visibility switch, not a delete.

Where to toggle: `/app` admin -> Categories -> edit the category -> Active
toggle. Takes effect on the next storefront load (the filter runs post-cache,
so no 5-minute wait for hide/show).

File: `app/Http/Controllers/Storefront/StorefrontController.php`
 - catalogue(): inactive categories' products filtered out; `category_order`
   now emits active categories only.

## 2. Reorder from the seller panel now reaches the storefront  (BUG FIX)
The `/panel` seller UI has left/right arrows to reorder categories, but they
saved only to a tenant setting the storefront ignores. Now the reorder also
writes `categories.sort` — the column the storefront reads — so arrow reorder
moves departments on the live store. (The `/app` Filament Sort field already
wrote this column and continues to work.)

File: `app/Http/Controllers/Panel/PanelApiController.php`
 - categoryReorder(): now also calls new `writeCategorySort()` to mirror the
   order into `categories.sort` (listed categories by position; unlisted pushed
   to the bottom; missing rows created so empty categories can be ordered).
 - categoryDelete(): also removes the metadata `categories` row (only reached
   when the category is empty) so it doesn't linger in the storefront order.

## Where to do what (summary)
- Reorder departments: `/panel` arrows  OR  `/app` -> Categories -> Sort.
- Hide/show a department: `/app` -> Categories -> Active toggle.
  (The seller `/panel` has no hide toggle yet — easy follow-up if you want it
  there too.)

## Safety
Storefront config is `Cache-Control: no-store`; the product feed caches 5 min,
but the hide filter and category order run outside that cache, so visibility
and order changes show on the next load. Backward compatible: tenants with no
inactive categories and no sort data behave exactly as before.

Verified: `php -l` clean on both files; reorder-sort, hide-filter and
active-only-order algorithms unit-tested.
