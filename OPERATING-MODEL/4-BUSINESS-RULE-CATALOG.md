# 4 — Business Rule Catalog

**Status:** DRAFT for approval · The invariants the system enforces no matter which screen, process,
or developer touches it. These are **executable specifications** — each should become an automated
test and a guard in code. A rule is broken = a bug, never a "config choice".

**Columns:** *Rule* · *Why* · *Enforced where* · *Status* (🟢 already enforced · 🟡 partial · 🔴 to build).

---

## Money & documents (the highest‑stakes rules)

| # | Rule | Why | Enforced where | Status |
|---|---|---|---|---|
| R1 | **A paid or part‑paid invoice's amounts cannot be edited.** Corrections are a new document (credit note / new invoice). | Money history must be immutable and auditable. | InvoiceService; guard on edit | 🟡 (void exists; block edit‑after‑pay) |
| R2 | **A receipt cannot exist without a Payment.** | A receipt is proof *of* a payment. | ReceiptRenderer takes a CustomerPayment | 🟢 |
| R3 | **A settlement payment applied to an invoice cannot exceed its outstanding balance;** any excess becomes an advance/credit, never a negative balance. | Prevents phantom money. | PaymentService::record + syncPaidFromPayments | 🟡 |
| R4 | **Cancelled/voided documents are never hard‑deleted.** They are marked void (with reason) and kept. | Audit trail; the CB‑0201/0202 and invoice cases already rely on this. | TradeCashbook::delete, Invoice void | 🟢 |
| R5 | **Every void requires a reason and is audit‑logged.** | Traceability. | AuditLogger | 🟢 |
| R6 | **A document's transaction currency is fixed at creation.** Display conversions are labeled and never re‑write stored amounts. | The entire currency saga's root fix. | SalesBaseCurrency; per‑doc currency | 🟢 |
| R7 | **Customer‑facing money is USD; Uganda purchases are UGX; South Sudan expenses are SSP.** Conversion only at display/settlement boundaries. | The agreed money policy (ADR‑004). | Fx, PanelCurrency, InvoiceService | 🟢 |
| R8 | **Prices come from the catalogue, never from AI or free text.** A quote/invoice re‑prices from the Product. | Stops invented prices (the bot‑quote fix). | OrderCalculator, InvoiceService re‑price | 🟢 |
| R9 | **A cross‑holder cash transfer creates two balanced legs;** the system can never create or destroy money in one leg. | Double‑entry integrity. | TradeCashbook::transfer | 🟢 |
| R10 | **Amounts are stored with their currency and a USD‑normalized value** (`usd_amount`) for consolidation. | One base for reports. | SalesBaseCurrency::stamp | 🟢 |

## Lifecycle & state

| # | Rule | Why | Enforced where | Status |
|---|---|---|---|---|
| R11 | **Only legal state transitions are allowed** (per each object's state machine, doc 2). Illegal transitions are rejected — online *and* offline. | Objects can't reach impossible states. | state guards per object | 🟡 |
| R12 | **A Quote can be converted to an Order at most once** (idempotent). | Prevents duplicate orders. | quotationConvert `already` guard | 🟢 |
| R13 | **An Order auto‑issues its Invoice on reaching `Delivered`** (idempotent per order). | Nothing delivered goes un‑invoiced. | OrderObserver → AutoIssueInvoice | 🟢 |
| R14 | **A Quote expires after 30 days** (tenant‑configurable) → `expired`; expired quotes can't be converted without a refresh. | Stale prices don't become orders. | scheduled expiry | 🔴 |
| R15 | **A Shipment cannot exceed the ordered quantity** without explicit approval. | Prevents over‑delivery / stock leaks. | fulfilment guard | 🔴 |
| R16 | **Inventory cannot go negative** without an approved adjustment. | Physical impossibility must be deliberate. | StockObserver guard | 🟡 |

## AI & automation (the "AI never owns the workflow" rules)

| # | Rule | Why | Enforced where | Status |
|---|---|---|---|---|
| R17 | **AI never computes a money figure shown to a user or customer.** It phrases figures produced by services. | Trust; no hallucinated numbers. | AiBrain guardrails | 🟢 |
| R18 | **AI never advances an object's state.** It proposes; a process (a human or a rule) acts. | Business runs with AI off. | process boundary | 🟡 |
| R19 | **AI never invents quantities/products in a quote;** if unstated, it asks. | The exact bot fix already shipped. | AiBrain captureQuote guard | 🟢 |
| R20 | **The business completes every process with AI and network both unavailable.** | Field reality in South Sudan/Uganda. | offline queue + no AI in critical path | 🟡 |

## Offline & integrity

| # | Rule | Why | Enforced where | Status |
|---|---|---|---|---|
| R21 | **Every offline‑created write carries a client‑generated id + idempotency key;** a retry can never double‑post. | No duplicate payments/orders after reconnect. | sync envelope | 🔴 |
| R22 | **Every mutating action emits its event** (doc 5). | Timeline/AI/accounting/analytics stay correct without cross‑calls. | emit() in services/observers | 🟡 |
| R23 | **Projections never hold source‑of‑truth data;** they are rebuildable from objects + events. | Safe to change/rebuild any screen. | projection layer | 🟢 |
| R24 | **A period, once locked, rejects edits/voids dated within it.** | Accounting close integrity. | AccountingGuard::isLocked | 🟢 |

## Access

| # | Rule | Why | Enforced where | Status |
|---|---|---|---|---|
| R25 | **A person sees and acts only within their role's capabilities** (Sell/Collect/…); the owner sees all. | Least privilege; clean UX. | permissions + role‑aware workspaces | 🟢 |
| R26 | **Owner‑only actions (void, normalize, settings) are gated server‑side,** never only hidden in the UI. | Security is not a hidden button. | ownerGate | 🟢 |

---

**How to use this catalog:** every rule above should map to (a) a guard in code and (b) an automated
test named for the rule (e.g. `test_R3_payment_cannot_exceed_balance`). New features must declare which
rules they must obey (governance rule, doc 0). Changing a rule is an amendment here + a new ADR.
