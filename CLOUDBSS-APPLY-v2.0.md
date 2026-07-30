# CLOUDBSS-APPLY v2.0 — trust + vision + Trust screen + catalogue PDF v3 "rich"
**Supersedes ALL earlier zips. One zip, upload everything to GitHub.**
Base: shoppingmain 41. Complete files, never diffs. Additive; php -l + node --check clean.

## Contents

**Trust v1.3 wiring** — routes/api.php · routes/web.php · routes/console.php
(nonce pages in the /task/{nonce} throttle group; schedules at 06:45 / 07:00 / Mon 07:15 EAT).

**Vision box claim backend (V4, CloudBSS half)** — vision_boxes migration · config/vision.php ·
VisionBox model · VisionBoxController (hello/poll) · VisionEventController (per-box tokens) ·
vision:claim + vision:boxes commands.

**NEW — Inventory Trust screen (backlog T6):**
- app/Http/Controllers/Panel/TrustPanelController.php  ← page + 7 papi endpoints
- resources/panel/trust.html                           ← the screen (house tokens.css/ui.js style)
- app/Services/Trust/TrustRuntime.php                  ← + resolveInvestigation() (additive method;
  same semantics as trust:resolve — clean ↑ reputation, confirmed_loss ↓ by value, error = no change,
  plus double-resolve protection)
- config/navigation.php                                ← menu entry: Stock → Inventory Trust
- routes/web.php                                       ← /panel/trust + papi routes (refreshed file)

## The screen — open /panel/trust after deploy (bookmark it)

Tiles: Verified stock (M9) · Shrink % (M1) · Attributed (M3) · Queue precision (M5) ·
Evidence coverage (M6) · Open queue. Shrink waterfall (7/14/28d). Today's queue with
one-tap resolve (clean / error / confirmed loss + value + notes — the label factory, no
artisan needed). Recent trust scores with decision filter. Vision: claim a box by typing
its on-screen code, fleet table with online/offline + revoke, live shelf-events feed.
It also appears in the left menu (Stock → Inventory Trust) where the nav config is wired.

## Deploy (same as before)

1. Upload extracted files to GitHub over the repo root (replaces 6, adds 9).
2. EasyPanel **Rebuild** → `php artisan migrate --force` → `php artisan storage:link` (once).
3. `.env`: `APP_TIMEZONE=Africa/Kampala` (+ optional legacy VISION_WEBHOOK_TOKEN/VISION_TENANT_ID).
4. Tenant 17 settings: `trust_digest=1` · `trust_staff_numbers` · optional `trust_digest_numbers`.
5. Open `/panel/trust` → tiles show "—" until the backtest/live scoring writes data:
   `php artisan trust:backtest --tenant=17 --months=12 --top=20 --write`

Actor data on the screen routes review only — never a ranking, never an accusation (Spec §5.2).

---

## NEW in v1.6 — Catalogue PDF v2 (bug fix + redesign)

`app/Services/Catalog/CatalogRenderer.php` (same public API; verified against dompdf 3 with
sample data, before/after screenshots in chat) + `CatalogueFlushCommand` now also clears the
PDF cache.

Bugs fixed (all reproduced from the old code):
1. Cover orphaned its contact line onto a near-blank page 2 → every index page number was wrong.
2. Every FULL category page (9 cards) overflowed A4 and dumped its footer on an orphan junk page.
3. Trailing blank page at the end of the document.
4. Broken-image ✕ boxes when a product image URL failed; wildly mixed photo sizes.
5. Product names glyph-sliced mid-line; price rows misaligned between cards.

Redesign: brand-band cover with accurate (auto two-column) index · dark category bands ·
equal-height cards on normalised 4:3 photo thumbnails (pre-fetched in parallel, disk-cached,
data-URI embedded → smaller PDFs, WhatsApp-safe) · deterministic two-line names with ellipsis ·
"How to order" back page · "Page N of T" numbering. Degrades gracefully without GD.

**After deploy:** `php artisan catalogue:flush --tenant=17` (old cached PDF lives up to 6h otherwise).
First render re-downloads photos once into `catalog-thumbs/` cache; after that it's fast.

## v1.7 — catalogue PDF size fix (33 MB → ~3-4 MB)

The old renderer embedded every product photo at ORIGINAL resolution — a 33 MB document is
just the sum of the original photos. v2 embeds normalised 440×330 q80 thumbnails instead,
plus font subsetting (isFontSubsettingEnabled). Measured on identical heavy-photo sample data:
**old 9.09 MB → new 0.24 MB (38× smaller)**; extrapolated to ~265 products ≈ **3-4 MB total**
(avg thumb 11.5 KB) — safe for WhatsApp and cheap on customer mobile data.

First render after deploy still takes 30-60 s (downloads each photo once, builds the thumb
cache in storage/catalog-thumbs/); every render after that reuses the cache and is fast.
Remember: `php artisan catalogue:flush --tenant=17` once after deploy.

## v1.8 — catalogue v3 "rich" (marketing-mock layout)

CatalogRenderer now renders the designed brochure: photographic cover (headline, audience
checklist, product-count stat box, hero photo, pinned QR/contact bar) · "Explore our categories"
overview page (photo cards ≤12 categories, compact 2-col index above that) · full intro page per
category with ≥7 items (hero photo + ★ top-sellers row + feature strip; smaller categories get a
slim hero band) · BEST SELLER / HOT DEAL badges on cards · WhatsApp QR (auto-generated from the
tenant's number via quickchart, cached; silently omitted offline) · "Need a product you don't
see?" promo band · richer How-to-Order page. Every element degrades gracefully — with zero setup
the v2 clean layout still renders. Verified end-to-end with dompdf 3 (9-page sample, no orphan
or blank pages; 246 KB).

**New tenant settings (all optional):**
- `catalog_cover_image`  — URL of the cover hero photo (portrait ~4:5; auto center-cropped)
- `catalog_promo_image`  — URL of the promo-band photo (landscape ~16:7)
- `catalog_qr_image`     — URL of a pre-made QR (else auto wa.me QR from the WhatsApp number)
- `catalog_best_sellers` — comma-separated SKUs or exact names → green BEST SELLER badge
- `catalog_hot_deals`    — comma-separated SKUs or exact names → orange HOT DEAL badge
- `catalog_audience`     — comma list for the cover checklist (default Hotels,Restaurants,Supermarkets,Retailers)

After deploy: `php artisan catalogue:flush --tenant=17` to rebuild.

## v1.9 — catalogue 500-error hardening (production fatals)

Fixes the `{"ok":false,...,"status":500}` on /api/v1/doc/catalog:
1. **Giant photos**: a 20-30 MP phone photo needs more GD decode memory than memory_limit —
   an UNCATCHABLE fatal. Dimensions are now checked BEFORE decoding (real headroom math);
   oversized photos get the placeholder. Verified: 31 MP photo under 128M limit → clean render, peak 30 MB.
2. **Timeouts**: first render of a big catalogue fetches every photo; set_time_limit(300) +
   a 120 s whole-fetch budget (stragglers become placeholders instead of killing the request).
3. Thumbnail + HTML build now run inside the try — any residual error returns the endpoint's
   clean 404 message, never a generic 500.

If a click still fails after this: EasyPanel console → `tail -n 80 storage/logs/laravel.log`
and search the request_id from the error JSON — paste those lines in chat.

## v2.0 — catalogue self-healing render ("PDF engine unavailable" fix)

The 404 "PDF engine unavailable" means pdf() returned null — either dompdf missing (now logged
explicitly) or an exception inside the rich photo pipeline (previously the 500; caught since v1.9).
pdf() is now TWO-STAGE: rich attempt → on any exception it logs the exact file:line
("CatalogRenderer rich path failed: ... — falling back to plain render") and retries in PLAIN
mode: no thumbnails, no cover art, no QR, no network/GD/Storage dependency at all — dompdf
embeds product image URLs directly (v1 behaviour). A catalogue is always delivered; the log
tells us which rich feature to fix. Both paths regression-tested.

Diagnosis after deploy, if needed (EasyPanel console):
  tail -n 100 storage/logs/laravel.log | grep -a CatalogRenderer
