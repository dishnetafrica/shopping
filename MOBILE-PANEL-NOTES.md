# Mobile Seller Panel — live wiring

A phone-first Seller Panel served at **`/panel/m`**, behind the same session as `/panel`
(`web` + `auth` + `SetTenantFromUser`). The existing `/panel` is untouched.

## Files (deploy = push to repo, repo-relative paths, no wrapper folder)
- `resources/panel/mobile.html`  ............ the mobile UI (NEW)
- `app/Http/Controllers/Panel/SellerPanelController.php`  ... adds `mobile()` (serves the asset + injects tenant branding via `window.SHOP`)
- `routes/web.php`  ......................... adds `Route::get('/panel/m', [SellerPanelController::class,'mobile'])`

No migration. After push: `php artisan optimize:clear` (and `route:clear` if routes are cached).

## What is LIVE (wired to /papi/*, GET, same-origin session cookies — no token, no CSRF)
- **Orders** — `GET /papi/orders`; polls every 25s so new WhatsApp orders appear on their own.
  - Confirm / Start preparing / Mark delivered / Cancel -> `GET /papi/update-status?row=ID&status=…`
  - Send out for delivery + Assign rider -> `GET /papi/dispatch?row=ID&rider=NAME&riderphone=PHONE`
    (this is the call that sets "Out for delivery", creates the track token, and fires the
    customer's "on the way" WhatsApp via OrderObserver). It is **plan-gated** — on a plan without
    dispatch the panel shows "Dispatch needs a higher plan" instead of failing silently.
  - Rider picker -> `GET /papi/riders`
- **Chats** — `GET /papi/chats` inbox (unread counts, BOT/YOU state from `last_sender`/`agent_active`);
  tapping a chat opens the full `/panel/chats` page. Polls every 25s.
- **POS** — `GET /papi/products` loads your real catalogue (search-and-tap, no customer details).
  Charge -> `GET /papi/save-order?channel=pos&status=Delivered&payment=…&total=…&items=[{name,qty,price}]`
  which creates a real order (OrderObserver assigns the order_no) that appears in the feed.
  Creating a POS order is plan-gated on `pos`; on a lower plan the sheet shows "POS needs a higher plan".
  A counter sale is recorded with `status=Delivered` and no customer name/phone (none required).
  After the order saves, POS also posts the cash received to the cashbook:
  `POST /papi/cashbook/add` (type=in, category=sale, method=<payment>, note="POS sale <order_no>").
  This is best-effort/non-fatal — if the ledger post fails, the sale order is still saved.
  (`papi/*` is CSRF-excepted in bootstrap/app.php, so POST works on the session cookie alone.)
- **Stock guard** — when a product has tracked stock > 0, the cart cannot exceed it (tap shows
  "Only N in stock"). Stock 0 is treated as untracked and sells freely, so shops that don't
  track inventory are never blocked.
- Branding (shop name, initials, plan, phone) injected server-side from the tenant.
- Auth loss (401/419) -> auto-redirect to `/app/login`.

## Notes
- **More** — deep-links into the existing full panel pages (`/panel`, `/panel/chats`,
  `/panel/cashbook`, `/panel/scheduled`, `/panel/marketing`, `/panel/setup`).
- POS records the sale as an **order** AND a cashbook **ledger** entry (cash/MoMo in). The order is
  the sales record; the ledger entry is the cash-drawer record.

## Add to phone home screen
The page is `apple-mobile-web-app-capable`; on a phone, open `/panel/m` and "Add to Home Screen"
for an app-like launch. (It reuses the panel session cookie, so no separate login.)

## Honest test checklist (cannot run Laravel/DB here — verify on staging)
1. Log into `/app`, then open `/panel/m` on a phone (or narrow browser).
2. Orders tab loads real orders; counts + "Sales today" reflect today's non-cancelled totals.
3. Tap an order -> Confirm; status pill + WhatsApp status message should update (OrderObserver).
4. Advance to "Send out for delivery" -> rider sheet -> pick a rider -> order goes
   "Out for delivery", customer gets the WhatsApp, rider tag shows on the card.
5. On a plan without dispatch: the rider action should toast the plan message, not error.
6. Place a real WhatsApp order on the connected number; within ~25s it should appear under New
   without reloading.
7. Chats tab lists real conversations with unread badges; tapping opens /panel/chats.
8. POS tab: search loads real products; tap to add; "Review & charge" -> pick payment -> Charge.
   A new order (channel=pos, Delivered) is created and appears in the Orders feed, and a cashbook
   "in" entry for the same amount appears under /panel/cashbook.
9. Try adding a low-stock item past its quantity -> should stop with "Only N in stock" (only
   for products whose Stock > 0).

If Orders/Chats show the "Couldn't load" state, it's the session or the endpoint, not the UI:
check you're logged in (cookie present), that `/papi/orders` returns JSON in the browser, and
the queue worker / WhatsApp connection for step 6.


## Install to phone home screen (the DishNet/PWA way)
The page is now a proper installable app — it reuses the existing PWA plumbing
(`/manifest.webmanifest`, `/sw.js`, icons) so it behaves like the DishNet app.

Files touched for this:
- `resources/panel/mobile.html` — adds `<link rel="manifest" href="/manifest.webmanifest?app=m">`,
  apple-touch-icon, status-bar + web-app-capable metas, and registers `/sw.js`.
- `app/Http/Controllers/Panel/PwaController.php` — `manifest()` now honors `?app=m` and sets
  `start_url=/panel/m`, so the installed icon opens the mobile panel (not the desktop `/panel`).
- `app/Http/Controllers/Panel/SellerPanelController.php` — `mobile()` brandizes the home-screen
  label + tab title with the tenant's own name.

How the owner installs it:
- **iPhone (Safari):** open `/panel/m`, tap Share -> **Add to Home Screen** -> Add. Tapping the
  icon launches full-screen (no Safari bars), straight into the panel — no URL to remember.
- **Android (Chrome):** open `/panel/m`, menu -> **Install app** / **Add to Home screen**.

Notes:
- The service worker is **network-first** and explicitly never caches `/papi/*` or `/api/*`, so
  Orders/Chats/POS always hit live data; only the static shell/icons are cached for fast launch.
- iOS standalone apps use a separate cookie jar. If the session has expired, launching the icon
  redirects to `/app/login`; after signing in, open the icon again (it lands on `/panel/m`).
  Sessions are long-lived, so day-to-day the icon opens straight into the panel.
