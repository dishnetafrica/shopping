# CloudBSS — Operations Runbook

Deploy, configure, and operate CloudBSS. Companion to `AI-HANDOVER.md`.

---

## 1. Deploy a change
1. Edit files in the overlay (repo-relative paths).
2. Commit + push to GitHub `dishnetafrica/shopping` (or upload the changed files).
3. EasyPanel rebuilds the Docker image automatically.
4. After deploy, in the app container:
   ```
   php artisan migrate --force
   php artisan optimize:clear
   ```
Only `app/ config/ database/ routes/ bootstrap/ resources/` are shipped by the
Dockerfile. `tests/ qa/ load/ *.md` stay in the repo but are not deployed.

## 2. Environment variables
Set in EasyPanel → service → Environment.

**Core**
```
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
QUEUE_CONNECTION=redis        # REQUIRED for production safety (locks need Redis)
DB_CONNECTION=pgsql  DB_HOST=...  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...
REDIS_HOST=...  REDIS_PASSWORD=...  REDIS_PORT=6379
APP_TENANT_ROOT_DOMAIN=...    # base domain for tenants
```
**WhatsApp**
```
WHATSAPP_DRIVER=evolution               # or 'cloud'
EVOLUTION_BASE_URL=...   EVOLUTION_API_KEY=...        # rotate before launch
# Meta Cloud API (if used):
WHATSAPP_CLOUD_TOKEN=...  WHATSAPP_CLOUD_PHONE_ID=...  WHATSAPP_CLOUD_VERIFY_TOKEN=...
```
**Payments**
```
FLW_BASE_URL=...  FLW_PUBLIC_KEY=...  FLW_SECRET_KEY=...  FLW_WEBHOOK_HASH=...
STRIPE_SECRET_KEY=...  STRIPE_WEBHOOK_SECRET=...  STRIPE_CURRENCY=usd
```
**AI + marketing**
```
OPENAI_API_KEY=...  OPENAI_MODEL=...
MARKETING_EMAIL=...  MARKETING_PHONE=...  MARKETING_WA_NUMBER=...
```

## 3. Queue worker + scheduler (both required)
- **Queue worker** (Redis) — processes inbound messages, campaigns, notifications.
  Run **Horizon** (preferred) or a worker:
  ```
  php artisan horizon          # or: php artisan queue:work redis --tries=25
  ```
  Run this as a long-lived EasyPanel process; restart on deploy.
- **Scheduler** — advances scheduled orders and dispatches due campaigns. The
  command is `shopbot:process-scheduled`. Run Laravel's scheduler every minute:
  ```
  * * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1
  ```
  (or call `php artisan shopbot:process-scheduled` each minute directly.)

## 4. Webhook registration
**Evolution (per tenant instance):** point the instance webhook to
```
https://<app-domain>/api/webhook/whatsapp/evolution
```
**Meta Cloud API:** callback URL
```
https://<app-domain>/api/webhook/whatsapp/cloud
```
verify token = `WHATSAPP_CLOUD_VERIFY_TOKEN` (the GET handshake echoes hub.challenge).
**Payments:**
```
Flutterwave: https://<app-domain>/api/billing/flutterwave/webhook   (hash = FLW_WEBHOOK_HASH)
Stripe:      https://<app-domain>/api/billing/stripe/webhook         (secret = STRIPE_WEBHOOK_SECRET)
```
Each tenant is resolved by the `whatsapp_instance` that received the message.

## 5. Go-live checklist (before public launch)
- [ ] `QUEUE_CONNECTION=redis` set; Horizon/worker running; scheduler cron live.
- [ ] All migrations applied (incl. `message_receipts`, `orders.idempotency_key`,
      `campaign_messages`, `product_defaults`).
- [ ] Run `delivered_at` backfill once:
      `php artisan tinker --execute="DB::table('orders')->where('status','Delivered')->whereNull('delivered_at')->update(['delivered_at'=>DB::raw('updated_at')]);"`
- [ ] FLW + Stripe keys set; both webhooks registered and test-fired.
- [ ] **Rotate `EVOLUTION_API_KEY`** (current one shared during dev).
- [ ] Replace placeholder phone `256700000000` in the marketing site
      (`config/marketing.php`, `MarketingController`, `resources/marketing/index.html`, `index_*.html`).
- [ ] Per tenant: set `owner_alert_phone` so order/loop alerts reach the owner.
- [ ] Run staging load + chaos + SQL verification (`PHASE2-STAGING-RUNBOOK.md`);
      all dup-checks return 0 rows.

## 6. Backups & health
- Postgres: enable EasyPanel automated DB backups (daily minimum).
- Watch `storage/logs/laravel.log` for `bot.latency`, `bot.loop_paused`, errors.
- Horizon dashboard: queue depth, throughput, failed jobs.

## 7. Tenant onboarding (per shop)
1. Create the Tenant (Filament admin): name, `order_prefix`, plan, `whatsapp_instance`.
2. Connect WhatsApp (Evolution instance or Cloud number) and register the webhook.
3. Setup tab: delivery pricing (base, per-km, min, free-over, store location pin),
   `owner_alert_phone`, bot mode = auto.
4. Load Products + Categories (POS/import). Optionally set Smart Defaults.
5. Send a test message; confirm a reply + an owner alert + an order appears.
