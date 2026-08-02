# CloudBSS v4.7.5 — TVS on the storefront, true fitment, photo localization

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.7.5.zip (81 files, cumulative — supersedes v4.7.4) · **Migration:** none · **Artisan:** none

## Why (live test of eaautopart.com, 02 Aug)

A full customer-journey test of the live storefront found:

1. **TVS riders cannot pick their bike.** 35 of the 89 boraboda parts are TVS-line, but the bike picker only offered Bajaj models. Half the boda market saw a shop with nothing for them.
2. **Wrong fitment badge.** With "Boxer 100" chosen, *Front Brake Cable (TVS) — UGX 8,400* showed **"✓ Fits your Boxer 100 KS."** The generic-wear-item shortcut (cables, chains, bulbs fit everything) ran before the brand check. Wrong-part sales + returns waiting to happen.
3. **Browse-by-system tiles were dead** (8 of 10 empty) — fixed on the data side by the v3 catalogue CSVs (pipe-format keywords). No deploy needed for that part.
4. **Product photos are hot-linked from boraboda.com** and the Brand column came in empty — the current prod importer predates the URL-download + Brand code (already in this zip's PanelApiController since v4.7.3/4 work).

## Changes

**resources/storefront/autoparts.html** *(FIRST TIME in a zip — the customer storefront SPA)*
- **TVS HLX 125** added to the bike list (id `tvshlx125`, catName "TVS HLX", make TVS, specs card). Placeholder silhouette image — send one real HLX photo and I'll bake it in next drop.
- **Brand-family fitment guard** in `fitsBike()`: a part keyword-tagged `tvs`/`hlx` is confidently *not for* a Bajaj/Boxer/CT bike (and vice versa) — even generic wear items. Token-exact matching (so "re**ct**ifier" can never read as "CT"). Untagged parts behave exactly as before.
- **Browse-by-system** shows only systems that actually have parts once the catalogue is loaded (`systemsLive()`); empty tiles like "Accessories 0" disappear.
- **Model page:** the popular-parts rail hides parts that confidently don't fit the chosen bike; the trust card now says "100% genuine *{make}* parts" instead of hardcoded "Bajaj".
- Homepage model grid cap raised 6 → 8 so the TVS card is visible.
- Source mirrors updated in `src/` (03-garage, 04-vehicle, 09-shell, 10-views) — src/ stays out of the zip; the built file is what deploys.

**app/Http/Controllers/Storefront/StorefrontController.php** *(FIRST TIME in a zip — carries all storefront work to date, incl. the v4.6.1 canonical-link gating)*
- `enabledModels` += `tvshlx125` via new setting `show_model_tvshlx125` (default **ON**).

**app/Support/SettingsSchema.php + app/Services/Settings/SettingsWriteService.php**
- "TVS HLX 125" toggle appears under Settings → Storefront → *Bike models on the website*.

## Deploy

GitHub upload (replace files) → EasyPanel **Rebuild**. That's all.

## After deploy — redo the catalogue upload (order matters)

Only **part 3 (29 products)** ever made it into the system, and it imported before this zip's importer — so photos are hot-linked and Brand is empty. Clean redo:

1. **Owner** login on the **bike tenant** (not Family Shoppers!).
2. Products → Import → `boraboda-catalogue-part1-of-3.csv` → tick **Replace catalogue** → type **RESTART** → apply. *(Archives the old 29 + any leftovers — recoverable, nothing deleted.)*
3. `part2` → normal import. 4. `part3` → normal import.
5. Each import now **downloads every photo to your own server** (`/storage/products/...`) and fills **Brand**. ~20–40 s per file is normal — that's 30 photos being fetched.

**Check afterwards:** Products list shows 89 items with photos · storefront → pick *TVS HLX 125* → TVS parts badge "✓ Fits" · pick *Boxer 100* → TVS parts disappear from listings · Browse-by-system shows 9 live tiles.

**Optional settings:** `sf_genuine` → "100% genuine Bajaj & TVS parts — or your money back." · per-model on/off toggles now include TVS HLX 125.

## Verified before ship

- 10/10 SPA edits string-verified; `node --check` on all touched src modules; `php -l` on all 3 PHP files.
- Playwright customer journeys (phone 390px + desktop) against the exact SPA + exact feed shape:
  - Boxer 100 rider: TVS parts `fits=false` (hidden), Boxer parts "✓ Fits", 9 live system tiles, search "chain" works.
  - TVS HLX rider: bike selectable, "Showing parts for TVS HLX 125", TVS parts "✓ Fits", Boxer parts hidden, model page renders specs + summary.
  - Zero console errors, zero failed requests in every pass.

## Still on the standing checklist

Deploy backlog unchanged (BOT-STRESS-TEST run, orders:items-backfill, MOQ activation, consignment phones, 8 GB resize, Africa's Talking KYC). See STATUS-ADDENDUM-2026-08-02 in the project.
