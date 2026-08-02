# CloudBSS v4.16 — Bike photo pack: upload ONE zip, done (+ the upload bug fixed)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.16.zip (cumulative — supersedes v4.15 and everything before it; 122 files) · **Migration:** same two guarded ones (skip if already run). Changed: BrandingUploadController + SettingsSchema + routes; NEW: BikePhotoPack service + controller.

## The bug you hit (why only TVS worked)

The image-upload endpoint had a **hardcoded whitelist** from v4.7.8 — only `model_image_tvshlx125` (and logo/cover/promo/QR) were allowed, so every NEW photo field from v4.15 was silently rejected server-side. Fixed properly: allowed keys are now **derived from the Settings schema**, so any image field the Settings screen ever shows is automatically uploadable. The one-by-one uploads now work for all bikes.

## The new one-shot way (what you asked for)

Open **`mycloudbss.com/papi/bike-photos`** while signed in as owner → drop **BIKE-PHOTO-PACK.zip** (sent in chat — built from your own records, already named correctly) → Upload pack. All 8 photos (homepage hero + 7 bikes) are stored with your business data and every setting is filled in one go. You'll see a ✓ per bike with a preview.

- Photos live in `/storage` — they **survive every rebuild forever** and never depend on GitHub or the Docker build.
- The zip contract: files named `hero.jpg, boxer100.jpg, boxer125hd.jpg, boxer150.jpg, boxer125x.jpg, ct125hd.jpg, boxer150x.jpg, tvshlx125.jpg` (.png/.webp also fine; spaces/underscores tolerated). Anything else in the zip is skipped with a friendly note, never an error. Re-upload any time to replace photos — later you can drop in your OWN better shots with the same names.
- The Settings page now mentions this shortcut in the Bike models section.

## After you upload the pack

Hard-refresh eaautopart.com: hero + all bike cards are back. The PDF catalogue's cover and bikes strip use the same uploads. Model-page photo galleries are the one thing still coming from the repo files — those come alive when the Dockerfile `COPY public/` line lands (still recommended, also needed for the catalogue's Inter font).

## Deploy

Upload the delta (6 files) or cumulative → Rebuild → open `/papi/bike-photos` → drop the pack.

## Verified before ship

Harness-tested ingestion: real pack 8/8 applied with correct `/storage` URLs + settings; junk-zip edge cases (Mac `__MACOSX` cruft ignored, wrong names skipped WITH naming hint, fake image rejected, case-insensitive names, duplicate protection, 8MB per-photo cap) · owner-gate on both routes (same pattern as branding-upload) · php -l ×5 · zip md5-verified against the repo (122 files).
