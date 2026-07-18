# STOREFRONT-UX-DEPLOY.md — three deltas + one honest correction

## The correction first
My UX review under-credited your storefront: **Buy again already exists** (rail +
"Reorder last" + persisted last order), so do Bestseller badges, Recently-viewed /
Trending / Recommended rails, and a WhatsApp rescue on the MAIN search. Impressive
build. What follows are the genuine gaps only.

## Delta 1 — the login nudge (unlocks Buy-again for everyone)
The Buy-again rail only shows for signed-in customers — but your checkout needs just
name + phone, so most customers never sign in and never see it. Now the
**Order-placed screen** offers: "🔑 Save my orders — see them & buy again next time"
(only when not signed in), and it opens login **with the phone they just typed
prefilled** — enter the OTP and done. Every order now recruits its customer into the
returning-customer experience you already built.

## Delta 2 — no dead-end without a conversation
The main search already rescued to WhatsApp; the three FILTER/collection empty
states ("No products match these filters") just stopped. All three now add:
"We may still have it — ask us on WhatsApp" with a prefilled message.

## Delta 3 — badges that mean something
"New" now requires ≤14 days AND self-suppresses when over 40% of the catalogue would
qualify — a freshly imported catalogue no longer screams NEW on every card (your
screenshot). Bestseller badges (already built, popularity-based) start appearing
naturally as `track()` accumulates sales signals.

## Zero-code win you can do right now (the hidden trust strip)
The trust bar under the header is fully wired — it just waits for content:
  php artisan tinker
  >>> Tenant::find(17)->putSetting('theme', array_merge((array) Tenant::find(17)->setting('theme', []), ['trustLine' => '✓ Since 2013 · ✓ Kampala & Juba delivery · ✓ Order on WhatsApp']));
Hard-refresh — it appears. (Wording yours to change; · separates the items.)

## Deferred honestly
Card quantity steppers (advice #5): real markup surgery on the card renderer —
worth doing, but not blind at the end of a long session. Next storefront session.
Product-name differentiation (#4): data cleanup in your catalogue, no code.

## Files (2) + deploy
resources/storefront/shop.html (all three deltas; script node-validated) ·
tests/Feature/StorefrontUxTest.php (all three pinned)
Upload → Rebuild → `php artisan test --filter=StorefrontUx` → hard refresh →
place a test order signed-out: the 🔑 nudge appears on the success screen.
