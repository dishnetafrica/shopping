# Restaurant: two menus (Food + Beverages) — browsable tabs + sendable file

Both halves of what the restaurant asked for.

## 1) Browsable tabs on the storefront
Group the restaurant's categories into named menus. In the seller panel → Settings → Website & branding →
**Menus** → "+ Add menu":
- **Food Menu** → list its categories (Starters, Mains, Sides, Desserts…) one per line
- **Beverages Menu** → Soft Drinks, Juices, Hot Drinks, Cocktails…
(Category names must match the product categories exactly.)

The storefront then shows a **"Food | Beverages" tab bar** at the top; each tab lists that menu's
categories with their items, and customers order from both. (This reuses the existing category-grouping
that grocery shops use — I enabled it for the restaurant vertical and added the tab switch.)

## 2) Sendable menu file on WhatsApp
Seller panel → **Menu files** → "+ Add menu file": a **Label** (e.g. "Food Menu") + an image/PDF (Upload
button, or paste a URL). Add two (Food + Beverages).

When a customer messages "menu", "food menu", "drinks/beverages menu" (English or Swahili "orodha"), the
bot acknowledges and **sends the matching file** — image via photo, PDF via document. Generic "menu" sends
both; "food menu" sends only food; "drinks menu" only beverages.

## Files
- `resources/storefront/shop.html` — Food/Beverages tab bar + menu rendering for restaurants.
- `resources/panel/seller.html` — Menus editor + Menu-files editor (with upload).
- `app/Http/Controllers/Panel/PanelApiController.php` — saves `category_groups` + `menu_files`, returns them in the feed.
- `app/Services/Bot/AiBrain.php` — sends the menu file(s) on request + prompt awareness.
- `qa/menus.php` — 10/10.

## Deploy
Pull → restart → `optimize:clear`. **No migration.** Then in the restaurant's panel: define the two Menus
(category groups) + upload the two Menu files. Hard-refresh the storefront (caches ~5 min).

## Honest caveats
- The tabs are driven by **category names matching** — if a category in a menu has no products (or the name
  is misspelt), it just won't show under that tab. Keep the menu's category list in sync with the products.
- Menu-file **upload** reuses the existing image uploader (images). For a **PDF**, paste a hosted URL in the
  URL field (the bot sends it as a document); if your image uploader doesn't accept PDFs, the URL route is
  the way.
- The bot sends a file only when the word "menu" (or "orodha"/"beverages") appears — a vague "what do you
  have?" gets a normal text answer, not the file. Tell me if you want more trigger phrases.
- Couldn't run the live storefront/bot from here — after deploy, test the tab switch on the storefront and
  message "send me the food menu" / "drinks menu" on WhatsApp.
