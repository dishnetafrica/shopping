# CloudBSS — Performance & Scale Report (Cat 21-26)

Measured in-sandbox, PHP 8.3, single core. **Measured numbers are real; the
concurrency rows are projections** from the measured per-message cost. The true
end-to-end concurrency test runs against staging (`load/k6_webhook.js`).

## 18/18 measured checks pass

### Cat 21 — catalogue scale (measured)
| Products | 1× search | 8-item handle | 1-item handle | peak mem |
|---|---|---|---|---|
| 500 | ~0.7 ms | ~9 ms | ~2.8 ms | ~1.2 MB |
| 1000 | ~1.4 ms | ~18 ms | ~5.6 ms | ~1.8 MB |
| 2000 | ~2.9 ms | ~36 ms | ~11 ms | ~3.0 MB |

Linear scaling; comfortably interactive at 2000 SKUs. Beyond ~5000 SKUs add an
inverted token index (Phase 2).

### Cat 22 — FMCG/tobacco
`20 Vimal 10 Coke 5 Rice` (+ "and"/"Need:" forms) → quantities 20/10/5 extracted
correctly. In a large catalogue Coke/Rice clarify (many SKUs share the word), qty
preserved into options — correct.

### Cat 23 — rapid messages
Context threads across messages (clarify in msg1 resolved by reply in msg2; cart
preserved across browses). True rapid-fire safety = infra: per-conversation queue
serialisation + messageId de-dup + optional debounce.

### Cat 24 / 25 — large list & ambiguity (real finding ⚠️)
20-item message: no loss, ~42 ms. High-ambiguity: clarifies all staples, never
guesses. In a realistic catalogue, generic words clarify often (many SKUs, price
spread) — correct, but a bare 20-item list becomes one big clarification message.
Phase 2 mitigation: per-item default SKU + size-aware/popularity ranking.

### Cat 26 — load (measured brain + projection)
Measured: ~6 ms/message at 1000 SKUs, ~159 msg/sec/core, memory flat. The brain is
not the bottleneck.

| Concurrent | 4 workers | 8 workers | 16 workers |
|---|---|---|---|
| 100 | ~0.16 s | ~0.08 s | ~0.04 s |
| 500 | ~0.79 s | ~0.39 s | ~0.20 s |
| 1000 | ~1.6 s | ~0.79 s | ~0.39 s |

Excludes DB fetch, WhatsApp Cloud API, OpenAI — which dominate in production.
Real bottlenecks to size: per-tenant catalogue cache (avoid a DB query per turn),
WhatsApp send limits, OpenAI latency + per-tenant spend caps (Phase 3).

### Load testing (the real Cat 26)
```
k6 run -e BASE_URL=https://staging.your-host -e WEBHOOK_PATH=/api/wa/webhook load/k6_webhook.js
```
Ramps 100→500→1000 VUs; asserts p95 ACK < 1.5 s, < 1% failures. Run on staging,
and watch Horizon queue-drain time for true reply latency.

Run locally: `php qa/performance_scale_suite.php` (full output in PERF-RESULTS.txt)
