# Slice 2 — Customer Timeline  ·  STATUS: BUILT (overlay, ready to deploy)

## Built (what shipped)
- **Emit hooks** (one guarded line each, in existing services): `PaymentService::record` →
  `PaymentReceived`; `InvoiceService::issue` → `InvoiceIssued`. Both via `App\BOS\Emit::customerEvent`
  (resolves the customer subject, no-ops if the spine isn't migrated, never throws → money path safe).
- **Read path:** a `timeline` command on `CustomerCapability` returns the customer header (outstanding
  from live invoices) + `Bos::timeline('customer', …)` — pure read, no new route/table.
- **UI:** a "🕑 Timeline" action on the customer record opens a WhatsApp-style timeline screen in the
  shell (`bosTimeline(id)` + the `bos.timeline` client route). Verified via a 390px render.
- Lint clean; JS valid; fitness green (events only via the SDK; the screen is a projection).

Deploy after Slice 1 (bos_events migrated) + Slice 1.5 (demo data) so it's demonstrable on day one.

---


The first *user-visible* value, and the root projection everything else builds on (Today, Insights,
Follow-up, Audit all read it). Built on the Slice-1 spine + Slice-1.5 demo data.

## Scope
1. **Customer Timeline projection** (concrete `TimelineProjection` for a customer) + a read endpoint.
2. **Mobile timeline screen** in the V3 shell — WhatsApp-style, opened from a customer.
3. **Emit real events** from the existing services (one line each, guarded): `PaymentReceived`
   (PaymentService::record), `InvoiceIssued` (InvoiceService::issue), `QuoteCreated` (quotation
   create). This is where the LIVE tenant's timeline starts filling from data you already produce.
4. *(optional)* a `bos:backfill` command bridging recent legacy rows (bot_events, payments) so the
   timeline has history immediately (ADR-012 backfill side).

## Definition of Ready
- Capability **Sell** · Process **Relationship** · Objects: reads events by `subject={customer,id}`.
- Rules: none new. Events consumed: all with the customer subject. Services: PaymentService,
  InvoiceService, quotation create — each gains ONE `Bos::emit(...)` line (additive, guarded).
- Projection: **Timeline** (canonical — no new type). UI: one new mobile screen (a projection).
- Tests written first: timeline read returns events for a subject; each service emits exactly one
  event; fitness (screen consumes a projection, emits nothing).

## Technical design
- `App\BOS\Projections\CustomerTimeline extends TimelineProjection` → `Bos::timeline('customer', id, tenant)`.
- Read endpoint: `GET /papi/customer-timeline?customer_id=` (thin controller) OR a `timeline` action on
  the existing customer capability — returns the newest-first lines + the customer header (outstanding,
  last order) computed from live objects.
- Shell: a `customerTimeline(id)` view (reuse the `.thread`/timeline CSS already in the shell) opened
  from the customer record and the mobile "Customers" workspace. Pure render of the projection.
- Emit hooks (overlay, each guarded by `Schema::hasTable('bos_events')`, wrapped in try/catch so a
  spine hiccup never breaks the money path):
  - `PaymentService::record()` → `Bos::emit('PaymentReceived', {...}, subject=customer)`.
  - `InvoiceService::issue()`  → `Bos::emit('InvoiceIssued', {...})`.
  - quotation create           → `Bos::emit('QuoteCreated', {...})`.
  Subject id resolved from customer phone → CustomerProfile id.

## Migration plan
- None (bos_events exists from Slice 1). Optional `bos:backfill --days=90` copies recent legacy rows in
  (idempotent via `legacy:table:id` keys).

## Rollback plan
- Remove the capability/endpoint + the shell screen + the 3 one-line emit calls. Each emit is additive
  and guarded, so removing them restores exact prior behaviour. No data/schema change.

## Acceptance tests
- Open a demo-tenant customer → timeline shows their CustomerCreated/InvoiceIssued/PaymentReceived
  lines in order (from Slice 1.5 data). ✅ verifiable immediately against the demo tenant.
- Record a real payment on the live tenant → a `PaymentReceived` event appears; the customer's timeline
  shows it. Money path unchanged if the spine is absent.
- Fitness: the timeline screen is a projection (reads only); no rule logic; events only via `Bos::emit`.
- Screenshot the mobile timeline at 390px (as with the workspace bar).

## Four-review gate
- Functional: a rep sees one customer's whole history in one place. · Architectural: pure projection +
  guarded emits; fitness green. · Operational: additive, reversible, observable (instrumented events).
  · UX: one-thumb, WhatsApp-familiar, opens in ≤ 2 taps from Customers.

## Prerequisite
Deploy **Slice 1** (migrate `bos_events`) and **Slice 1.5** (`php artisan bos:demo`) first — then the
timeline is demonstrable against real demo data on day one of the build.
