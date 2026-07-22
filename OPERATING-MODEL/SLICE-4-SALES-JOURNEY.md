# Slice 4 — The Sales Journey  ·  STATUS: BUILT (overlay, ready to deploy)

Make the **Quote → Accept → Convert → Invoice → Payment → Receipt** journey excellent end to end —
by (a) recording every step as an event so the customer timeline tells the whole story, and (b) showing,
on each customer, where their latest deal stands and the single next step to advance it. No new tables,
no new money paths, no rewrites — overlay only.

## Built (what shipped)
- **Journey emits** (guarded, mirror Slice 2 — no-op if the spine is absent, never throw):
  - `QuoteCreated`  → `QuotationService::persist` (the single create choke point for every source).
  - `QuoteAccepted` → `PanelApiController::quotationStatus` (panel) + `QuotationPortalController::approve`
    (customer self-serve portal).
  - `QuoteConverted`→ `QuotationConvertService::convert` + `PanelApiController::quotationConvert` (the
    inline duplicate path), so both conversion routes record it.
  - `ReceiptSent`   → `PaymentController::send` (WhatsApp receipt).
  The V3 timeline renderer already had icons/labels for all of these (`bosTLine`), so they light up the
  timeline immediately — quoted → accepted → converted → invoiced → paid → receipt sent, in order.
- **SalesJourney** (`app/BOS/Process/SalesJourney.php`) — a read-only process view: from the customer's
  live quotes/invoices/payments it computes the 5-stage rail (done/current/todo) and the single
  **next best action** (collect the open balance / convert the accepted quote / send the receipt / follow
  up the sent quote). Pure read; no writes; every figure from a real object.
- **UI:** the customer Timeline (Slice 2) now opens with a **journey rail** + a **Next-step button** that
  deep-links one tap to the object where the verb lives (the invoice to collect on, the quote to convert,
  the payment to receipt). Below it, the full event story. Verified at 390px.
- Lint clean; shell JS valid; fitness green (SalesJourney is read-only; emits go only through the SDK).

Deploy after Slices 1–3. No migration.

---

## Scope
1. Emit the five missing journey events (guarded one-liners at the choke points above).
2. `SalesJourney` read helper: stage rail + next best action for a customer, from live objects.
3. Surface both on the existing customer Timeline screen (rail + one next-step button). No new screen.

## Definition of Ready
- Capability **Sell / Collect** · Process **Order-to-Cash**. Objects read: Quotation, Invoice,
  CustomerPayment (current state) + `bos_events` (ReceiptSent for "already receipted?").
- Rules: none new (the NBA respects R3/R4 by only ever deep-linking to the proven verb screens; it never
  mutates). Events emitted: QuoteCreated/QuoteAccepted/QuoteConverted/ReceiptSent (all additive, guarded).
- Projections/process: `SalesJourney` (a process read; NOT a 5th projection type — ADR-014 intact).
- Tests: each choke point emits exactly one event; SalesJourney returns the right stage+NBA for the
  quote-only / accepted / invoiced-with-balance / paid-no-receipt / done cases; fitness (read-only).

## Technical design
- Emits: `\App\BOS\Emit::customerEvent($tenantId, <Name>, <phone>, <payload>, ['actor'=>...])` — identical
  guarded bridge as Slice 2. Payloads: quotes carry quote_no/total/currency; convert adds order_no;
  receipt carries receipt_no/invoice_no/amount/currency.
- `SalesJourney(tenantId, customerId)->build()` → `['stage', 'steps'=>[{key,label,state}], 'next'=>{label,
  hint,route:{key,id},tone}|null, 'outstanding']`. NBA priority: open invoice balance (collect) →
  accepted-but-unconverted quote (convert) → paid-but-no-ReceiptSent (send receipt) → sent quote (follow
  up) → null (all caught up). Matches invoices/quotes/payments by `customer_phone === CustomerProfile.phone`
  (same join the timeline header already uses).
- `CustomerCapability::bosTimeline` gains a `journey` key (try/caught) alongside customer+timeline.
- Shell `bosTimeline`: render `.bjr` rail + `.bjr-next` button (calls `route(key,id)`) above the timeline.

## Migration / Rollback
- Migration: none. Rollback: remove the five emit lines, the `journey` key, the rail/CSS, and delete
  `SalesJourney.php`. All additive + read-only → removal restores exact prior behaviour. No schema/data change.

## Acceptance tests
- Accept a quote in the panel → a `QuoteAccepted` event appears; the customer's timeline shows it and the
  rail advances to "Invoiced (next)". Convert it → `QuoteConverted`. ✅
- Issue an invoice, receive part payment → rail shows "Paid (current)"; Next-step = "Collect USD <balance>"
  and taps into that invoice. Send the receipt → `ReceiptSent`; rail completes; Next-step clears. ✅
- Send a receipt from the payments screen → exactly one `ReceiptSent`; money/receipt output unchanged. ✅
- Fitness: SalesJourney performs no writes; events only via `Bos::emit`. 390px render. ✅

## Four-review gate
- Functional: one customer view now tells the whole deal story and always shows the one next step. ·
  Architectural: guarded emits + a read-only process view; fitness green; ADR-014 intact (no new
  projection type). · Operational: additive, reversible, can't touch the money path (emits never throw;
  NBA never mutates). · UX: one-thumb, the next action is the biggest thing on screen, ≤ 1 tap to act.

## Note
This closes the sales loop: Slice 2 showed history, Slice 3 turned it into a to-do list, Slice 4 makes
each customer's journey legible and self-advancing. Next (Slice 5): offline-first sync so the field team
keeps working with no signal.
