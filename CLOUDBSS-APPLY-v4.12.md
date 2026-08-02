# CloudBSS v4.12 — 5× faster first load (the images moved out of the page)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.12.zip (cumulative — supersedes v4.11 and everything before it; **now 117 files** — 26 new image assets) · **Migration:** same two guarded ones (skip if already run).

## What the audit found, now fixed

The performance audit traced the slow first load to one cause: **3.42 MB of the 4.34 MB page was motorcycle photos baked into the HTML as text** (base64), re-downloaded on every visit and un-cacheable. This drop extracts every one of them into real image files.

## Measured result (same Fast-3G + throttled-CPU test as the audit)

| Metric | Before | **After** |
|---|---:|---:|
| Page size (gzipped, on the wire) | 3.06 MB | **0.54 MB** |
| Largest Contentful Paint | 15.4s | **3.3s** |
| Time until search is usable | ~15.5s | **3.3s** |
| First paint | 1.4s | 1.4s |

A mechanic on a mid-range phone with 3G now has a working search in ~3 seconds instead of ~15. Every `/part/…` URL Google visits (v4.11) is also 5× lighter.

## How it works now

- The 36 baked photos became **26 deduplicated image files** (identical photos shared one file automatically) under `/assets/sfm/`, named by content hash — so browsers cache them for good.
- The page's image maps now hold **URLs instead of megabytes**, and photos load lazily — the browser only fetches the ones actually scrolled into view.
- Nothing else changed: the bike selector, hero, model galleries and picker all read the same maps and were verified pixel-working; if an image file were ever missing, the existing fallbacks kick in.

## Deploy

GitHub upload → Rebuild. The zip carries the image files at `public/assets/sfm/` — no configuration. **Quick test:** open eaautopart.com on your phone with mobile data (not Wi-Fi) — feel the difference; then check the bike photos on the homepage and a model page look exactly as before.

## Verified before ship

Extraction integrity (26 files, 1.84 MB decoded, every map value now a URL) · all imagery renders from URLs on home / model gallery / bike picker with zero failed requests · Fast-3G vitals re-measured (table above) · all six feature journeys green, zero console errors · **overflow matrix still perfect (16 routes × 9 widths, all 0px)** · data + app bundles syntax-checked · zip md5-verified against the repo (117 files).
