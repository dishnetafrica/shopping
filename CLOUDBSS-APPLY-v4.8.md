# CloudBSS v4.8 — Storefront 2.0 Phase 1: FIND

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.8.zip (cumulative — supersedes v4.7.8 and everything before it) · **Migration:** same single one as v4.7.7/v4.7.8 (`php artisan migrate`, guarded — skip if already run).

The reviewer approved the Storefront 2.0 plan at 9.8/10 with five pre-launch refinements. This drop ships Phase 1 (FIND) with all five built in.

## The part-finder hero ("Google for motorcycle parts")

- **Type-ahead suggestions** as the customer types: top-3 parts with name, **price** and brand tag, plus "Search everything → N parts". Client-side over the loaded catalogue — instant even on poor data. Keyboard navigation (↑↓/Enter/Esc), ARIA combobox/listbox, 44 px rows.
- **Search understands part numbers:** exact **OEM or SKU** match ranks first (normalised — "bb bm cha 00" finds BB-BM-CHA-00), then name-prefix, name, keywords/category/brand.
- **Bike-aware everywhere:** suggestions never include a wrong-bike part; fitting parts outrank unknown ones.
- **Bike chips** under the search — one tap filters the whole session; "All bikes" clears.
- **"📷 Photo of old part"** — opens WhatsApp with a pre-filled message incl. the customer's bike (your bot's vision already identifies parts from photos — Phase 3 makes this native on the web).
- **"🔧 Describe the problem"** — the AI Mechanic, now a hero-level button.
- **Popular chips are now data-driven** — only part families that actually exist in the catalogue (goodbye dead "Air Filter"/"Spark Plug" chips).

## Cards & product page (the reviewer's five refinements)

1. **Bigger photography:** card image area enlarged (~45% of card height), white background, contain-fit.
2. **OEM dominates:** monospace OEM chip on top of every card that has one; on the product page a large **OEM block** (big number + small SKU) replaces the old "Part No" line.
3. **Compatibility as a checklist:** product page shows 🟢 **FITS** with ✓ rows per model (from the Bajaj cross-reference when OEM data lands; brand-level today — never over-claims). Cards carry a one-line "Fits …" summary.
4. **Real stock quantities:** "24 in stock" when a count exists; "In stock" for untracked; "To order" for zero.
5. **Diagrams as a shopping entry:** "Shop from the diagram ›" on the homepage systems section, and every category listing links straight to its exploded diagram.

Plus: **Buy on WhatsApp button on every card** (composes part + OEM + price + customer's bike), honest brand chip on product pages (a TVS part no longer says "Genuine Bajaj"), and the hardcoded genuine wording removed.

## New homepage flow (reviewer's structure)

Search → bike chips → can't-find-it (photo / mechanic) → motorcycle cards → systems (+diagram link) → **Recently added** → **Best sellers** (reads the same Settings → Catalogue "Best sellers" list the PDF badges use) → **"Common replacements for your {bike}"** service rail when a bike is chosen → trust. The old price-sorted "Popular parts" rail is retired until real sales data feeds it (it returns automatically as popularity accumulates).

## Files changed vs v4.7.8

- `resources/storefront/autoparts.html` — hero, suggest engine, prodCard, product page, vSystem, rails, CSS (+`min-width:0` hero-grid fix). Src mirrors: 01-core, 02-catalogue, 10-views.
- `app/Http/Controllers/Storefront/StorefrontController.php` — catalogue feed +`SKU`; cfg +`bestSellers`.

## Deploy

GitHub upload → Rebuild → `php artisan migrate` (if not yet run). To light up **Best sellers**: Settings → Catalogue → Best sellers (comma-separated SKUs or exact names). All previous checklist items still apply (public phone fix, catalogue re-upload part1 RESTART → 2 → 3, boraboda OEM list).

## Verified before ship

- Suggest engine: exact SKU → correct part #1 · "cha" → 3 chains + total · garbage → 0 · **bike-aware: Boxer rider's "cable" suggestions contain zero TVS cables**.
- UI: dropdown renders with prices + brand tags; keyboard nav; hero grid no longer clips the headline (min-width fix).
- Cards: OEM chip when present, Fits line, SKU, real-qty stock text, WhatsApp buy.
- Product page: 🟢 FITS checklist, big OEM block, brand-honest chip ("TVS" on a TVS part).
- Full regression journeys as Boxer rider AND TVS rider: fitment guard intact both directions, search page, cart, diagrams, model pages — **zero console errors, zero failed requests** in every pass.
