# CLOUDBSS-TRUST-VISION-APPLY v1.5 — wiring + claim backend + Trust screen (T6)
**Supersedes v1.4 and trust-v1.3-wiring. One zip, upload everything to GitHub.**
Base: shoppingmain 41. Complete files, never diffs. Additive; php -l + node --check clean.

## Contents

**Trust v1.3 wiring** — routes/api.php · routes/web.php · routes/console.php
(nonce pages in the /task/{nonce} throttle group; schedules at 06:45 / 07:00 / Mon 07:15 EAT).

**Vision box claim backend (V4, CloudBSS half)** — vision_boxes migration · config/vision.php ·
VisionBox model · VisionBoxController (hello/poll) · VisionEventController (per-box tokens) ·
vision:claim + vision:boxes commands.

**NEW — Inventory Trust screen (backlog T6):**
- app/Http/Controllers/Panel/TrustPanelController.php  ← page + 7 papi endpoints
- resources/panel/trust.html                           ← the screen (house tokens.css/ui.js style)
- app/Services/Trust/TrustRuntime.php                  ← + resolveInvestigation() (additive method;
  same semantics as trust:resolve — clean ↑ reputation, confirmed_loss ↓ by value, error = no change,
  plus double-resolve protection)
- config/navigation.php                                ← menu entry: Stock → Inventory Trust
- routes/web.php                                       ← /panel/trust + papi routes (refreshed file)

## The screen — open /panel/trust after deploy (bookmark it)

Tiles: Verified stock (M9) · Shrink % (M1) · Attributed (M3) · Queue precision (M5) ·
Evidence coverage (M6) · Open queue. Shrink waterfall (7/14/28d). Today's queue with
one-tap resolve (clean / error / confirmed loss + value + notes — the label factory, no
artisan needed). Recent trust scores with decision filter. Vision: claim a box by typing
its on-screen code, fleet table with online/offline + revoke, live shelf-events feed.
It also appears in the left menu (Stock → Inventory Trust) where the nav config is wired.

## Deploy (same as before)

1. Upload extracted files to GitHub over the repo root (replaces 6, adds 9).
2. EasyPanel **Rebuild** → `php artisan migrate --force` → `php artisan storage:link` (once).
3. `.env`: `APP_TIMEZONE=Africa/Kampala` (+ optional legacy VISION_WEBHOOK_TOKEN/VISION_TENANT_ID).
4. Tenant 17 settings: `trust_digest=1` · `trust_staff_numbers` · optional `trust_digest_numbers`.
5. Open `/panel/trust` → tiles show "—" until the backtest/live scoring writes data:
   `php artisan trust:backtest --tenant=17 --months=12 --top=20 --write`

Actor data on the screen routes review only — never a ranking, never an accusation (Spec §5.2).
