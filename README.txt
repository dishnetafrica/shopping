EA AUTO PART — full customer-facing storefront (drop-in)
========================================================
This REPLACES the earlier resources/storefront/autoparts.html with the FULL
design: home, motorcycle overview, browse-by-system, parts listing, part detail
(with compatibility + "also bought"), exploded-diagram browser (plate nav → diagram → clickable numbered part list), search, and AI assistant — all responsive.

It renders from your LIVE catalogue: on load it fetches the tenant's
`/{shop}/catalogue` feed (the controller already passes catalogueUrl), groups
products by Category (= system) and shows them with their prices, stock and the
exploded-diagram photos you imported. So it stays in sync with what you manage in
CloudBSS — no rebuild needed when you change prices/stock/photos.

INSTALL
  Replace this one file in your repo:
    resources/storefront/autoparts.html   (upload / commit, then deploy)
  No other change — the StorefrontController patch already injects the config
  (brand, colour, catalogueUrl, shopUrl, WhatsApp, models, systems).

ORDERING
  "Add to Cart" links to /{shop}/shop?add=<id> and system tiles to
  /{shop}/shop?cat=<system> — one cart, one checkout (your existing shop).

NOTES
  - Model/hero photos are embedded (small) so model cards look right without
    hosting extra images; part & system-cover images come from your live feed.
  - Prices show your tenant currency. Set real prices in Products and they show
    here automatically.
