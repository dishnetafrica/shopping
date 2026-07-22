# 1 — Mission & Capability Map

**Status:** DRAFT for approval · The apex of the operating model. Everything below answers to this.

## Why capabilities sit at the top
Capabilities are the **most stable layer** of any business. A trading company will *Sell, Buy, Move,
Collect, and Manage* for decades; the processes under them change, the tools change, the objects get
refined — the capabilities barely move. So they become both the **organizing principle** and the
**governance anchor**: every feature must name the capability it serves, or it doesn't belong.

> **Nav vs architecture (an honest distinction):** capabilities organize the *system* and the
> *desktop* navigation. The *phone bottom bar* stays a role‑aware curation (Today + the two or three
> capabilities a role actually uses) — eight capability tabs would fail the one‑thumb test. Capability‑
> first governs the architecture; the UI still curates for the thumb.

---

## Company Mission
> Run a profitable trading company across Uganda and South Sudan — WhatsApp‑first, field‑sales‑led,
> multi‑currency — such that **a salesperson can complete an entire day of work with one thumb on an
> unreliable connection**, and the owner always knows the true cash and profit position.

Company‑level quality attributes (every capability inherits these): **offline‑first · one‑thumb ·
WhatsApp‑native · currency‑correct (USD base) · AI‑assisted but AI‑optional.**

---

## The eight capabilities

Each capability declares: **Purpose (its mission) · Owns processes · Owns objects · Quality
attributes (measurable) · Key events · Primary projection.**

### SELL
- **Purpose:** convert customer demand into cash while maximizing profit and customer satisfaction.
- **Processes:** Lead→Customer, Visit, Quote→Cash, Follow‑up (P1–P4).
- **Objects:** Customer, Visit, Quote, Sales Order, Invoice, Receipt.
- **Quality attributes:** create a quote in **< 10 s**; fully **offline**; **one‑handed**; **≤ 3 taps**
  to any common create from Today.
- **Events:** CustomerVisited, QuoteCreated/Accepted/Converted, OrderConfirmed.
- **Primary projection:** Today + Customer Timeline.

### BUY
- **Purpose:** ensure the right products are available at the right time while minimizing capital tied
  up in stock.
- **Processes:** Procurement — Demand→PO→Receive→Stock→Supplier Payment (P5).
- **Objects:** Supplier, Purchase Order, Product, Stock, Price List.
- **Quality attributes:** barcode scan **< 1 s**; stock lookup **< 2 s**; reorder list generated nightly.
- **Events:** PurchaseOrdered, StockReceived, LowStockDetected, SupplierPaid.
- **Primary projection:** Operations hub · "Items to Receive" · "Low Stock".

### MOVE (Deliver)
- **Purpose:** get goods to the customer reliably and provably.
- **Processes:** Fulfilment — Order→Pack→Dispatch→Deliver→POD (P6).
- **Objects:** Shipment, Delivery, POD.
- **Quality attributes:** POD photo capture **offline in < 30 s**; delivery status visible to customer.
- **Events:** DeliveryDispatched, GoodsLoaded, DeliveryCompleted, PODUploaded.
- **Primary projection:** "To Deliver" · shipment timeline.

### COLLECT
- **Purpose:** convert outstanding receivables into collected cash with minimum friction.
- **Processes:** Collections — Outstanding→Reminder→Receive→Receipt→Close (P7).
- **Objects:** Invoice, Payment, Receipt, Ledger Entry.
- **Quality attributes:** record a payment in **< 15 s**; **receipt generated immediately**; **auto‑sync**
  (never a manual "sync").
- **Events:** PaymentReceived, ReceiptGenerated/Sent.
- **Primary projection:** Today (collections) · "Money to Collect".

### SUPPORT
- **Purpose:** resolve customer issues and protect the relationship.
- **Processes:** Returns, Complaints/Escalations (P8–P9).
- **Objects:** Return, Credit Note, Escalation.
- **Quality attributes:** a return started from the customer timeline in **≤ 3 taps**; every resolution
  auditable.
- **Events:** ReturnInitiated/Approved/Completed, CreditNoteIssued, CustomerEscalated.
- **Primary projection:** Customer Timeline.

### MARKET
- **Purpose:** create demand and keep the pipeline full.
- **Processes:** Campaigns, Storefront, Ads.
- **Objects:** Campaign, Storefront collection, Lead.
- **Quality attributes:** a campaign composed + previewed (WhatsApp frame) before send; every send
  attributed to a customer timeline.
- **Events:** CampaignSent, LeadCaptured.
- **Primary projection:** Storefront Insights · campaign results.

### MANAGE
- **Purpose:** keep the company's money, people, and rules correct.
- **Processes:** Expense Approval, Cash Management, Daily Closing, Settings/Users (P10–P12).
- **Objects:** Expense, Cash Entry, User, Settings.
- **Quality attributes:** cash movement recorded in **< 15 s**; every money mutation two‑sided and
  balanced; period locks enforced.
- **Events:** ExpenseRecorded/Approved, CashTransferred, CashEntryVoided, CurrencyConverted.
- **Primary projection:** Finance hub · Owner dashboard.

### ANALYZE
- **Purpose:** turn facts into decisions — never store new facts.
- **Processes:** none (it is the projection + AI layer).
- **Objects:** none (reads events).
- **Quality attributes:** every figure traces to a real event/field; **no invented data**; period +
  currency always stated.
- **Events:** consumes all.
- **Primary projection:** Insights · AI Morning Brief · role dashboards.

---

## Capability → Process → Object ownership (one glance)

| Capability | Owns processes | Owns objects |
|---|---|---|
| Sell | Lead→Customer · Visit · Quote→Cash · Follow‑up | Customer, Visit, Quote, Order, Invoice, Receipt |
| Buy | Procurement | Supplier, PO, Product, Stock, Price List |
| Move | Fulfilment | Shipment, Delivery, POD |
| Collect | Collections | Invoice, Payment, Receipt, Ledger Entry |
| Support | Returns · Complaints | Return, Credit Note, Escalation |
| Market | Campaigns · Storefront | Campaign, Lead, Storefront |
| Manage | Expense · Cash · Closing · Admin | Expense, Cash Entry, User, Settings |
| Analyze | — (projections) | — (reads events) |

Objects that appear under two capabilities (e.g. **Invoice** in Sell *and* Collect) are **owned once**
(by the capability that creates them — Sell issues, Collect settles) and *referenced* by the other.
Ownership decides who may change an object's core state; referencing capabilities act through events.

---

## How this is used (governance)
Every new feature must first answer: **which capability, which process, which objects, which rules
(doc 4), which events (doc 5), which projections (doc 6)?** If it doesn't map to a capability's
purpose, question whether it belongs. This one rule is what prevents architectural drift at scale.
