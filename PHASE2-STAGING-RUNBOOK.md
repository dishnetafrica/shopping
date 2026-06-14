# CloudBSS — Phase 2 Staging Verification Runbook

These four checks need the deployed app + Redis + Postgres + a traffic generator,
which do not exist in the build sandbox. Run them on **staging** to produce the
load results, queue metrics, and live SQL outputs. Do not run on production.

Prereqs on staging:
- `php artisan migrate` applied (product_defaults, message_receipts, orders.idempotency_key, campaign_messages).
- `QUEUE_CONNECTION=redis`; Horizon (or workers) running.
- k6 installed on a load box; `BASE_URL`, `WEBHOOK_PATH`, `INSTANCE` known.

## 3. Load test  →  produces: reply latency, failure rate, (with §queue) queue depth
```
k6 run -e BASE_URL=https://staging.host -e WEBHOOK_PATH=/api/webhook/whatsapp/evolution \
       -e INSTANCE=<tenant-instance> load/k6_webhook.js | tee load_100_500_1000.txt
```
Capture from k6 output: `http_req_duration p(95)`, `failed_requests`, `iterations`.

## 4. Chaos test  →  produces: behaviour under duplicate flood + double checkout
```
k6 run -e BASE_URL=https://staging.host -e WEBHOOK_PATH=/api/webhook/whatsapp/evolution \
       -e INSTANCE=<tenant-instance> load/k6_safety.js | tee chaos.txt
```

## Queue metrics (sample once per second DURING the runs)
```
# queue depth (Redis)
watch -n1 'redis-cli LLEN queues:default; redis-cli LLEN queues:default:delayed'
# or Horizon: Dashboard → Metrics → throughput + wait time per queue
# processing + reply latency (from app logs)
tail -f storage/logs/laravel.log | grep bot.latency      # queue_ms, brain_ms, total_ms
# CPU / memory during ramp
docker stats   # or htop on web + worker nodes
```

## 5. SQL verification (run on the staging Postgres AFTER the chaos run)
```sql
-- Zero duplicate message receipts
SELECT tenant_id, whatsapp_message_id, count(*) FROM message_receipts
 GROUP BY 1,2 HAVING count(*) > 1;                          -- expect 0 rows

-- Zero duplicate orders
SELECT idempotency_key, count(*) FROM orders
 WHERE idempotency_key IS NOT NULL GROUP BY 1 HAVING count(*) > 1;   -- expect 0 rows

-- Zero duplicate campaign sends
SELECT campaign_id, recipient, count(*) FROM campaign_messages
 GROUP BY 1,2 HAVING count(*) > 1;                          -- expect 0 rows

-- Tenant isolation: a message id shared across tenants is independent
SELECT whatsapp_message_id, count(DISTINCT tenant_id) tenants
 FROM message_receipts GROUP BY 1 HAVING count(*) <> count(DISTINCT tenant_id); -- expect 0 rows

-- Dup-flood customers: one cart line per product, qty = DISTINCT ids (not deliveries)
SELECT customer_phone, jsonb_array_length(cart) lines FROM conversations
 WHERE customer_phone LIKE '256700%';
```

## Conversation ordering (from logs, during the load run)
For a sample conversation, confirm `bot.latency` log lines appear in send order
and there is never overlapping processing for the same `from` (the
WithoutOverlapping lock). Horizon will show no two concurrent jobs sharing a
conversation key.

## Sign-off gate
Production Safety is complete only when all five SQL queries return **0 rows**,
k6 failure rate < 1%, p95 reply latency within target, and no queue backlog growth
that doesn't drain. Paste those outputs back and we mark it done.
