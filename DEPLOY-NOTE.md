# CloudBSS — Homepage UX pass: product-first & compact

Addresses the storefront audit: compact navigation, no wasted banner space,
collections with counts, and products pulled up to the first screen. Single file:
`resources/storefront/shop.html`. No backend/config change; mobile unaffected.

## What changed (this pass)
1. **Compact selector** — the "Shop for Every Need" cards are ~52px tall (was
   ~76), smaller icon + tighter padding. Reads as navigation, not big tiles.
2. **Clean banner** — removed the floating product cards inside the banner (you
   crossed them out). The banner now carries only the message + CTA.
3. **Product-first** — the "Best sellers" product row now renders **directly
   under the banner**, before collections, so real products appear on the first
   screen instead of after category chrome.
4. **Collections fixed** — the empty grey placeholders are now compact tiles with
   an icon, the collection name, and a **live product count** ("245 products").
5. **Tighter spacing** — reduced section-title padding for higher density / less
   scrolling.
6. Wholesale still leads (default = first card, from the earlier fix).

## Deliberately deferred (called out, not silently skipped)
These touch the FROZEN storefront core used across every page, so they deserve
their own carefully-tested passes rather than a rushed rewrite on a live store:
- **Global product-card redesign** (per-card WhatsApp / Quick View / Save /
  discount + stock badges, new elevation/hover system).
- **Premium search overlay** (voice/scan already present; recent/trending exist —
  a full Blinkit-style search surface is a separate build).
I can take either next as a focused, tested pass.

Verified: shop.html JS compiles; Playwright at 1440px — selector card 52px, no
banner product cards, best-sellers render before collections, collection tiles
show live product counts; no console errors.
