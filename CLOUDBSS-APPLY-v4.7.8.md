# CloudBSS v4.7.8 — your TVS HLX 125 photo, baked in + uploadable

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.7.8.zip (cumulative — supersedes v4.7.7 and everything before it) · **Migration:** **YES, same one as v4.7.7** (`php artisan migrate` — products.oem_number; guarded, safe to re-run; skip if you already ran it).

If you haven't deployed v4.7.7 yet, deploy only this zip — it contains everything (TVS bike + fitment guard, catalogue OEM/Fits/badge/back-page work, and this drop).

## What's new

**1. The photo you sent is now the storefront's built-in TVS HLX 125 image.** Processed (white background, trimmed, optimised to 126 KB) and embedded directly in the storefront exactly like the Bajaj bike photos — homepage card, bike picker and model page. No external link to TVS's website anymore, nothing to host, loads instantly, works offline-cached.

**2. A real Upload button for future changes.** Settings → Storefront → *TVS HLX 125 photo* is now a **Choose photo** field (was: paste-a-URL). Pick any image from your phone/computer — it stores on your server and replaces the built-in photo. Clear it to go back to the built-in one. (BrandingUpload endpoint now accepts this key; owner-only, 6 MB max, png/jpg/webp.)

If a custom-uploaded photo URL ever breaks, the site automatically falls back to the built-in photo — never a broken image.

## Files changed vs v4.7.7

- `resources/storefront/autoparts.html` — IMG library: TVS HLX 125 = your photo (data-URI, 1400 px JPEG).
- `app/Http/Controllers/Storefront/StorefrontController.php` — modelImages default now empty (baked photo is the base; setting overrides).
- `app/Http/Controllers/Panel/BrandingUploadController.php` — accepted keys += `model_image_tvshlx125`.
- `app/Support/SettingsSchema.php` — the field is type `image` (upload control) with help text.

## Deploy

GitHub upload → Rebuild → `php artisan migrate` (if not already run for v4.7.7). Nothing else.

Then the standing list still applies: fix Public phone (the "+2567" bug) · catalogue re-upload part 1 RESTART → 2 → 3 · fill Trust badge / Cover tags / Business hours / Delivery areas / Payment methods · ask boraboda for the OEM cross-reference list + genuine confirmation.

## Verified before ship

Playwright on the exact SPA: homepage card, bike picker and model-page hero all render the baked photo (naturalWidth 1400, no external requests, zero console errors); override mechanism unchanged (setting URL still wins; dead URL falls back to the baked photo). `php -l` ×3 · zip md5 vs repo.
