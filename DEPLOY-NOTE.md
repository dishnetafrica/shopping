# CloudBSS — Hero UX fix (default = main, hero merchandises)

Two fixes from the live screenshot feedback. Single file:
`resources/storefront/shop.html`. No backend/config change; works with your
current saved config.

## 1. The Default collection is now the MAIN (first) card
Previously "Default for new visitors" only picked which hero opened — the card
stayed wherever it sat in the list (so Wholesale showed 5th). Now the default
collection **automatically leads the selector row**. So setting Default =
Wholesale makes Wholesale the first, highlighted card — no manual reordering.
It stays stable (the default is a fixed setting; the row doesn't reshuffle as
visitors click around).

→ Effect on your live site: since your Default is already Wholesale, deploying
this makes Wholesale the first card immediately. (To also rename it "Wholesale
Deals", edit the label in v3 → Shop for Every Need, or Load starter template.)

## 2. The hero merchandises instead of covering the screen
The big empty banner was the problem — a first-time visitor saw a large coloured
block with no product. The hero now shows **3 featured products from the
collection** floating on the right (desktop), each tappable to the product. So
the hero communicates what's inside even before you upload a hero photo, and it
no longer reads as an empty space. When you do add a hero lifestyle photo, the
product cards sit nicely over it. Featured products come from the collection's
best-sellers automatically — no setup.

(Mobile is unchanged — the best-sellers rail already sits right under the hero.)

Verified: shop.html JS compiles; Playwright at 1440px — the Default collection
leads the row and is highlighted; the hero shows 3 featured products; no errors.
