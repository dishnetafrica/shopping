# CloudBSS v4.15 — Manage every bike photo from the portal (no GitHub needed)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.15.zip (cumulative — supersedes v4.14 and everything before it; 120 files) · **Migration:** same two guarded ones (skip if already run). Changed: storefront file + 2 src modules + SettingsSchema + SettingsWriteService + StorefrontController + CatalogRenderer.

## What this is

You asked: "instead of keeping images on GitHub why don't we load in portal directly?" — done. **Settings → Storefront → Bike models on the website** now has a photo upload for every bike plus the homepage hero:

- Homepage hero photo · Boxer 100 KS · Boxer 125 HD · Boxer BM150 · Boxer BM125X · CT 125 HD · Boxer BM150 X · (TVS HLX 125 was already there since v4.7.8)

**Your upload wins everywhere** — homepage bike cards, bike picker, model pages, the hero, AND the PDF catalogue (cover shot + the "parts for these motorcycles" strip). Uploads are stored like your product photos (`/storage`), so they **survive every rebuild and never touch GitHub**. Leave a field empty = the built-in photo. If an uploaded link ever dies, the site quietly falls back to the built-in.

Also in this drop: a broken photo can no longer show the ugly broken-image icon anywhere in the bike sections — if both the upload and the built-in fail, the image hides itself cleanly (this is the strip you saw at the top of your homepage).

## Why this fixes your site TODAY

As of my last check, the Dockerfile fix (`COPY public/ ./public/`) is **not on GitHub main yet**, so the built-in photos still 404 on the live site. With v4.15 you don't have to wait for that: deploy this, then upload the bike photos in the panel — they serve from `/storage`, which has always worked on your server. Bikes come back immediately.

**The Dockerfile one-liner is still worth landing** — it's what makes the built-in defaults and the catalogue's Inter font files reach the server, and any future files under `public/`. But it's no longer the only path to a good-looking site.

## Deploy + test

Upload (≤100 files per GitHub drag — or use the delta zip) → Rebuild. Then: Settings → Storefront → Bike models → **Homepage hero photo → Choose photo** (any good motorcycle shot) → save → refresh eaautopart.com: the hero shows your upload. Repeat per model card. Generate the catalogue after: cover + bikes strip use the same uploads.

## Verified before ship

Override-wins + dead-link-fallback + total-failure-hides (new journey415 + master journey, zero errors) · **overflow matrix all-zero (16 routes × 9 widths)** · catalogue renders with overrides (cover + strip) and without (built-ins) · grocery unaffected (vertical-gated) · php -l ×4 · node --check src + both built bundles · zip md5-verified (120 files).
