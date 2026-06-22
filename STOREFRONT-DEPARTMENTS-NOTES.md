# Supermarket storefront — Department → Sub-category → Products

## The problem this solves

Grocery shoppers don't scan a flat list of 30–80 categories; they navigate by **department first**
("Grocery & Kitchen" → "Atta, Rice & Dal" → rice). A flat category grid works at 50 products and
collapses at 500 / 1000 / 5000. This adds the department layer so browsing scales with the catalogue
and matches how people actually shop (Blinkit / Instamart / Zepto / Carrefour / Lulu).

## What it is — a presentation + config layer (NO schema change)

Products keep their existing `category`. A per-tenant **`category_groups`** setting maps those
categories into departments:

```json
{
  "Grocery & Kitchen": ["Vegetables & Fruits","Atta, Rice & Dal","Oil, Ghee & Masala","Dairy, Bread & Eggs"],
  "Snacks & Drinks":   ["Chips & Namkeen","Sweets & Chocolates","Drinks & Juices"],
  "Beauty & Personal Care": ["Bath & Body","Hair Care","Skin Care"],
  "Household Essentials":   ["Cleaning","Kitchen Supplies","Home Care"]
}
```

The grocery home then renders each department as a heading + a grid of its sub-category tiles. Tapping a
tile opens that sub-category's products (existing category view), with sibling tiles as quick chips to
move within the department.

## Scope — supermarket only, everything else untouched

The department layout activates **only** when both are true:
1. the tenant's `vertical` is **grocery**, AND
2. it has a **`category_groups`** map with at least one real (product-backed) department.

So:
- **Restaurants & snacks** — excluded by vertical. Unchanged.
- **Electronics / hardware / pharmacy / fashion** — these are grocery-vertical-by-default but will
  simply have **no `category_groups`** configured, so they keep today's flat category grid. Unchanged.
- **Supermarkets** — the admin configures department groups → they get the department UX.

No config anywhere = byte-for-byte the current storefront. The flat tile and the department tile share
one `catTile()` renderer, so tiles look identical; only the grouping differs.

Safety built in: sub-categories with no products are dropped from a department, and any category not
placed in a department falls into an automatic **"More"** department — a category can never disappear.

## Files

**Edited**
- `app/Http/Controllers/Storefront/StorefrontController.php` — `category_groups` added to the catalogue feed.
- `resources/storefront/shop.html` — `deptModel()` / `useDepartments()` / `deptOf()` / `groceryHome()` / shared `catTile()`; `render()` branches to the department home for grocery; sibling-chip nav + sub-chip CSS.

**New**
- `qa/storefront_departments.php` — **11/11 green** (activation gate per vertical, grouping, the "More" bucket never dropping a category, empty-sub drop, `deptOf`).

**Visual reference**
- `grocery-storefront-prototype-v2.html` (already shared) — the exact department → sub-category UX this implements.

## Configure a supermarket (until the panel editor lands)

On the EasyPanel app container:

```bash
php artisan tinker <<'PHP'
$t = \App\Models\Tenant::withoutGlobalScopes()->where('slug','family-shoppers')->first();
$t->putSetting('category_groups', [
  'Grocery & Kitchen' => ['Vegetables & Fruits','Atta, Rice & Dal','Oil, Ghee & Masala','Dairy, Bread & Eggs'],
  'Snacks & Drinks'   => ['Chips & Namkeen','Sweets & Chocolates','Drinks & Juices'],
  'Household Essentials' => ['Cleaning','Home Care'],
]);
echo "set\n";
PHP
```

Use your **real** category names (run through your product categories). Names that don't match are
ignored; anything you don't list shows under "More". Set `vertical = grocery` if it isn't already.

## Deploy

```bash
# GitHub → EasyPanel pull → restart.
```

No migration, no new route. (Cache clear optional.)

## Recommended next step

A small **panel editor** to drag categories into departments (instead of tinker), writing the same
`category_groups` setting — so shop owners curate their own departments. Clean follow-on.
