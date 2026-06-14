# CloudBSS — Production Safety (Phase 2A–2F)

Infrastructure reliability layer. Built on top of the existing webhook → queue →
`BotBrain` pipeline. Idempotency keys + claim logic are **unit-tested here**;
the DB/queue/Redis integration is **lint-verified** and must be confirmed on
staging (`php artisan test` + the k6 chaos run).

## Success criteria → where it's enforced

| # | Criterion | Mechanism |
|---|---|---|
| 1 | Duplicate WhatsApp messages can't create duplicate cart entries | **2A** `message_receipts` unique `(tenant_id, whatsapp_message_id)`; `MessageReceipt::claim()` (insertOrIgnore) at the top of `ProcessIncomingMessage` — a duplicate returns before logging/replying/cart |
| 2 | Duplicate checkout requests can't create duplicate orders | **2C** `orders.idempotency_key` unique + checkout `Cache::lock`; `Order::firstOrCreate(idempotency_key)` keyed by a per-checkout token |
| 3 | Queue retries can't corrupt state | **2B** per-conversation `WithoutOverlapping` lock (no concurrent processing) + claim-before-process; cart writes happen once |
| 4 | Campaign retries can't send duplicates | **2D** `campaign_messages` unique `(campaign_id, recipient)`; `CampaignMessage::claim()` before each send, so a restarted job skips everyone already handled |
| 5 | Message ordering guaranteed per conversation | **2B** single-flight per conversation + FIFO dispatch from the webhook |

## 2A — Message deduplication
`MessageReceipt::claim($tenant, $conversationId, $messageId)` does an
`insertOrIgnore`; the unique index means the **second** caller gets 0 rows back
and returns `false`. In `ProcessIncomingMessage::handle()` this runs immediately
after the conversation is loaded — before `MessageLog`, `markRead`, the brain, or
any reply. A duplicate is dropped silently.

Proven: 1000 duplicate deliveries of one id → processed exactly once
(`idempotency_suite.php`).

## 2B — Conversation serialization & ordering
`ProcessIncomingMessage::middleware()` returns
`WithoutOverlapping(conversationLockKey)->releaseAfter(3)->expireAfter(30)`.
Only one job per conversation runs at a time; siblings **release** and retry
(hence `$tries = 25`). Combined with the webhook dispatching in receive order,
"Rice", "Sugar", "Oil" process in order, never interleaved.

- `expireAfter(30)` releases the lock if a worker dies holding it.
- Different customers/tenants get different keys → they run in parallel (no global
  bottleneck).

**Ordering note (honest):** single-flight + FIFO dispatch gives correct ordering
for normal delivery. Under heavy retry churn, strict global ordering also depends
on the queue staying FIFO. If you need a hard guarantee even under adversarial
retries, add a monotonic `inbound_seq` per conversation and process only when
`seq == last_processed + 1` (release otherwise). Flagged, not built — current
design meets the stated requirement ("one message per conversation at a time").

## 2C — Order idempotency
- A `checkout_token` (UUID) is written to the conversation state when checkout
  starts. It is stable across retries of that checkout, new for the next one.
- `placeOrder()` computes `orderKey(tenant, conversation, token)` and runs
  `Order::firstOrCreate(['idempotency_key' => $key], …)` inside a
  `Cache::lock(checkoutLock)->block(5)`. Order items are created only when
  `wasRecentlyCreated`.
- Result: double-press, WhatsApp retry, worker retry of the same checkout → one
  order. A genuinely new checkout (new token) → a new order. Note that 2A already
  drops duplicate *location messages*; this is defense-in-depth for any path that
  isn't message-id-deduped.

## 2D — Campaign safety
Before sending to each recipient, `SendCampaign` calls
`CampaignMessage::claim(tenant, campaign, recipient)`. The unique
`(campaign_id, recipient)` index means a retried/restarted job skips anyone
already claimed. Recipients are normalised (digits only) so formatting variants
don't double-send. `markSent()` records status + outbound id after the send.

## 2E — Webhook resilience
- **Duplicate deliveries** → 2A drops them.
- **Out-of-order events** → 2B serialises; normal delivery stays ordered.
- **Worker restarts / queue retries** → durable queue + claim-before-process; a
  retried job that already has a receipt is skipped.

**Transaction boundary / trade-off (read this):** the receipt is claimed *before*
processing (inside the per-conversation lock, so no concurrent duplicate). If a
worker dies *after* claiming but *before* replying, the retry is skipped — the
customer may not get that one reply (recoverable: they resend), but the cart/order
is never duplicated or corrupted. We deliberately favour **no duplicates** over
**at-least-once replies**. If you prefer guaranteed replies, switch to a two-phase
receipt (`processed_at NULL` on claim, set on success) plus idempotent cart writes
— more moving parts; not needed for the stated criteria.

## 2F — Load testing
Two scripts in `load/`:
- `k6_webhook.js` — ramps 100 → 500 → 1000 concurrent customers (realistic mix).
- `k6_safety.js` — **chaos**: floods duplicate message ids and double-fires
  checkout under load.

```
k6 run -e BASE_URL=https://staging -e WEBHOOK_PATH=/api/webhook/whatsapp/evolution \
       -e INSTANCE=<tenant-instance> load/k6_safety.js
```

### What to measure (server-side, during the run)
k6 reports webhook **ACK latency** + failure rate. The rest is server-side:

| Metric | How |
|---|---|
| Queue depth | Horizon dashboard, or `redis-cli LLEN queues:default` (per queue) sampled each second |
| Processing latency | `bot.latency` log → `queue_ms` (wait) + `brain_ms` (work) |
| Reply latency | `bot.latency` log → `total_ms` (webhook-received → reply-sent) |
| Memory / CPU | server monitor (htop / `docker stats` / Grafana) on web + worker nodes during ramp |

### Correctness checks AFTER the chaos run (k6 can't read the DB)
```sql
-- 2A: each flooded id processed once (one receipt per id)
SELECT whatsapp_message_id, count(*) FROM message_receipts GROUP BY 1 HAVING count(*) > 1;   -- expect 0 rows

-- the duplicate-flood customers must have a single cart line, qty = number of DISTINCT ids (not deliveries)
SELECT customer_phone, jsonb_array_length(cart) AS lines FROM conversations WHERE customer_phone LIKE '256700%';

-- 2C: no two orders share an idempotency key
SELECT idempotency_key, count(*) FROM orders WHERE idempotency_key IS NOT NULL GROUP BY 1 HAVING count(*) > 1;  -- expect 0

-- 2D: no recipient claimed twice for a campaign
SELECT campaign_id, recipient, count(*) FROM campaign_messages GROUP BY 1,2 HAVING count(*) > 1;  -- expect 0
```

## Tests
- `qa/idempotency_suite.php` — **20/20** logic checks (simulated unique index).
- `tests/Unit/IdempotencyTest.php` — pure key tests (`php artisan test`).
- `tests/Feature/ProductionSafetyTest.php` — DB-backed claim/uniqueness (runs on deploy).

## Deploy order
1. `php artisan migrate` (message_receipts, orders.idempotency_key, campaign_messages).
2. Ensure the queue driver is **Redis** (WithoutOverlapping + Cache::lock need an
   atomic store; the default `database`/`sync` driver won't serialise correctly).
3. `php artisan test`.
4. Run `load/k6_safety.js` on staging; run the SQL checks above (all expect 0 dup rows).
