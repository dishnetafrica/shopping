# CloudBSS v4.7.7 — catalogue review fixes (8.8 → 9.8 plan, part 1)

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.7.7.zip (cumulative — supersedes v4.7.6 and everything before it) · **Migration:** **YES — run `php artisan migrate`** (adds `products.oem_number`; guarded, safe to re-run. Everything degrades gracefully until it runs — nothing breaks, OEM features simply stay dormant.)

## What the external catalogue review asked for, and what this drop does

**1. OEM part numbers (review's ⭐⭐⭐⭐⭐ item) — the plumbing is now everywhere.**
New `oem_number` on products, flowing through the whole platform:
- **Catalogue PDF:** cards print **OEM: <number>** in bold where filled (mechanics order by this). Falls back to the unit line exactly as before when empty.
- **Storefront:** the part-number chip on product cards now uses the real OEM number, and the **exploded-diagram cross-links (byPn) resolve against it** — as OEM numbers fill in, diagram callouts start linking straight to your stock, and per-model fitment from the official Bajaj cross-reference activates automatically.
- **Cart / WhatsApp orders** carry the OEM number on each line.
- **Import:** CSV column `OEM Number` (also accepts OEM / Part Number / Part No). **Export/feed:** `OEM_Number`.
- **Update API:** `updateProduct` now also accepts `sku` and `oem`.
Numbers themselves: **never invented.** Sources are (a) boraboda's cross-reference list — one email to ask, or (b) copied off each genuine packet as stock arrives. Send me either and I regenerate the catalogue CSVs with the OEM column filled.

**2. Compatibility line.** Cards without an MOQ now print **"Fits: Bajaj Boxer models" / "Fits: TVS models"** from the Brand column. (MOQ tenants like Family Shoppers keep their MOQ line — the slot is shared, card height unchanged, zero FMCG regression — harness-proven.) Per-model lists ("✓ BM100, ✓ BM150") come free once OEM numbers land, via the Bajaj cross-reference already in the storefront.

**3. Trust badge.** New setting **Settings → Storefront/Catalogue → "Trust badge"** — prints on the cover next to the date and on the back page. ⚠️ Before wording it "Genuine Bajaj/TVS", get boraboda's written confirmation that the parts are genuine; otherwise use "OEM-quality parts". Wrong genuineness claims destroy dealer trust and can bite legally.

**6. Incomplete phone (+2567) — two fixes.** The number printed on the PDF comes from *Settings → Business profile → Public phone*, where a half-typed value was saved. Fix the setting (full `+256 7XX XXX XXX`). The renderer is also hardened: if the public phone has fewer than 9 digits it now falls back to the connected WhatsApp number automatically, so a typo can never cripple a catalogue again.

**7. Cover.** New setting **"Cover tags"** — one value-prop line under the title (e.g. *"Wholesale Distributor · East Africa Coverage · Fast Delivery"*). For a full-impact cover with the two motorcycles, upload artwork to *Cover photo* (portrait ~4:5) — the layout already supports it.

**8. Back page.** Now prints a business-details block from settings you already have — Business hours, Delivery areas, Payment methods, Email, Location — plus a standing **"Dealer & bulk enquiries welcome — ask for the trade price list"** line. Only filled settings print.

## Review items NOT in this drop, and why

- **4. Packaging/hologram photos:** needs real photos (ask boraboda for packet shots, or snap on arrival). Gallery slots (Gallery 1–3) already exist per product; catalogue placement comes with v2.
- **5. QR per product card:** needs a QR library decision (none in composer today; the single page-level WhatsApp QR remains). Scheduled for catalogue v2.
- **Full premium redesign (reviewer's "Version 2"):** dealer/retail dual pricing, carton quantities, weight/dimensions, NEW/BESTSELLER tags exist partially (badges do) — the rest is a design project; say the word and I'll spec it.

## Deploy

1. GitHub upload (replace files) → Rebuild.
2. **`php artisan migrate`** (one new guarded migration).
3. Settings to fill (2 minutes): Public phone (full number!) · Trust badge · Cover tags · Business hours · Delivery areas · Payment methods.
4. Catalogue PDFs regenerate on next request (6-hour cache; changing any setting above busts nothing — to force, generate after the cache expires or bump a product).

Also still pending from before: the clean 3-file catalogue re-upload (part 1 RESTART → 2 → 3) to localize photos + fill Brand/Cost — do it after this deploy and the new Fits lines light up too (they read Brand).

## Files changed vs v4.7.6

CatalogRenderer.php · PanelApiController.php · StorefrontController.php · Product.php (FIRST TIME in zip) · SettingsSchema.php · SettingsWriteService.php · resources/storefront/autoparts.html · NEW migration 2026_08_02_000002_add_oem_number_to_products.php. (src/ mirrors updated in repo, not shipped.)

## Verified before ship

- **18/18 renderer harness** (reflection on the real class): OEM line prints bold + escaped + clipped; Fits line from Brand; MOQ precedence (FMCG unchanged); legacy item arrays without the new keys render clean; back-page info block folds 2-col, dealer line always present, no empty table.
- **Storefront live test** (Playwright on the exact SPA + feed): OEM in feed → real part-number chip on the card, byPn diagram index resolves it, cart line carries it, zero console errors.
- Schema guards proven by design: feed/import/update all no-op the OEM column until the migration runs.
- `php -l` ×7 · `node --check` on all 7 touched src modules · zip md5 vs repo.
