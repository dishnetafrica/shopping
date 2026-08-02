# CloudBSS v4.10.1 — The Amazon-style search journey (screens 2 & 3 of your reference)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.10.1.zip (cumulative — supersedes v4.10 and everything before it) · **Migration:** same two guarded ones (skip if already run). **No backend changes — one file: the storefront.**

Your 4-screen reference audit: screen 1 (home) shipped in v4.10; screen 4 (product page) was already ~90% there. This drop builds the two missing screens and the small product-page delta.

## Screen 2 — Dedicated search screen (the Search tab)

Tapping **Search** in the bottom nav now opens a full search page instead of a pop-over — the "no keyboard overlap" screen from the mock:

- Back button + **Search Parts** title, big search bar with camera (photo part-ID) and mic (voice) inside, auto-focused.
- **Recent Searches** — this device's last 8 searches as chips, with **Clear all**. Recorded automatically every time a search runs.
- **Popular Searches** — your best sellers as tappable rows with magnifier icons (falls back to popular categories until best sellers are set).
- **Browse by Motorcycle** — the same photo-card rail as the homepage (shared component, one source of truth), with the green "Showing parts for…" line.
- The two big quick actions (Photo of old part / WhatsApp a mechanic).

## Screen 3 — Amazon-style results

- **Tabs with live counts: All · Products · Diagrams · OEM.** Diagrams finds exploded-diagram plates whose parts match the query; OEM finds parts by OEM/SKU number even when the name doesn't match. Tabs only appear for buckets that have hits.
- **Scannable list rows** instead of a card grid: photo · name · **OEM in mono** · Fits line · red price · stock · one-tap add-to-cart. "Fast scanning — price, fitment, stock visible at a glance."
- The pinned top search bar now **carries the query** on results pages, ready to edit.
- **The mechanic recovery got smarter, not weaker:** a total miss shows the v4.9.2 "Ask my colleague" form as before. New case — when the query matches a **diagram slot but no stocked part** (your "air filter" exactly): the diagram rows appear AND beneath them: *"'air filter' is on the diagram — the part itself isn't in stock today"* with the same request form. The diagram proves you know the part; the form captures the sale.

## Screen 4 — Product page delta

- Sticky buy bar now has **Add to Cart + Chat on WhatsApp** side by side (mock's dual-CTA).
- "Goes well with" retitled **Frequently bought together**.
- Deliberately NOT copied from the mock: the ★4.8 (128 reviews) block — inventing reviews would poison the trust the rest of the site builds. When you have real ratings, we'll show real ones. Heart/share icons skipped as low-value for v1 (recorded).

## Deploy

GitHub upload → Rebuild. Test in 30 seconds: tap Search in the bottom nav → see recent/popular searches → search "drive chain" → tabs + list rows → search an OEM number → OEM tab appears → search "air filter" → diagram + Ask-my-colleague together.

## Verified before ship

- Playwright at 390px: hub (title/popular rows/bike rail/quick actions/active tab) → hub suggestions → results tabs with counts → OEM-number search surfaces the OEM tab → diagrams bucket + strip → add-to-cart from a row stays on results → **diagram-only miss shows recovery with part prefilled** → total miss shows recovery with no tabs → recent chips record/tap/clear → buy bar dual CTA → FBT title. Zero console errors.
- Regressions: v4.10 home journey (updated for the new Search tab), v4.9.2 part-request journey (form still works end-to-end from the new results page), full Boxer + TVS persona journeys — all zero errors.
- node --check on all changed modules + built bundle · zip md5-verified against the repo.
