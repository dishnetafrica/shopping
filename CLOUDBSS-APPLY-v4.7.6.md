# CloudBSS v4.7.6 — real TVS HLX 125 photo on the storefront

**Date:** 2026-08-02 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.7.6.zip (81 files, cumulative — supersedes v4.7.5 and everything before it) · **Migration:** none · **Artisan:** none

If you have not deployed v4.7.5 yet, deploy only this zip — it contains all of v4.7.5 (TVS bike, fitment guard, live system tiles) plus this drop.

## What's new

**The TVS HLX 125 card now shows the real motorcycle** — the official product shot from TVS's own media library (tvsmotor.com, the same image TVS uses on its HLX product pages). It appears on the homepage card, in the bike picker, and as the hero on the TVS HLX 125 model page.

Three layers of safety behind it:

1. **Official photo** loads from TVS's server (customers' browsers fetch it directly; nothing stored on ours).
2. **Auto-fallback:** if TVS ever moves or removes that file, the card instantly swaps back to the built-in silhouette — no broken-image icon, ever. (`mimgErr()` in the SPA; tested with the URL alive and with it blocked.)
3. **Your own photo wins:** Settings → Storefront → *"TVS HLX 125 photo URL"* — paste any image URL (e.g. one of your own uploads) and it replaces the TVS one. Blank = official photo.

## Files changed vs v4.7.5

- `resources/storefront/autoparts.html` — `modelImg()` resolution (tenant override → baked library), `onerror` fallback wired on home card, picker tiles and model-page hero; `galleryFor()` override-aware. Src mirrors: 01-core, 09-shell, 10-views.
- `app/Http/Controllers/Storefront/StorefrontController.php` — passes `modelImages` (setting `model_image_tvshlx125`, defaulting to the official TVS URL).
- `app/Support/SettingsSchema.php` + `app/Services/Settings/SettingsWriteService.php` — the photo-URL field under Settings → Storefront.

## Deploy

GitHub upload (replace files) → EasyPanel **Rebuild**.

## Still pending from your catalogue export (02 Aug)

Your export confirmed all 89 products are live with correct keywords — well done. Two things it also confirmed, both cured by ONE redo after this deploy:

- **Photos still hot-link boraboda.com** (not stored on your server), and **Cost is empty** (margin reports will show no profit).
- Fix: after Rebuild — owner login, bike tenant: part 1 with **Replace → RESTART**, then part 2, then part 3. The current importer stores every photo locally and fills Brand + Cost. ~20–40 s per file is normal.

## Verified before ship

- Playwright, both paths: TVS URL loading → real photo on card/picker/model hero (naturalWidth > 0); TVS URL blocked → silhouette swap on all three, no broken images, no unhandled errors.
- `php -l` ×3 · `node --check` on all touched src modules · zip contents md5-checked against the repo.
