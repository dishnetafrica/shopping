# CloudBSS — consolidated deploy bundle

Unzip at the repo root (files are at repo-relative paths, **no wrapper folder**),
commit, push. EasyPanel rebuilds. Then in the container:

```
php artisan migrate --force
php artisan optimize:clear
# one-time: backfill delivery dates for orders delivered before this build
php artisan tinker --execute="DB::table('orders')->where('status','Delivered')->whereNull('delivered_at')->update(['delivered_at'=>DB::raw('updated_at')]);"
```
Ensure `QUEUE_CONNECTION=redis` and a queue worker (Horizon) + scheduler are running.

## What's included (everything from this session, self-consistent)
**Deploys** (app/ config/ database/ resources/ + README):
- Brain + Default Strategy + Production Safety (Bot/*, Models, Jobs, Support/Idempotency,
  Filament ProductDefaultResource, 4 migrations 2026_06_14_000001..4)
- delivered_at fix: app/Observers/OrderObserver.php
- Decline + fuzzy-match fix: Bot/BotBrain.php + Bot/CatalogueMatcher.php (carry the fix
  together with the production-safety changes)
- Seller password change: app/Providers/Filament/AppPanelProvider.php (->profile())
  + resources/panel/seller.html ("Account & Password" link to /app/profile)
- Canonical README.md

**Reference only** (ride in the repo, NOT shipped by the Dockerfile):
- docs/ (handover, operations, troubleshooting, pilot, module specs)
- tests/ (PHPUnit), qa/ (standalone suites), load/ (k6)

## Migrations in this bundle
- 000001 product_defaults · 000002 message_receipts
- 000003 orders.idempotency_key · 000004 campaign_messages

## Not included / still pending (ops side)
- OTP login (not built — awaiting your choice of sender channel)
- Go-live: FLW/Stripe keys + webhooks, rotate EVOLUTION_API_KEY, replace placeholder
  256700000000 in marketing site, owner_alert_phone per tenant, staging verification.
