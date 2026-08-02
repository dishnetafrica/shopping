# CloudBSS v4.9.1 — Dealer Programme drop 4b: LOGIN (dealer prices on the website)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.9.1.zip (cumulative — supersedes v4.9 and everything before it) · **Migration:** same two guarded ones (`php artisan migrate` — skip if already run). **No new migration.**

## What a dealer experiences

1. Storefront footer → **💼 Dealer sign-in** → enters their WhatsApp number.
2. **Your own bot sends them a 4-digit code on WhatsApp** (no SMS gateway, no passwords, no cost). They type it in — signed in for 30 days on that phone.
3. Everything re-prices instantly: cards and product pages show **struck-through retail + YOUR PRICE** in green, the sticky buy bar, the cart and the WhatsApp order message all carry their trade prices, and a black dealer bar sits under the header: *"💼 Kampala Moto Garage — DEALER pricing (10% off list) · My orders · Sign out."*
4. **My orders**: their last 5 quotations with an **Order again** button — one tap reloads a past order into the cart at today's dealer prices.
5. WhatsApp orders are marked **"(DEALER ORDER — my trade prices below)"** so your team sees it instantly. Sign out returns everything to retail, including cart lines.

## Security (spec §8, implemented exactly)

- **Code delivery is the identity:** possession of the WhatsApp number — the same trust base as your entire bot. Codes are 4 digits, cache-only, 5-minute life, **3 sends/hour/phone, 5 attempts/code**, single use. The "code sent" reply is identical whether or not a number is enrolled — nobody can probe who your dealers are.
- **Signed tokens** (HMAC over tenant|phone|expiry with the app key, 30 days) — 8/8 harness: round-trip, expiry, wrong-key, tampered payload/signature, garbage all rejected.
- **The public site is untouched:** /catalogue stays cacheable with retail prices only. Dealer prices arrive via a separate authed **/dealer-feed** (always no-store) computed per request through PriceResolver — floor-guarded like everything else.
- **/quote is now dealer-token aware server-side:** with a valid token the server re-prices every line itself through PriceResolver (client-sent prices ignored for dealers) — the browser can never invent a discount.
- New endpoints throttled (otp 6/min, verify 12/min, feed 30/min) and CSRF-exempt like /order and /quote. Sign-ins and code sends are BotTraced (`dealer_otp`, `dealer_login`).

## Deploy

GitHub upload → Rebuild (migrate only if you skipped it at v4.9). Test in 2 minutes: mark yourself as a dealer (customer card → 💼), open eaautopart.com → footer → Dealer sign-in → code arrives from your shop's WhatsApp → watch the whole site switch to your price.

## Files changed vs v4.9

NEW `app/Services/Pricing/DealerAuth.php` · StorefrontController (dealerOtp/dealerVerify/dealerFeed + /quote dealer re-pricing) · routes/web.php (6 throttled routes) · bootstrap/app.php (CSRF exempt) · autoparts.html (+src mirrors 01/02/05→08/06/09/10: sign-in sheet, price overlays incl. sticky buy bar, dealer bar, cart reprice on login/logout, history + order-again, footer entry, boot hook).

## Verified before ship

- 8/8 DealerAuth harness.
- Playwright full dealer journey on the exact SPA: wrong code rejected → right code signs in → dealer bar + 89 prices loaded → card/product/buy-bar/cart all at 14,220 (from 15,800) → WhatsApp text carries the DEALER ORDER marker → history sheet → **Order again** rebuilds a past quotation in the cart at dealer prices → sign out restores retail everywhere. Zero console errors.
- Retail regression journeys (signed-out) as Boxer and TVS riders: zero errors, zero failed requests — public experience byte-identical in behaviour.
- php -l ×4 · node --check ×7 · shell untouched this drop · zip md5 vs repo.

Next and last in the programme: **4c — CSV/paste quick-order + saved lists** (the wholesaler tools), on your word.
