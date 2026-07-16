# CloudBSS — Clean collection selector (navigation, not a dashboard)

Simplifies the "Shop for Every Need" selector to minimal navigation. The
merchandising now lives entirely in the hero below. 3 files:
`shop.html` (storefront), `shell.blade.php` (v3 editor), `seller.html` (classic).

## What changed
- **Selector cards are now clean navigation**: large icon + title + one subtitle.
  No product thumbnails, no collage, no coloured/photo backgrounds, no Browse
  arrow. Selected card = solid green. Think Apple / Shopify / Costco Business.
- **The hero does the selling** — its lifestyle photo (uploaded per collection),
  featured products and CTA carry the merchandising. Switching collections now
  **animates the hero in smoothly** (respects reduce-motion); the selector stays
  put.
- **Stable, business-priority order** (no more personalization reshuffling of the
  cards). The selector shows collections in the order you set:
  1. Wholesale Deals · 2. Restaurant Supplies · 3. Hotel Supplies ·
  4. Retail Store Essentials · 5. Home Essentials · 6. Catering Supplies ·
  7. Office Supplies.
  (Personalization still quietly powers the "Recommended for you" row below.)
- **"Wholesale Packs" → "Wholesale Deals"** — reads as pricing/value, not just
  bundles. Default landing collection is now Wholesale Deals.
- **Editors: reorder arrows** (↑ ↓) on each collection in both v3 and the classic
  panel, so you can set the priority order without reloading the starter.
- **Starter template** updated to the new order, names and subtitles in both
  editors.

## Apply the new order/names to your live site
Your saved config still has the old order/names. Either:
- v3 → Shop for Every Need → use the ↑ ↓ arrows to reorder + rename Wholesale
  → "Wholesale Deals", then Save (keeps any photos you added), **or**
- click **Load starter template** (now the new order/names) → Save (replaces the
  collection set).
Then set the hero lifestyle photo per collection (the selector no longer uses a
card image — photography belongs on the hero now).

Verified: shop.html, seller.html, shell.blade.php scripts compile; Playwright at
1440px — clean cards (no thumbnails/backgrounds/arrows), business-priority order,
Wholesale Deals default, stable selector on switch, hero animates in; no errors.
