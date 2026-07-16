# CloudBSS — Product card: premium pass + brand

Makes the shared product card feel premium and adds the one piece of content it
was missing (brand). Single file: `resources/storefront/shop.html`. The card is
used everywhere (rails, grids, search, related), so this was done as a focused,
non-destructive pass — the existing add-to-cart / MOQ / stepper logic is untouched.

## What changed
- **Brand line** above the product name (small, uppercase, muted) — the retail
  pattern. Shows only when the product has a brand.
- **Premium hover** (desktop): the card lifts with a stronger shadow and the
  product image gently zooms — modern-commerce feel instead of a static tile.
  Gated to `hover:hover` so touch devices are unaffected.
- Rounded overflow so the image zoom and corners clip cleanly.

## What the card ALREADY had (from your audit list — no rebuild needed)
Discount badge + strikethrough price · Bestseller / New badges · "Only N left"
low-stock · **Save (wishlist)** · **Compare** · **Quick View** (tap the card) ·
pack/carton label · per-piece price · Min-order (MOQ) · Add / quantity stepper.
So the audit's "missing" items were mostly present — the real gaps were the
brand line and the premium visual feel, both now addressed.

## Still open (bigger, optional)
- A per-card **WhatsApp order** button and an explicit **"In stock"** pill (the
  store is already WhatsApp-first via checkout, and low/out-of-stock is shown, so
  I left these out to avoid clutter — happy to add if you want them).

Verified: shop.html JS compiles; Playwright at 1440px — brand renders on branded
cards (and is absent on unbranded), Add/stepper + wishlist + low-stock all intact,
hover transition present; no console errors.
