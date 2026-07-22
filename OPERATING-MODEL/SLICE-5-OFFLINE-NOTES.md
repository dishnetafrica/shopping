# Slice 5 — Offline Field Notes (the offline outbox, proven safely)  ·  STATUS: BUILT (overlay)

The field team can log what happened at a customer **with no signal** — a visit, a note, a follow-up —
and it syncs invisibly when they reconnect. This slice builds the **offline outbox spine** (queue →
optimistic UI → flush-on-reconnect → idempotent server) and proves it on the *safest* high-value action
first. No money path is touched; nothing can be double-counted (the event spine already dedupes on the
client's idempotency key).

## Why notes first (risk-staging — deliberate)
Recording a **payment** offline is the eventual prize, but it (a) requires an order to tie to — data that
must be cached offline — and (b) is the money path, where an untested duplicate/loss is costly. So Slice 5
builds and hardens the outbox on **customer notes/visits**, where a lost or duplicated entry is harmless
and the server side is already idempotent. Once the outbox is proven on real devices, **Slice 6 puts
offline-collect on top of the *same* outbox** — no new machinery, just an allowlisted money action with a
`customer_payments.idempotency_key` column. Shipping the risky money path on untested offline code would
be irresponsible; this stages it correctly.

## Built (what shipped)
- **Endpoint** `POST /papi/customer-note` (`BosNoteController`): resolves the customer, then emits a
  `CustomerVisited` / `FollowupScheduled` / `CustomerNote` event via the sanctioned `Emit::customerEvent`
  bridge — passing the **client's idempotency_key** (replay-safe), the **occurred_at** the note was really
  taken offline, and `connectivity` so the spine records it was captured offline (ADR-015). Pure additive;
  guarded; never throws.
- **Offline outbox** in the shell (`OB`): an IndexedDB queue (with an in-memory fallback when IDB is
  unavailable) — `enqueue / all / remove / count`, and `obFlush()` that drains it, POSTing each item with
  its idempotency key; it removes on success, keeps + retries on a network failure, and drops + surfaces a
  clear-cut server rejection so a bad item never wedges the queue. Flush runs on load, on the browser
  `online` event, on a 30s heartbeat, and right after a send.
- **UI:** a one-thumb **note composer** on the customer Timeline (Visit · Note · Follow-up + text). A send
  with signal posts immediately; a send with no signal is saved to the outbox, shows an optimistic
  "⏳ waiting to sync" line, and a **pending chip** counts what's queued and clears as it flushes. The
  timeline now renders the note text on visit/note/follow-up lines.
- Lint clean; shell JS valid; fitness green (events only via the SDK bridge; the composer writes through
  the outbox → the one endpoint). 390px render verified.

Prereq: Slices 1–4 (bos_events exists). **No migration.**

## Scope
1. One additive write endpoint that emits a customer event with the client's idempotency key + real time.
2. A general offline outbox (IndexedDB) + optimistic UI + flush-on-reconnect in the shell.
3. Wire the customer note/visit/follow-up composer through it. No money action in this slice.

## Definition of Ready
- Capability **Sell / Relationship** · Process **Relationship**. Objects: none written; emits customer
  events. Rules: none new. Idempotency + occurred_at + connectivity already supported by `EventStore::emit`.
- Projections: Timeline (existing) renders the new lines. UI: the composer + pending chip (additive).
- Tests: same note replayed (same key) creates ONE event; an offline send is queued then flushes to
  exactly one event; a network failure keeps the item; a 422 drops it with a notice; fitness (SDK-only).

## Technical design
- Server: `Emit::customerEvent($tenantId, $name, $phone, ['note'=>…,'due'=>…], ['actor'=>…,
  'idempotency_key'=>$key,'occurred_at'=>$occurredAt,'connectivity'=>'offline'])`. `$meta` flows straight
  through the bridge → `Bos::emit` → `EventStore::emit`, which returns the existing event if the key was
  seen (no duplicate). Route in the `papi` group.
- Client: `OB` = a ~40-line IndexedDB wrapper (store `outbox`, keyPath `id`). `obSend(item)` tries the
  network first when `navigator.onLine`; on offline/throw it enqueues + renders an optimistic pending line.
  `obFlush()` drains FIFO, stops on the first network error (preserves order), re-fetches the timeline when
  it empties so pending lines become real. Idempotency key = a client uuid per note; also the item id.
- Rollback: delete `BosNoteController` + the route; remove the `OB` module, composer, pending chip, and the
  bosTLine note text. All additive + read-safe → removal restores exact prior behaviour. No schema/data.

## Acceptance tests
- Online: type a visit note → it posts, appears on the timeline immediately, no pending chip. ✅
- Offline (DevTools → Offline): send → "Saved offline", pending chip shows 1, an optimistic line appears.
  Go online → within 30s (or on reconnect) it flushes; the chip clears; the real event line shows. ✅
- Replay safety: the same queued item flushed twice yields ONE event (idempotency key). ✅
- Bad item (e.g. empty text rejected 422) is dropped with a notice, not retried forever. Fitness green;
  390px render. ✅

## Four-review gate
- Functional: a rep logs a visit/follow-up at the customer with no signal and it lands when they reconnect.
  · Architectural: one SDK-bridge emit + a generic outbox; idempotent by construction; no new event path.
  · Operational: additive, reversible, observable (events carry connectivity=offline + true occurred_at);
  the money path is untouched. · UX: one-thumb composer, honest pending state, invisible sync.

## Note
Slice 6 rides this outbox to **offline collections**: add `customer_payments.idempotency_key`, allowlist
the payment action into `OB`, and prefill order/invoice from the already-loaded record — no new offline
machinery, because this slice already built and proved it.
