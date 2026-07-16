# CloudBSS — Search surface: Popular brands + polish

Completes the search overlay's default (empty) state. Single file:
`resources/storefront/shop.html`. No backend/config change; mobile + desktop.

## What the search ALREADY had
Full-screen overlay (mobile) / centered command palette (desktop) with:
Recent searches · Popular categories · Trending products · voice (mic) + scan
(camera) icons · brand / category / product matching while you type · a
"See all results" row · and a WhatsApp fallback when nothing matches.

## What changed (the genuine gap)
- **Popular brands** row in the default (empty) search view — your top brands by
  product count, as tappable chips that run a brand search (Blinkit/Amazon
  pattern). Completes the set: Recent · Popular brands · Popular categories ·
  Trending.
- Light polish: chips now hover to green; brand chips are slightly bolder.

Verified: shop.html JS compiles; Playwright — opening search shows Popular
brands / Popular categories / Trending, brand chips ranked by product count,
tapping a brand runs its search; no console errors.
