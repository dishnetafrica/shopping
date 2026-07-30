# CLOUDBSS-TRUST-VISION-APPLY v1.4 — trust wiring + vision box claim backend
**Supersedes `trust-v1.3-wiring.zip` (this zip contains everything it had, plus the claim backend).**
Base: shoppingmain 41. Complete files, never diffs. Additive only; php -l clean (PHP 8.4).

## What's in this drop

**Trust v1.3 wiring (was pending from handover §4 step 3):**
- routes/api.php · routes/web.php · routes/console.php — trust routes + schedules wired
  (nonce pages inside the /task/{nonce} throttle group; schedules with explicit
  Africa/Kampala tz + withoutOverlapping + onOneServer).

**NEW — Vision box zero-touch claim backend (handover §8 / backlog V4, CloudBSS half):**
- database/migrations/2026_07_30_000003_create_vision_boxes_table.php   (hasTable-guarded)
- config/vision.php            ← token/tenant survive config:cache (fixes a latent env() trap)
- app/Models/VisionBox.php
- app/Http/Controllers/Api/VisionBoxController.php     ← POST /api/vision/box/hello + /poll
- app/Http/Controllers/Api/VisionEventController.php   ← now accepts per-box webhook tokens;
  legacy VISION_WEBHOOK_TOKEN/VISION_TENANT_ID pair still works (hand-configured pilot box)
- app/Console/Commands/VisionClaimCommand.php          ← vision:claim CODE --tenant=17 --store=kampala
- app/Console/Commands/VisionBoxesCommand.php          ← vision:boxes [--revoke=uid|--forget=uid]

## Deploy

1. Unzip over repo root (only safe if your repo == shoppingmain 41).
2. `.env`: `APP_TIMEZONE=Africa/Kampala` · optional legacy pair `VISION_WEBHOOK_TOKEN` +
   `VISION_TENANT_ID=17` (claimed boxes don't need it).
3. EasyPanel → **Rebuild** → `php artisan migrate --force` → `php artisan storage:link` (once).
4. Tenant 17 settings: `trust_digest=1` · `trust_staff_numbers` · optional `trust_digest_numbers`.
5. GATE-1: `php artisan trust:backtest --tenant=17 --months=12 --top=20` (then `--write`).

## Box claim flow (once a one-click box boots in a store)

Screen shows `CLAIM CODE: AB7K2M` → `php artisan vision:claim AB7K2M --tenant=17 --store=kampala`
→ box self-configures in ≤10 s → events land in vision_events with payload._box_uid.
Fleet: `php artisan vision:boxes`. Lost/stolen: `--revoke`. Re-register after corrupt secret: `--forget`.

## Security model (v1)

Box identity = machine-id + box-held random secret (server stores sha256 only, write-once per uid).
Claim codes: 6 chars, no 0/O/1/I, single-use, 24 h TTL (VISION_CLAIM_TTL_HOURS), rate-limited
endpoints. Per-box webhook tokens are revocable individually without touching other stores.
