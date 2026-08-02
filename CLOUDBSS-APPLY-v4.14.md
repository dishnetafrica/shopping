# CloudBSS v4.14 — Catalogue 2.1: the 9.1/10 review answered

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.14.zip (cumulative — supersedes v4.13 and everything before it; 120 files) · **Migration:** same two guarded ones (skip if already run). Changed code: one file — `app/Services/Catalog/CatalogRenderer.php`.

## Review points → what shipped

- **Bigger images (60%+)** — the badge strip is gone; badges, stock chip and QR now sit ON the photo (like Bosch catalogues), so the photo area grew ~16% with no page-count change.
- **QR on every product** — each card carries its own QR, bottom-right of the photo. For your parts store it opens the part's REAL page (`eaautopart.com/part/…` — the v4.11 SEO pages, so the scan lands on price + WhatsApp button + photos). Fetched once in parallel and disk-cached; if the QR service is unreachable the card just prints without one. **To get product-page QRs make sure Settings → public website = eaautopart.com** (otherwise cards fall back to a prefilled WhatsApp QR — also fine).
- **Fitment on every card** — "✓ Fits Boxer 100 KS · 125 HD · BM150 +2": specific models, matched from each product's brand + keywords against your storefront's model list, compressed by family. Falls back to the brand line when nothing specific matches. Grocery tenants keep MOQ lines here.
- **OEM prominent** — the blue OEM chip moved to the TOP of the card, above the name, with the technical spec beside it.
- **Specs without new data** — trailing brackets in names become a spec line: "Drive Chain (428H-112L)" prints as **Drive Chain** + spec `428H-112L`. Pure fitment brackets like "(Boxer)" disappear into the fits line instead of cluttering the name.
- **Visual hierarchy / more badge types** — besides your BEST SELLER (green) and HOT DEAL (red, promotions only), two AUTOMATIC badges: **POPULAR** (top sellers by the live popularity score, capped at ~8) and **NEW** (products added in the last 45 days, outline style). No settings needed.
- **Stronger small-category heroes** — brand chips ("Bajaj Boxer · TVS") on the green band so a mechanic instantly sees coverage.
- **Contact page** — new **BECOME A DEALER** card (your dealer programme is live, so the catalogue now recruits for it).

## One thing I did NOT do — star ratings

The review's Amazon-style card shows ★★★★★. We have no ratings data, and printing invented stars on every product is the kind of fake signal we rejected before (same call as the storefront review). The POPULAR badge does the same persuasion job honestly — it's computed from your real sales score. If you later collect ratings, the card has room for them.

## Deploy + test

GitHub upload → Rebuild → ask the bot for the catalogue (or open `/catalogue`). Check one card: OEM chip on top, fits line with model names, QR in the photo corner — scan it with your phone: it should open that part's page on eaautopart.com. First render ~15–40 s (photos + 89 QRs cache in parallel), instant for 6 h after.

## Verified before ship

23-page BIKE render + uploaded-cover-art mode + grocery regression (MOQ lines, wholesale copy, no bike/parts content, graceful no-QR) · fitment compression on real feed keywords (V15/HLX never claimed by Boxer-only parts — maker words excluded from matching) · overlay positioning spike-tested in dompdf before adoption · php -l · zip md5-verified against the repo (120 files).
