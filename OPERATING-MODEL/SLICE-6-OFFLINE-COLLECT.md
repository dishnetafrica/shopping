# Slice 6 — Offline Collections  ·  STATUS: BUILT (overlay, needs 1 migration)

The field team can **take a payment against an open invoice with no signal**, right from the customer's
timeline. It rides the Slice-5 outbox unchanged; the only new machinery is a client idempotency key on
`customer_payments`, so a payment queued offline can replay on reconnect without ever creating a second
receipt. That same guard also kills the online double-tap duplicate.

## Built (what shipped)
- **Idempotent recording (server):** `customer_payments.idempotency_key` (nullable, indexed — one
  migration). `PaymentService::record` returns the existing receipt when it sees a key it already stored
  (checked before the insert), and stores the key on create. `PaymentController::store` reads it from the
  request. Online payments send no key (NULL) and are unaffected.
- **Collect context (read):** `SalesJourney` resolves the open invoice's `order_no` (payments must tie to
  an order) and exposes `next.collect = {order_no, invoice_no, currency, amount}` — only when an order is
  resolvable, so the inline form never offers an un-postable payment.
- **Inline collect (client):** when a customer has a collectable open invoice, the Timeline's next-step
  becomes an inline **"Collect USD X"** card (amount prefilled to the balance + Cash/MoMo/Bank). It posts
  through the SAME outbox: online it records immediately; offline it queues, shows a 💵 "waiting to sync"
  line + the pending chip, and flushes idempotently on reconnect. The server computes the USD rate at
  record time, so no FX is needed offline.
- Lint clean; shell JS valid; fitness green (SalesJourney still read-only; no inline balance rule; events
  only via the SDK). 390px render verified.

Prereq: Slices 1–5. **Run `php artisan migrate --force` once** (adds the one column).

## Scope
1. `idempotency_key` on `customer_payments` + dedupe in the one recorder + read it in `store`.
2. `SalesJourney` exposes the collect context (order/invoice/currency/amount) when postable.
3. Inline collect composer on the Timeline, routed through the existing outbox. No new outbox code.

## Definition of Ready
- Capability **Collect** · Process **Order-to-Cash**. Writes: a CustomerPayment (through the ONE existing
  recorder — no new money path). Rules: R3 (payment ≤ balance) still enforced server-side; the form only
  ever prefills the balance. Idempotency via the new key.
- Tests: same key recorded twice → ONE payment (returns the first). Offline collect queues then flushes to
  exactly one receipt. A rejected payment (e.g. bad amount) drops from the queue with a notice. Fitness green.

## Technical design
- `record()` guard: `if idempotency_key and a payment with (tenant, key) exists → return it` (before the
  transaction); else create with the key. The outbox is serialized (one flush at a time), so a check-then-
  insert is sufficient; the index just speeds the lookup.
- Client `obCollect()` builds a `/papi/payments` item {customer_name, phone, order_no, invoice_no,
  kind:'settlement', currency, amount, method, rate_to_usd:0, idempotency_key, created_date} and enqueues
  it via the same `OB` queue + `obFlush`. `rate_to_usd:0` → the server derives the rate from the single FX
  source at record time. `created_date` = when the rep recorded it, so an offline payment is dated to the
  field, not the sync.
- Rollback: revert the 6 files and drop the column (migration `down`). The outbox itself is Slice 5.

## Acceptance tests
- Online: open a customer with a balance → tap Record payment → a receipt is created; the timeline shows
  PaymentReceived; the rail advances. ✅
- Offline: DevTools → Offline → Record payment → "Saved offline", 💵 pending line, chip = 1. Reconnect →
  it flushes to exactly one receipt; the pending line becomes the real PaymentReceived. ✅
- Replay/double-tap safety: the same key never creates a second receipt (record returns the first). ✅
- Only postable collects show the form (order resolvable); otherwise the next-step stays a deep-link.
  Fitness green; 390px render. ✅

## Four-review gate
- Functional: collect at the customer even with no signal, one tap from their timeline. · Architectural:
  reuses the one recorder + the proven outbox; the only addition is an idempotency key; fitness green. ·
  Operational: additive column (nullable), reversible; idempotent replay; online path unchanged (no key =
  old behaviour). · UX: one-thumb, amount prefilled to the balance, honest offline state.

## Note
This completes the field money loop. A later slice can switch the service worker to cache the app shell
for true cold-start-offline (kept separate so a caching bug never rides in with money-critical code), and
allowlist more actions (new quote, customer visit already done) into the same outbox.
