# TRUST-ENGINE-DEPLOY — Inventory Trust v1 (Silent Mode + GATE-1 Backtest)

Implements *Inventory Trust Engine — Specification v1.0* against the existing
`stock_movements` ledger (E6.1). **Additive only** — no existing table, model, or
behaviour is touched. v1 is Pilot Plan **Phase B0 silent mode**: scores are computed
and stored, nothing is ever blocked. Gates/step-up UI ship in Phase B1.

## Files in this drop (copy to same paths)

```
database/migrations/2026_07_30_000001_create_inventory_trust_tables.php
app/Services/Trust/SignalCatalog.php        ← v1 weights, versioned in code (authoritative)
app/Services/Trust/EntityTrust.php          ← decayed Beta-Bernoulli reputation
app/Services/Trust/TrustScorer.php          ← pure scorer: priors + class-capped evidence
app/Console/Commands/TrustBacktestCommand.php
app/Http/Controllers/Api/VisionEventController.php
app/Models/Trust*.php  app/Models/VisionEvent.php   (8 thin models)
TRUST-routes-append-to-api.php.txt          ← one route, append to routes/api.php
```

All files `php -l` clean on PHP 8.3. Scorer verified by a 10-case behavioural
harness (spec Case A → T 99.5 auto_post; spec Case B shape → T 5.9 hold;
expected-missing penalty, class caps, value-scaled T_required, determinism).

## Deploy

1. Copy files, append the route block from `TRUST-routes-append-to-api.php.txt` to `routes/api.php`.
2. `.env` (only needed for the Vision webhook):
   `VISION_WEBHOOK_TOKEN=<long random>` · `VISION_TENANT_ID=17`
   Then point the Vision Gateway's `EVENT_WEBHOOK_URL` at
   `https://<cloudbss>/api/vision/events` with header `X-Vision-Token`.
3. EasyPanel → **Rebuild** (not Restart — opcache).
4. `php artisan migrate --force`

## GATE-1 backtest (the whole point)

```bash
php artisan trust:backtest --tenant=17 --months=12 --top=20          # read-only
php artisan trust:backtest --tenant=17 --months=12 --top=20 --write  # persist scores/stats
```

Replays movements **chronologically and causally** (a movement is scored only
with what was knowable before it). Negative ADJUSTs are treated as revealed
variances: they tax the reputation of actors who touched that SKU+location in
the prior 14 days — affecting only later scores. The `later` column (`⚠ adj +Nd`)
is a report-only validation aid, never part of the score.

**Procedure:** sit with Rupesh, adjudicate each of the top-20 rows against
records/memory as clean | error | confirmed_loss.
**GATE-1 passes at ≥6 real (error or confirmed_loss).** Fail → adjust
`SignalCatalog` weights once, re-run; fail again → the model is wrong, stop and
rethink before further build (Pilot Plan §7).

If the ledger has little history (E6.2 dual-writes recent), the honest move is
Phase B0 forward: deploy, let it score silently for 4 weeks, then review.

## v1 evidence scope (ledger-only)

Backtest signals: `ref_linked`, `actor_known`, `note_present` (+ pattern flags:
off-hours, burst, round-qty, negative-adjust; + actor history; + value band).
Runtime signals (`scale_weight`, `grn_photo`, `dock_clip`, `counterparty_ack`,
`second_pin`, `cycle_count_match`) are defined and scoreable now — they start
flowing when the receiving app / counting app attach `trust_evidence_items`
rows in Phase B1, and Vision events join as P-class evidence in M3 once zones
map to SKUs.

## Rollback

`php artisan migrate:rollback --step=1` drops only the eight new tables.
Removing the copied files restores the tree exactly — nothing else references them.

## Notes

- Movement value = |qty| × (`cost_price` ?? `price`); movements without either
  score with value 0 (they still get a trust score, just no expected-loss rank).
- Off-hours uses server local time; if app tz ≠ EAT, set `APP_TIMEZONE=Africa/Kampala`.
- The actor scorecard **routes review only** — spec §5.2 / §7 of the frozen spec:
  never display as a ranking, never drive personnel action.


---

# v1.1 — RUNTIME LAYER (this same drop)

Adds live scoring + the owner's daily loop on top of the backtest:

```
app/Services/Trust/TrustRuntime.php          ← live scoring, variance registration,
                                               record-confidence decay, count pick list
app/Console/Commands/TrustDailyCommand.php   ← trust:daily — queue + WhatsApp digest
app/Console/Commands/TrustResolveCommand.php ← trust:resolve — outcomes = ML labels
TRUST-console-append-to-console.php.txt      ← daily schedule (06:45 EAT)
```

## Daily loop

```bash
php artisan trust:daily --tenant=17 --force            # print digest, build queue (test)
php artisan trust:daily --tenant=17 --send --force     # + WhatsApp to owner numbers
php artisan trust:resolve --tenant=17                  # list open investigations
php artisan trust:resolve --tenant=17 12 confirmed_loss --value=180000 --notes="short delivery"
```

Opt-in per tenant: setting `trust_digest` = 1 (extra recipients: `trust_digest_numbers`).
Append the schedule line from `TRUST-console-append-to-console.php.txt` to `routes/console.php`.

## What the queue is

Top-K (default 7) recent movements by expected loss = (1−T)·value, plus up to 3 ε-random
spot checks drawn from clean-looking auto-posts (thresholds must stay unlearnable — Spec §7.3).
Every item becomes a `trust_investigations` row; **resolving it is the label factory** — clean
outcomes lift the actor's reputation, confirmed losses lower it (severity ∝ value), and `error`
deliberately changes no reputation (process fault, not person). Records confidence decays nightly
(fast movers come due for a count ≈ weekly, quiet items monthly) and the digest carries the
count pick list = (1−confidence) × on-hand value.

## Order of operations for the pilot

1. Deploy → `migrate --force` → `trust:backtest --tenant=17 --write` (GATE-1 with Rupesh).
2. Enable `trust_digest` for Family Shoppers only → silent two weeks: read the digest, resolve
   items, DON'T change staff workflow yet (Phase B0).
3. Then switch on blind-receiving behaviour and evidence attachment (Phase B1) — the
   `scale_weight` / `grn_photo` signals are already defined and scoreable the moment the
   receiving flow writes `trust_evidence_items` rows.
