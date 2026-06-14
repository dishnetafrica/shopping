# CloudBSS — Troubleshooting

Symptom → likely cause → fix. Logs live in `storage/logs/laravel.log`
(`grep bot.latency`, `bot.loop_paused`). Horizon shows queue + failed jobs.

---

### Bot doesn't reply
1. **Bot mode off / chat taken over** — Chats: is bot mode `auto`? Is the chat in
   "agent active" (someone took over)? Re-enable / hand back.
2. **Queue not running** — inbound is a queued job. Confirm Horizon/worker is up:
   `php artisan horizon:status`. If `QUEUE_CONNECTION` isn't `redis`, fix it.
3. **Webhook not arriving** — confirm the Evolution/Cloud instance webhook points
   to `/api/webhook/whatsapp/{driver}`. Check `BotTrace` logs for `no_tenant`
   (instance not matched to a tenant) or `queued`.
4. **Loop guard paused the chat** — too many replies too fast auto-pauses + alerts
   the owner (`bot.loop_paused`). Take over, then re-enable.
5. **Debounce** — the bot ignores a message <2s after its own last reply (anti-echo).
   Normal.

### Delivery date shows "—" on delivered orders
Fixed: `OrderObserver` now stamps `delivered_at` when status → Delivered. For
orders delivered **before** the fix, run the one-time backfill:
```
php artisan tinker --execute="DB::table('orders')->where('status','Delivered')->whereNull('delivered_at')->update(['delivered_at'=>DB::raw('updated_at')]);"
```

### Duplicate orders / duplicate cart items
Prevented by the production-safety layer (message dedup + order idempotency key +
per-conversation lock). If you ever see one, check that **migrations are applied**
(`message_receipts`, `orders.idempotency_key`) and `QUEUE_CONNECTION=redis` — the
locks are no-ops without Redis. Verify with the SQL in `qa/PRODUCTION-SAFETY.md`.

### Campaign sent twice / to the same person twice
`campaign_messages` unique `(campaign_id, recipient)` blocks this. Confirm that
migration ran. A restarted `SendCampaign` job skips anyone already sent.

### Customer name shows as "Customer"
The order was created without a captured name (e.g. bot order before name capture).
Cosmetic. Full name+location capture at checkout is on the roadmap (not yet built).

### Payment not confirming
1. Webhook registered? Flutterwave → `/api/billing/flutterwave/webhook` (hash =
   `FLW_WEBHOOK_HASH`); Stripe → `/api/billing/stripe/webhook` (secret =
   `STRIPE_WEBHOOK_SECRET`).
2. Keys set for the right environment (test vs live).
3. Check the provider dashboard for delivery attempts + the app logs for the
   webhook hit.

### Tracking link not working / no rider
Dispatch is a **Pro** feature. Confirm the tenant's plan and that a rider was
assigned. The link is keyed by the order's `track_token`.

### Scheduled order/campaign didn't fire
The scheduler must run every minute (`schedule:run` cron) calling
`shopbot:process-scheduled`. Confirm the cron is live.

### "Slow" replies under load
Check Horizon queue depth + `bot.latency` (`queue_ms` vs `brain_ms` vs `send_ms`).
High `queue_ms` = not enough workers. The brain itself is ~6 ms/msg at 1000 SKUs.

### Numbers look wrong / placeholder phone visible
`256700000000` is a dev placeholder still in the **marketing site** files. Replace
before public launch (see Operations Runbook go-live checklist).
