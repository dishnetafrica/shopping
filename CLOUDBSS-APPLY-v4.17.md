# CloudBSS v4.17 — Model-page GALLERIES from the portal (the pack now carries them)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.17.zip (cumulative — supersedes v4.16 and everything before it; 122 files) · **Migration:** same two guarded ones (skip if already run). Changed: storefront file + StorefrontController + BikePhotoPack service + pack page copy.

## What this closes

Your screenshot of the Boxer 125 HD page: main photo empty, gallery thumbnails broken. That gallery was the last surface still fed by repo files. Now:

- **The photo pack carries galleries too.** Files named `gal-boxer125hd-1.jpg`, `gal-boxer125hd-2.jpg`, … (up to 8 per bike) become that model's photo gallery — stored in `/storage`, shown on the model page, surviving every rebuild. The **updated BIKE-PHOTO-PACK.zip sent in chat now contains 32 photos**: 8 main + 24 gallery, all from your records, correctly named.
- **Your uploaded main photo now leads the gallery** even without gallery files — so after any pack upload the model page's big photo is never empty.
- **Dead gallery thumbnails hide themselves** instead of showing broken icons (same hardening the hero got in v4.15).

## How to use

Deploy this (delta = 4 files) → Rebuild → open **`mycloudbss.com/papi/bike-photos`** → drop the NEW BIKE-PHOTO-PACK.zip → you'll see 32 ✓ rows → hard-refresh the site → homepage, bike cards AND every model page with its full gallery are back. If you already uploaded the old 8-photo pack, just upload the new one over it — replacements are safe and instant.

Later, replace any bike's gallery with your own shots: name them `gal-{bike}-1.jpg` … and drop a zip with just those.

## Verified before ship

Harness: full 32-photo pack applied 32/32 (galleries written as ordered JSON, out-of-order slot numbers sorted, messy names tolerated, unknown bikes get a naming hint, duplicate slots skipped) · storefront journeys: gallery override renders (main + thumbs), uploaded main photo leads built-in gallery, dead thumb hides — plus master journey, zero errors · **overflow matrix all-zero (16 routes × 9 widths)** · built↔src byte parity · php -l ×3 · node --check · zip md5-verified (122 files).
