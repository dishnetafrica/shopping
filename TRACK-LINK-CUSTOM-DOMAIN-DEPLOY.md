# Customer track links now use the shop's own custom domain

When a shop has a custom domain set, the order-tracking links sent to customers now
point at that domain (e.g. https://europearlafrica.com/papi/track?...) instead of the
platform domain. Shops with no custom domain are unchanged.

## Files changed (4)
- `app/Models/Tenant.php` — NEW helper `publicUrl($path)`: returns
  `https://{custom_domain}{path}` when a custom domain is set, else `url($path)`
  (the platform URL). Single source of truth for branded public links.
- `app/Jobs/NotifyCustomerOrderReceived.php` — the website "we’ve received your order"
  WhatsApp now builds its 📍 Track link via `publicUrl()`.
- `app/Services/Bot/AiBrain.php` — the WhatsApp bot order confirmation and the
  duplicate-order reply now build their track links via `publicUrl()`.
- `app/Http/Controllers/Panel/PanelApiController.php` — the rider-dispatch action’s
  returned track link now uses `publicUrl()` too (so a seller copying it shares the
  branded link).

## Why this was needed
These links are generated server-side (queue worker / webhook), where there is no
browser host to derive from, so Laravel `url()` fell back to APP_URL (the platform
domain). The on-screen "Track my order" button after web checkout is intentionally
LEFT AS-IS: it is built during the customer’s own request, so it already uses whatever
domain they ordered on (the custom domain if they came in via it).

## Scope / safety
- No database change, no migration, no new settings.
- `publicUrl()` falls back to `url()` whenever `custom_domain` is empty — so every shop
  without a custom domain behaves exactly as before.
- `/papi/track` is a global, host-agnostic route, so it resolves on the custom domain.

## IMPORTANT prerequisite
Because these links now point at the custom domain, any shop that has a custom domain
set in its panel MUST have that domain’s DNS live (A-record → server, HTTPS issued).
If a custom domain is saved but not yet pointed, its track links would not open until
DNS resolves. (Shops already serving on their custom domain are fine.)

## Deploy
1. Unzip at the repo root (overwrites the 4 files).
2. Commit + push → EasyPanel rebuild.
3. Run `php artisan optimize:clear` after deploy.
4. The website "order received" WhatsApp (with the track link) is a QUEUED job, so the
   queue worker service must be running for it to send.
5. Test: place a web order on a custom-domain shop → the WhatsApp confirmation’s Track
   link should show that shop’s domain. Place one on a shop with no custom domain → it
   should still show the platform domain.

## Rollback
Restore the 4 files from the previous version and redeploy. The new method is additive,
so leaving Tenant::publicUrl() in place is harmless even if call sites are reverted.
