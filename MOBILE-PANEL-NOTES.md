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
- Branding (shop name, initials, plan, phone) injected server-side from the tenant.
- Auth loss (401/419) -> auto-redirect to `/app/login`.

## What is PREVIEW (clearly labelled in the UI, NOT yet wired)
- **POS** — local quick-tally only. Recording a POS sale to the books needs the order-create
  contract (`save-order`) which I did not wire blind. Next step if you want it.
- **More** — deep-links into the existing full panel pages (`/panel`, `/panel/chats`,
  `/panel/cashbook`, `/panel/scheduled`, `/panel/marketing`, `/panel/setup`).

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

If Orders/Chats show the "Couldn't load" state, it's the session or the endpoint, not the UI:
check you're logged in (cookie present), that `/papi/orders` returns JSON in the browser, and
the queue worker / WhatsApp connection for step 6.
