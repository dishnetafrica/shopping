# CloudBSS × n8n smart-bot — P2 (dedupe + memory + watchdog + digest)

Builds on P1. Design intent kept, but **state lives in CloudBSS** (the single source of truth) instead
of bolting Postgres/Redis into n8n — so the shared workflow stays dependency-light.

## 1. Fire-once alerts (no more alert spam)
`/api/bot/alert` now accepts `dedupe_key` + `dedupe_ttl`. `Cache::add` is atomic, so the first call
within the window sends and the rest collapse to `{ok, sent:0, deduped:true}`. The n8n Brain passes
`dedupe_key = "<signal>:<phone>"` (e.g. `lead:2567…`) with a 1-hour TTL — so a customer who keeps
saying "price?" alerts sales once per hour, not every message.

## 2. Conversation memory
`forwardToN8n` now includes the **last 10 messages** for that customer (from CloudBSS's message log)
as `history` in the payload. The Brain folds them into a "RECENT CONVERSATION" block in the AI brief,
so the bot stops re-asking what the customer already told it. No memory store needed in n8n.

## 3. Unanswered-customer watchdog  (`bot:watchdog`, every 15 min)
A conversation with `unread > 0` has had no bot/agent/owner reply since the customer's last message.
For each n8n tenant, within business hours, the watchdog finds those waiting longer than the threshold
and alerts dispatch→sales→management — **once per waiting message**. Per-tenant settings:
`watchdog_enabled` (default on), `watchdog_wait_min` (10), `watchdog_hours` ("7-21"),
`watchdog_max_age_min` (180), `tz_offset` (3 = EAT).

## 4. Daily digest  (`bot:digest`, hourly, fires at each tenant's local hour)
Each n8n tenant gets a daily summary to management: customers, messages in/out, still-waiting count.
Settings: `digest_enabled` (default on), `digest_hour` (18), `tz_offset` (3). One digest per local day
(cache-guarded). `--force` sends immediately for testing.

## Files
- `app/Http/Controllers/Api/BotBridgeController.php` — alert dedupe.
- `app/Jobs/ProcessIncomingMessage.php` — `history` in payload + `recentHistory()`.
- `app/Console/Commands/BotWatchdogCommand.php`, `BotDigestCommand.php` — NEW.
- `routes/console.php` — schedules (every 15 min / hourly).
- `app/Filament/Admin/Resources/TenantResource.php` — watchdog/digest admin fields.
- `cloudbss-smart-bot.n8n.json` — Brain updated (dedupe_key on alerts + history/memory).
- `qa/n8n_p2.php` — 17/17 · `qa/n8n_bridge.php` — 17/17.

## Deploy
Pull → restart → `php artisan optimize:clear`. **No migration.** Ensure the Laravel scheduler is
running (`php artisan schedule:work`, or the cron entry `* * * * * php artisan schedule:run`).
Re-import the updated `cloudbss-smart-bot.n8n.json` (or just paste the new Brain code into the
existing Brain node) and re-activate.

## Test
- Memory: message twice; the second reply should reflect the first.
- Dedupe: send "price?" 3× fast → sales gets ONE alert.
- Watchdog: set `bot_mode=off` briefly, message the number, wait `watchdog_wait_min`, run
  `php artisan bot:watchdog` → dispatch gets an "unanswered" alert.
- Digest: `php artisan bot:digest --force` → management gets today's summary.
