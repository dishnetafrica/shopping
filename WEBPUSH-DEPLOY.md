# WEBPUSH-DEPLOY.md — phones buzz, browser closed (dependency-free Web Push)

## What this is
True push notifications for new WhatsApp messages: staff phones buzz even with the
browser fully closed. Implemented the DishNet way — **zero composer additions**
(VAPID RFC 8292 + payload encryption RFC 8291 in pure PHP/openssl, same
standards-by-hand philosophy as the ARKBET TOTP build). Nothing can break the
Rebuild's composer install.

## How it flows
1. First click in /v3 after deploy → browser asks notification permission → on Allow,
   the shell silently registers `/sw.js` and enrols the device (`push/subscribe`).
2. Customer messages → MessageLog (the one write path) → `notifyInbound` → every
   enrolled device buzzes. **Throttled 60s per customer** so a burst is one buzz.
   Fail-soft: a push can never break the webhook; dead subscriptions (404/410)
   self-prune.
3. Tapping the notification opens/focuses /v3 straight into Chats.

## Per-platform truth
| Device | Works browser-closed? |
|---|---|
| **Android Chrome** | ✅ yes — this is the big win for Jayesh/Asish/Bidal |
| **iPhone (iOS 16.4+)** | ✅ but ONLY after **Add to Home Screen** (Share → Add to Home Screen, open from the icon once) — Apple's rule, the manifest in this zip makes it installable |
| Desktop Chrome/Edge | ✅ while the browser app runs (even with no tab open) |

## Deploy
1. Upload → Rebuild → **`php artisan migrate`** (2 new tables) →
   `php artisan test --filter=PushTest`
2. Open /v3 on your phone, click once, Allow notifications.
3. Owner sanity check: `POST /papi/push/test` (or from console:
   `fetch('/papi/push/test',{method:'POST',headers:{'X-Requested-With':'x'},credentials:'same-origin'})`)
   → your phone buzzes "Push notifications are working 🎉".
4. Then the real one: WhatsApp yourself from another number with the browser closed.

## Files (10)
WebPushService (the standards, ~200 lines) · migration (push_subscriptions +
push_keys, idempotent) · PushSubscription model · PushController (vapid-public /
subscribe / unsubscribe / test) · routes (4) · MessageLog (fail-soft inbound hook —
pinned by test: inbound buzzes, outbound never) · public/sw.js · 
public/v3-manifest.webmanifest · shell.blade.php (manifest link + silent enrolment
after permission) · PushTest (3 tests)

Supersedes earlier shells (contains waweb + media + attention + this).
