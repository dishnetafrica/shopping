# CloudBSS — Homepage becomes a full-width showroom (desktop UX direction change)

Removes the old ERP-style two-sidebar desktop layout from the **homepage** so the
"Shop for Every Need" merchandising owns the screen. Product listings and search
keep their filter sidebar. Single file: `resources/storefront/shop.html`.
Mobile is unchanged.

## What changed
- **Homepage (desktop) is now full-width.** The permanent left department
  sidebar and the right cart rail are removed on the homepage — it reads as a
  merchandising showroom, not an inventory screen.
- **The department-tile grid is gone from the homepage** when Shop for Every Need
  is enabled. Category browsing now lives where it belongs — the listing view's
  filter sidebar and search. (When merchandising is off, the classic category
  grid stays — backward compatible.)
- **Sidebar returns on product listings / search** (filters belong there).
- **The cart rail only appears once the order has items** — an empty cart never
  reserves screen space. On the homepage it's always hidden.
- **Collection cards are now image-led.** Each card shows 2–3 real product photos
  from that collection in the foreground (auto-pulled from your catalogue), over
  the lifestyle background photo you upload per collection, with the title,
  one-line description and Browse arrow. Icons are now a small accent, not the
  hero — photography tells the story.

## How it works (no new config)
- A `body.dhome` class (homepage) collapses the desktop grid to one full-width
  column and hides the sidebar + cart; a `body.dcartempty` class hides the cart
  rail until there are items. Both are consumed only inside the desktop media
  query, so the phone layout is byte-for-byte unchanged.
- Foreground card photos come from the products each collection already matches —
  no extra setup. Upload a lifestyle background per collection in v3 → Shop for
  Every Need for the full premium effect.

## Requires
Deploy on top of the merchandising bundle (this replaces only its `shop.html`).
No backend/config change.

Verified: shop.html JS compiles; Playwright at 1440px — homepage is full-width
(no sidebar/cart, cards carry foreground thumbnails, no department grid), a
listing restores the sidebar, the cart rail appears only after adding an item,
returning home goes full-width again; at 390px the mobile layout is unchanged
(sidebar stays hidden, cards render). No JS errors.
