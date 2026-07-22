# 2 — Process Catalog

**Status:** DRAFT for approval · Layer 2. A **process** is how objects move through their states to
get real work done. Processes are the backbone of the company — the verbs. They orchestrate objects
(layer 1) and record events (layer 3); the UI (layer 4) is just a projection of "where is this
process and what's the next step".

**Capabilities = processes.** Each process below maps to a *verb capability* that evolves your
existing `ProcessCapability` layer: **Sell · Buy · Collect · Deliver · Support · Analyze · Administer.**

Each step is written: **Moment** (person) → **System** (V3) → **Events** → **Document** → **Offline**
→ **AI**. AI only ever *explains/prioritizes/drafts* the step the process already defines — it never
decides the next step (invariant: the process runs with AI off).

---

## Object state machines (the rails processes run on)
```
Lead:      new → qualified → converted | lost
Customer:  active ⇄ dormant → churned
Quote:     draft → sent → accepted | rejected → converted | expired
Order:     New → Confirmed → Packed → Ready → Dispatched → Delivered → Invoiced → Closed | Cancelled
Shipment:  pending → loaded → in_transit → delivered | failed
Invoice:   issued → part_paid → paid | void
Payment:   advance → settlement
Purchase:  ordered → partially_received → received
Expense:   pending → approved
Task:      open → done | skipped (→ escalate)
```
Transitions are contracts; illegal ones are rejected, online or offline.

---

## Verb: SELL

### P1 · Lead → Customer
new → qualified → convert. Events: `LeadCaptured` → `CustomerCreated`. AI qualifies/queues; drafts
first outreach (approval before send). Offline: capture works offline.

### P2 · Customer Visit
check‑in (GPS) → discuss → (branch to Quote) → check‑out. Events: `CustomerVisited`,
`FollowupScheduled`. Offline: whole visit offline. AI: next stop + outstanding; "create the quote?".

### P3 · Quote → Cash  *(flagship)*
```
Create quote → customer edits → approve → accept → convert → collect deposit → receipt → WhatsApp → done
```
Object moves: Quote `draft→sent→accepted→converted`, Order `New→Confirmed`, Payment `settlement`,
Invoice `issued`. Events: `QuoteCreated/Edited/Sent/Accepted/Converted`, `OrderConfirmed`,
`PaymentReceived`, `ReceiptGenerated/Sent`, `InvoiceIssued`. Documents: Quote, Receipt, Invoice PDFs.
Offline: quote/order/payment created with client ids; PDFs on device; sends queue. AI:
likelihood‑to‑close; nudges deposit; schedules follow‑up. **Invariants:** catalogue prices only;
invoice in USD even from a legacy UGX‑priced order.

### P4 · Relationship / Follow‑up
dormancy or scheduled follow‑up → activity in Today → contact → outcome. Events: `FollowupScheduled/
Completed`, `CustomerMessaged`. AI: detects "14 days no order", drafts the message.

## Verb: BUY

### P5 · Procurement (Demand → Stock)
```
Demand signal → Purchase Request → Purchase Order → Receive → Stock → Supplier Payment
```
Objects: Purchase `ordered→partially_received→received`, Product stock up. Events: `PurchaseOrdered`,
`StockReceived`, `SupplierPaid`. AI: reorder list from unmet‑demand (existing weekly report).
Offline: desk process, low priority. Currency: UGX/SSP by nature.

## Verb: DELIVER

### P6 · Fulfilment (Order → Delivery)
Order `Confirmed→Packed→Ready→Dispatched→Delivered`; Shipment `pending→loaded→in_transit→delivered`.
Events: `DeliveryDispatched`, `GoodsLoaded`, `DeliveryCompleted`, `PODUploaded` → auto `InvoiceIssued`
on Delivered (already wired). Offline: POD photo captured offline, queued. AI: delivery nudge.

## Verb: COLLECT

### P7 · Collections
```
Outstanding → Reminder → (Visit) → Receive → Receipt → Close
```
Projection of unpaid Invoices − Payments, aged. Each overdue customer = an activity ("Collect $420").
Completing it: `PaymentReceived` → `ReceiptSent`, Invoice `→ part_paid | paid`. AI: orders the call
list by value × likelihood; "$1,200 at risk" (real AR; AI phrases only). Offline: collect offline.

## Verb: SUPPORT

### P8 · Returns
initiate → approve → complete. Events: `ReturnInitiated → ReturnApproved → ReturnCompleted`, which
fans to `StockReceived` (+restock) and `CreditNoteIssued` (−AR). Document: credit note. Fully
auditable.

### P9 · Complaints / Escalations
customer issue → `CustomerEscalated` → owner activity → resolve. AI: routes, drafts reply; human
decides.

## Verb: ADMINISTER

### P10 · Expense Approval
record → `ExpenseRecorded` (pending) → approve → `ExpenseApproved` → cashbook + P&L. Offline: record
offline; approval is an owner activity. AI: flags anomalies.

### P11 · Cash Management
transfer between holders / currency exchange → `CashTransferred` / `CurrencyConverted` (two legs);
mistakes → `CashEntryVoided` (kept for audit). (Exactly the transfer + void behaviour already built.)

### P12 · Daily Closing
end of day: project the day's events → cash counted vs `PaymentReceived` + `ExpenseRecorded`,
deliveries done, quotes open → confirm cash on hand (variance event if mismatched) → optional handover
(`CashTransferred`). AI: "collected $4,320 across 6 customers; hand $3,500 to Jayesh?".

## Verb: ANALYZE
Not a process with states — it's the projection layer (doc 4). Listed here only to reserve the verb.

---

## The meta‑process: Daily Salesperson Journey
Threads the verbs into one day so the rep barely navigates:
```
Morning brief (AI reads real facts)
  → Travel (next stop + outstanding)
  → Visit (P2) → Quote→Cash (P3) → Collect (P7)   ↺ repeat
  → Daily Closing (P12)
```
"Today" = `activities WHERE owner=me AND due=today`. Documents are produced automatically as each
activity completes — the rep completes *work*, not *forms*.

---

## Why processes (not modules) are the backbone
A module ("Invoices") is a place to browse a table. A process ("Collect") is a journey with a state,
a next step, and a definite done. Modeling the company as ~12 processes means: every screen has an
obvious next action, AI has a real workflow to assist (not own), and offline has clear units of work
to queue. Add a capability later = add a verb, not another page.
