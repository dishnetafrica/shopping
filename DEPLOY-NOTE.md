# CloudBSS — Compact hero banner (Blinkit-style)

The hero was too tall and dominated the screen. It's now a compact promotional
banner so products sit near the fold. Single file: `resources/storefront/shop.html`.
No backend/config change.

## What changed
- **Banner height roughly halved.** Desktop hero is now ~232px (was ~450);
  mobile ~180px (was ~230). Padding, headline size, bullet spacing and the
  featured-product cards were all tightened to match — a short, wide promo strip,
  not a full-height hero.
- Text is now vertically centered in the shorter band; the featured products
  still float on the right (desktop) and fit within the compact height.
- Everything else (default-leads-first, featured products, auto-scroll, scrim)
  is unchanged.

Net effect: the collection selector + a compact banner + the best-sellers row all
fit near the top, like Blinkit/quick-commerce — the banner promotes, the products
are right there.

Verified: shop.html JS compiles; Playwright — desktop hero measures 232px,
mobile 181px, featured products present on desktop; no errors.
