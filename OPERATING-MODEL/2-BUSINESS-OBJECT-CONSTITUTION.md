# 1 — Business Object Constitution

**Status:** DRAFT for approval · Layer 1 of the operating model.

Business objects are the permanent nouns of the company — the language everyone speaks. They change
rarely; when they do, it's a deliberate amendment here, because processes, events, projections, AI,
and offline sync all depend on these contracts.

**Every object is defined by the same ten fields.** A change to *States*, *Events*, or *Permissions*
is a breaking contract change (needs approval); everything else can evolve.

> **Legend:** states/fields shown in `code` are the *real values already in the V3 codebase*.

---

## Template (applied to every object)

```
Purpose        — the one job this object does
Relationships  — what it links to
Lifecycle      — how it comes into being and ends
States         — allowed states + legal transitions (a contract)
Permissions    — who may create / read / change / act
Events         — the facts it emits (see doc 3)
AI             — what AI may do WITH it (assist only; never invent its numbers)
Offline        — behaviour with no signal
Merge          — how duplicates are resolved
Deletion       — how it is removed (almost always: never hard-delete)
Audit          — what is recorded and kept
```

---

## Customer
- **Purpose:** the party we sell to; the anchor of every sales journey and the timeline.
- **Relationships:** → Visits, Quotes, Orders, Invoices, Payments, Tasks, Messages, Price List.
- **Lifecycle:** created from a Lead, storefront, WhatsApp, or manually; never truly ends (goes
  dormant/churned).
- **States:** `active` ⇄ `dormant` (14+ days no order) → `churned`. Lead feeds in: `new → qualified
  → converted`.
- **Permissions:** sales create/read/act on *their* customers; owner/manager all; accountant read
  + collect.
- **Events:** `CustomerCreated`, `CustomerVisited`, `CustomerCalled/Messaged`, `CustomerEscalated`,
  `FollowupScheduled/Completed`.
- **AI:** dormancy detection ("ABC hasn't ordered in 14 days"), next‑best‑action, draft outreach —
  from real history only.
- **Offline:** create + visit + note fully offline (client id); syncs later.
- **Merge:** by phone (primary key of trade here); merging re‑points all history to the survivor,
  emits `CustomerMerged`.
- **Deletion:** never hard‑delete; `archived` flag. History is retained.
- **Audit:** every field change and merge logged (`AuditLogger`), keyed by phone.

## Supplier
- **Purpose:** the party we buy from. **Relationships:** → Purchase Orders, Supplier Payments,
  Products, Cash Entries. **Lifecycle:** created on first purchase; archived, never deleted.
- **States:** `active` ⇄ `inactive`. **Permissions:** procurement + owner. **Events:**
  `SupplierCreated`, `PurchaseOrdered`, `SupplierPaid`. **AI:** reminds to chase unpriced requests
  (existing `sourcing:remind`). **Offline:** rare in field; read‑mostly. **Merge:** by name+phone.
  **Deletion:** archive only. **Audit:** full.

## Product (+ Price List)
- **Purpose:** a thing we sell, with a price. **Price List** is the set of current selling prices
  (USD base). **Relationships:** → Categories, Quote/Order lines, Stock, Supplier.
- **Lifecycle:** created/imported; archived when discontinued.
- **States:** `active` ⇄ `archived`; stock sub‑state `in_stock | low | out` (vs reorder point).
- **Permissions:** owner/manager write; sales read. **Events:** `ProductCreated`, `PriceChanged`,
  `StockReceived/Counted/Adjusted/Transferred`, `LowStockDetected`.
- **AI:** unmet‑demand → "add/alias this product" (existing weekly report); "enough stock to close 18
  quotes". **Money invariant:** price is USD; `price` and `base_price` are kept in sync (already
  enforced in the model) — **quotes read the catalogue price, never a model‑invented one.**
- **Offline:** catalogue cached for offline quoting. **Merge:** by SKU. **Deletion:** archive only.
  **Audit:** price changes logged (price‑drift is already a tracked concern).

## Quotation
- **Purpose:** a priced offer to a customer — the **output** of the "customer wants prices" moment.
- **Relationships:** → Customer, Product lines, → converts to Order.
- **Lifecycle:** created (bot `<<QUOTE>>`, panel, or rep) → sent → accepted → converted.
- **States:** `draft → sent → accepted | rejected → converted | expired` (real values).
- **Permissions:** sales create/edit own; owner all. **Events:** `QuoteCreated/Edited/Sent/
  Accepted/Rejected/Converted`. **AI:** likelihood‑to‑close; drafts the quote from catalogue prices;
  **never invents quantities or prices** (existing hard guardrail). **Offline:** created + PDF
  rendered on device; send queues. **Merge:** n/a (each is distinct). **Deletion:** `void` with
  reason, never hard‑delete. **Audit:** every edit + who, kept.

## Sales Order
- **Purpose:** a confirmed commitment to supply. **Relationships:** → Customer, Quote (origin),
  lines, → Shipment, → Invoice, → Payments.
- **Lifecycle:** born from `QuoteConverted` or direct; closes when delivered + paid.
- **States:** `New → Confirmed → Packed → Ready → Dispatched → Delivered → Invoiced → Closed |
  Cancelled` (real values). Each transition is an event and may notify the customer + auto‑issue the
  invoice on `Delivered` (already wired in `OrderObserver`).
- **Permissions:** sales create; warehouse/dispatch advance fulfilment states; owner all.
- **Events:** `OrderConfirmed`, `OrderStatusChanged`, `DeliveryDispatched/Completed`.
- **AI:** flags stuck orders past SLA (existing pending‑digest). **Offline:** create offline; state
  advances queue. **Merge:** n/a. **Deletion:** `Cancelled` (state), never hard‑delete. **Audit:**
  full status history — **carries its true `sell_currency`** (money invariant).

## Purchase Order
- **Purpose:** a commitment to buy from a supplier. **States:** `ordered → partially_received →
  received`. **Relationships:** → Supplier, lines, → Stock, → Supplier Payment. **Events:**
  `PurchaseOrdered`, `StockReceived`, `SupplierPaid`. **AI:** reorder suggestions from unmet demand.
  **Offline:** desk‑bound, low priority. **Deletion:** cancel state. **Audit:** full; currency is
  UGX/SSP by nature (purchases are local — money invariant).

## Shipment / Delivery
- **Purpose:** movement of goods to the customer. **Relationships:** → Order, driver, POD.
- **States:** `pending → loaded → in_transit → delivered | failed`. **Events:** `DeliveryDispatched`,
  `GoodsLoaded`, `DeliveryCompleted`, `PODUploaded` (already has `shipment_events`).
- **AI:** delivery‑confirmation nudge to customer (existing). **Offline:** POD photo captured
  offline via wrapper camera, queued. **Deletion:** cancel. **Audit:** geo + timestamps + POD image.

## Invoice
- **Purpose:** the demand for payment; the AR record. **Relationships:** → Order, Customer, →
  Payments, → Credit Notes.
- **Lifecycle:** issued from a delivered/confirmed order (auto or manual); closes when balance ≤ 0.
- **States:** `issued → part_paid → paid | void` (real values).
- **Permissions:** sales/accountant issue + send; owner void. **Events:** `InvoiceIssued/Sent/Voided`.
- **AI:** overdue prioritization + "$X at risk" (real AR figure, AI phrases only). **Offline:** issue
  + PDF on device; WhatsApp send queues. **Merge:** n/a. **Deletion:** **`void` with reason — never
  hard‑delete** (and never edit stored money; corrections are new documents — matches the invoice
  re‑normalization + credit‑note rules). **Audit:** issue/void/paid transitions + true currency.

## Payment
- **Purpose:** money received from a customer. **Relationships:** → Invoice/Order, Customer, →
  Receipt, → Cash Entry.
- **States:** `advance` (unearned) | `settlement` (applied). **Permissions:** anyone who collects;
  owner/manager/accountant always. **Events:** `PaymentReceived` (the multi‑subscriber fan‑out that
  already updates cashbook + invoice + ledger). **AI:** none decides — it only reminds to collect.
  **Offline:** recorded offline with **client id + idempotency key** (so a retry never double‑posts);
  the single most important offline object. **Merge:** n/a. **Deletion:** reverse entry, never
  hard‑delete. **Audit:** amount, currency, `usd_amount`, holder, time — normalized to USD.

## Receipt
- **Purpose:** proof of a payment, sent to the customer. **Relationships:** → Payment.
- **Lifecycle:** generated by `PaymentReceived`; sent on WhatsApp. **States:** `generated → sent`.
- **Events:** `ReceiptGenerated`, `ReceiptSent` (just shipped). **AI:** none. **Offline:** PDF on
  device; send queues. **Deletion:** n/a (regenerate from payment). **Audit:** send log.

## Expense / Cash Entry
- **Purpose:** money out (expense) and cash movements between holders. **Relationships:** → Cashbook,
  P&L, holders. **States:** expense `pending → approved`; cash entry `approved → void`. **Events:**
  `ExpenseRecorded/Approved`, `CashTransferred`, `CashEntryVoided`, `CurrencyConverted`.
- **Permissions:** rep records; owner/manager approve/void. **AI:** flags out‑of‑pattern spend.
  **Offline:** record offline. **Deletion:** **void keeps it for audit, removes from live balances**
  (exactly how the CB‑0201/0202 transfer void works today). **Audit:** `AuditLogger`, holder + rate +
  usd.

## Task / Activity
- **Purpose:** a unit of work a person must do — rendered as an **action** ("Collect $420"), not a
  row. Activities *are* the surface of the app. **Relationships:** → any object (customer, order,
  invoice) via subject.
- **States:** `open → done | skipped`; may `escalate`. **Permissions:** owner/assignee. **Events:**
  `TaskCreated/Assigned/Completed/Escalated`. **AI:** generates & prioritizes the day's activities
  from real facts; drafts messages. **Offline:** complete offline. **Deletion:** archive. **Audit:**
  assignment + completion, notify log (existing task notifications).

## Visit
- **Purpose:** a field call on a customer (the defining field‑sales moment). **Relationships:** →
  Customer, geo, may spawn Quote/Order/Payment. **Lifecycle:** check‑in → discussion → check‑out.
- **States:** `checked_in → in_progress → checked_out`. **Permissions:** sales (own). **Events:**
  `CustomerVisited`, `FollowupScheduled`. **AI:** "next stop 4 km · outstanding $520"; "create the
  quote?". **Offline:** the whole visit is offline‑first; geo stamped by device clock. **Deletion:**
  n/a. **Audit:** geo + time + outcome.

---

## Cross‑object rules (apply to all)
- **Never hard‑delete a financial or customer‑facing object.** Void/reverse/archive; history stays.
- **Every object carries its true currency**; USD is base, conversions only at boundaries.
- **Every mutating action emits its event** (doc 3) so timeline/AI/accounting/analytics stay correct
  without any object calling another.
- **Offline‑capable objects carry a client‑generated id + idempotency key** from creation.
- **AI may read any object to assist; it may change none directly** — it proposes; the process acts.
