# CloudBSS v4.10.4 — Audit Part 2: search measured (and it's excellent), gaps fixed

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.10.4.zip (cumulative — supersedes v4.10.3 and everything before it) · **Migration:** same two guarded ones (skip if already run). Changed: the storefront file + StorefrontController (SEO head only).

Your reviewer's "world-class" list, executed. Full numbers in `report-part2.md` inside the audit package.

## The headline numbers (measured, not estimated)

- **OEM search accuracy: 90/90 identifiers rank #1.** Exact names: 89/89. Engine latency: **0.13ms per keystroke**. A mechanic typing an OEM number reaches WhatsApp order in **3 taps**. Your "right part in under 10 seconds" is measured fact.
- **Misspellings were the one search leak** — 9/10 realistic typos dead-ended. **Fixed: "Did you mean"** — `breke shoe` now offers *brake shoe*, one tap to results. 8/9 recover automatically, zero false alarms, and anything uncorrectable still lands in the ask-my-colleague capture. Three-layer recovery: correction → diagrams → human.
- **Performance truth on a Fast-3G mid-range phone: first paint 1.4s ✅ but full load 15.4s 🔴** — and the audit found exactly why: **3.42 MB of the 4.34 MB file is baked-in model/gallery photos** (base64 doesn't compress). App code is just 0.10 MB. The fix is architectural (externalize images into a cached second request) — projected **5× faster first load (~3s)** — recommended as the next engineering drop (v4.11), not rushed into this one.

## Also fixed this drop

- **"Try again ›"** action on the catalogue-down notice (was a dead-end message).
- **Broken supplier images now self-hide** on product cards, result rows and category tiles (no broken-glass icons if a CDN dies).
- **Card "Add to cart" raised to 40px** tall (was 28px — the most-tapped button in the shop was the smallest).
- **SEO shell completed** (StorefrontController): canonical, full Open Graph + Twitter card, **AutoPartsStore + WebSite(SearchAction) JSON-LD** — the parts site now matches the brand page's SEO. Honest limitation documented: per-part Product schema needs non-hash URLs (v5-scale item).

## Also audited (no code needed)

- **Journeys:** all 5 reviewer journeys tap-counted (table in report).
- **Resilience:** slow-feed spinner ✅ recovers ✅; offline notice ✅ (+retry); empty states best-in-class.
- **Consistency:** one shadow system ✅, button heights clustered ✅ (after fix), radii tokenization noted for housekeeping.
- **Conversion instrumentation:** every funnel step already emits events (`search`, `add`, `checkout`, `whatsapp`, `part_request`…) — the KPIs your reviewer listed are computable today; recommended next: a one-screen panel report (top searches / zero-results / funnel) so the storefront doubles as a demand sensor.
- **Images:** criteria locked, measurement blocked until the RESTART catalogue redo lands real photos.

## Verified before ship

Did-you-mean: 8/9 typo corpus + 3 no-false-positive controls + banner→tap→results flow · retry link renders on feed failure · 40px buttons confirmed · **overflow matrix still perfect (16 routes × 9 widths, all 0px)** · all six feature journeys green · php -l + node --check + built bundle · zip 91 files md5-verified.

## Deploy

GitHub upload → Rebuild. Score: **94/100** — the 96–98 path is now a concrete list: v4.11 image externalization (the big one), R1–R6 polish, panel search-KPI report.
