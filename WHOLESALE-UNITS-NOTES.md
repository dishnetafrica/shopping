# Wholesale selling units (carton + per-piece + MOQ)

Lets a product be sold as a **carton/box/pack** with a **minimum order quantity**, and shows the
**per-piece** price so buyers see value. Fully opt-in per product — anything with the fields blank
behaves exactly as today, so grocery/restaurant catalogues are untouched.

## What a buyer sees (when set)

A "Carton · 100 pcs" badge, the carton price, a "≈ UGX 750 / pc" line, and a "Min order: 1 carton"
note. The cart enforces the MOQ: the first **Add** jumps straight to the minimum, and stepping below it
removes the line.

## Three fields per product

- **Unit** — the selling unit name (carton / box / pack / roll). Drives the badge + MOQ wording.
- **Pack size** — pieces per unit (e.g. 100 rolls/carton). Drives the per-piece price (shown when ≥ 2).
- **MOQ** — minimum order quantity in selling units (e.g. 1 carton). Blank = 1 (no minimum).

Set them in the panel: **Products → edit a product →** the new "Selling unit & minimum order" row.
Or carry them in the import CSV via the columns **Unit**, **Pack Size**, **MOQ** (see EuroPearl file).

## Files

**Edited**
- `database/migrations/2026_06_23_000001_add_wholesale_units_to_products.php` — nullable `moq`, `pack_size`, `unit_label`.
- `app/Models/Product.php` — fillable.
- `app/Services/Catalogue/ProductImporter.php` — accepts Unit / Pack Size / MOQ columns (by alias).
- `app/Http/Controllers/Storefront/StorefrontController.php` — feed exposes the fields.
- `app/Http/Controllers/Panel/PanelApiController.php` — feed exposes them; create/update accept them.
- `resources/storefront/shop.html` — pack badge, per-piece price, MOQ note, MOQ-aware cart (inc/dec/bump).
- `resources/panel/seller.html` — editor fields + parse + save.

**New**
- `qa/wholesale_units.php` — **17/17 green** (per-piece, labels, MOQ cart jump/remove, importer clamp).

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan migrate          # adds moq / pack_size / unit_label
php artisan optimize:clear
```

Then re-import `paper-company-products.csv` (Merge) for EuroPearl, or set the fields per product in the panel.

## QA
```
wholesale_units 17/17 · storefront_departments 11/11 · box_summary 13/13 · box_custody 14/14 — all green
```
