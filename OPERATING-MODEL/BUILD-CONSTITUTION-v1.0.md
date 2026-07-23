# CloudBSS Founder Build Constitution — v1.0

**Status:** RATIFIED · governing document · supersedes all conflicting roadmap items
**Scope:** the one source of truth for *what we build and why*. Every ADR, slice, and backlog item defers to this.
**Companion law:** `DESIGN-SYSTEM.md` (frozen v1.0) governs *how it looks*; this governs *what gets built*.

---

## Principle Zero — Consolidate before you add

> For every new feature, first look for existing implementations in the codebase, complete unfinished work, consolidate duplicates, and remove obsolete code. The objective is a cohesive, production-ready system with a **single source of truth for every business capability** — not the largest feature list.

This is the meta-rule that outranks every other priority. Before any new page, engine, or table is written, the answer to *"does a version of this already exist, and can I finish it instead?"* must be **no**. A pull request that adds a parallel implementation beside an existing one is rejected by default.

Why this is Principle Zero: the production-readiness audit found **three parallel stock systems, three parallel accounting systems, two campaign engines, a rule engine that is never called, and an event spine with no subscribers.** None of that came from bad engineering — it came from adding beside instead of finishing. That single habit is the largest threat to this codebase. This principle exists to end it.

---

## 1. Mission

CloudBSS is an **AI-first Business Operating System for wholesale trade.** Its customers are:

- Wholesale distributors
- FMCG trading companies
- Import / export businesses
- Retail chains and supermarkets
- Pharmaceutical distributors
- Construction suppliers

Every implementation decision must strengthen this mission. If a feature does not serve a wholesale/trading/distribution operator, it does not belong in this product.

## 2. First Principle

Never build a feature because it is technically interesting. Build only features that do at least one of:

1. **Reduce work** (fewer taps, fewer screens, fewer manual steps)
2. **Increase sales**
3. **Improve inventory accuracy**
4. **Improve cash flow**
5. **Improve management visibility**

If a proposed change cannot be tied to one of these five, it is cut.

## 3. Permanently out of scope

The following are **struck from the product and the roadmap**, effective v1.0. Any prior recommendation, review note, ADR, or backlog item touching them is **void**:

- ISP / internet service provider functionality of any kind
- Recurring / subscription billing for end customers
- RADIUS, PPPoE, hotspot, voucher, CPE, router/network provisioning
- Usage / bandwidth / data metering and rating
- Subscriber service accounts
- Anything specific to **DishNet Africa** as an ISP

CloudBSS is not an ISP platform. Reviews that analysed ISP fit remain useful **only** for their architectural findings; their ISP-specific recommendations are discarded.

---

## 4. Single Source of Truth — the consolidation decisions

Principle Zero, made concrete. For each capability that currently has more than one implementation, this table names the **one** that survives and what must be migrated out. **No new engine may be created beside any of these.**

| Capability | The ONE (keep) | Remove / migrate out | State today | 
|---|---|---|---|
| **Inventory engine** | **Stock Ledger** (append-only `StockMovement` + `StockLedger` projection) | Legacy `products.stock` cache column and consignment `qty_*` as *truth*; `InventorySnapshot` demoted to bot-belief only, never accounting | Ledger is **built but gated OFF** (`stock_ledger`/`stock_v2` flags). Enable → backfill → switch reads → make it the only on-hand source. |
| **Accounting engine** | **One double-entry GL** posting from the operational transaction source — *recommendation below, needs sign-off* | The other two GLs; stop presenting cash-basis P&L as a separate truth (it becomes a **report off the one ledger**) | Three engines: operational cashbook (live, cash-basis), `LedgerService` GL (dormant), event-sourced kernel (dormant). See §4a. |
| **Notification / campaigns** | **`CampaignService` (M4)** for outbound campaigns; **WebPush + order-notification jobs** for transactional (complementary, not duplicate) | Legacy `Campaign` / `SendCampaign` WhatsApp-blast engine | Two campaign engines run in parallel. Migrate legacy sends onto M4, delete the old path. |
| **Timeline** | **BOS `TimelineProjection`** (from `bos_events`) | Ad-hoc `ActivityFeedItem` / `ActivityReviewItem` feeds as separate timelines | BOS timeline is canonical but young. Route all "history" reads through it. |
| **Search** | **One search service** over customers / invoices / products / quotes | Scattered per-screen query endpoints | Global search is partly mocked in V3; back it with one real service. |
| **Rule engine** | **BOS `Bos::enforce()` / rule registry** as the single enforcement point | Inline financial guards duplicated across controllers | Rule registry exists (R2/R3/R4) but is **dead code — never called.** Either wire the financial invariants through it or delete it; do not leave a third state. |
| **Event engine** | **BOS `EventStore` (`bos_events`)** | Treating domain event logs (`OrderTaskEvent`, `OrderDraftEvent`, …) as *the* event system | EventStore is real but has **zero subscribers** and narrow emission. Complete it: register subscribers, broaden emission, drive projections from it. |

### 4a. The one open decision — which accounting engine

This is the single choice in this Constitution I will not make unilaterally, because migrating accounting touches money and is expensive to redo. The three candidates:

- **A — Operational cashbook** (`order_costs`): live, cash-basis, single-entry. *Cannot* be the GL (no double-entry → no trial balance, no accountant export). But it is the real, working transaction capture and must remain the **journal source**.
- **B — `LedgerService` double-entry GL:** simpler, already integrated with `order_costs`, USD-consolidated reporting, has period lock + FX. **Lacks VAT posting.**
- **C — Event-sourced kernel:** most complete (VAT policies, AR/AP aging, idempotent durable posting) **but** posts per-(tenant, entity, currency) with **no cross-currency consolidation** — a problem for a USD/UGX/SSP business.

**Recommendation: keep `order_costs` + sales documents as the journal source, and make `LedgerService` (B) the single General Ledger** — turn on live posting, **add VAT posting to it** (the one thing it lacks), reconcile every P&L to it, and **decommission the kernel (C).** The cash-basis view becomes a report derived from the one ledger, not a competing engine. Rationale: B is already wired to the live money source and gives one consolidated multi-currency statement; C's consolidation gap would cost more to close than B's VAT gap.

→ **Requires the founder's explicit yes before any accounting migration begins.** Until then, nothing in the accounting layer is touched.

---

## 5. P0 — Production Foundation

**Nothing in §6 begins until §5 is done.** These are not features; they are the difference between a demo and a business you can operate, secure, and recover.

### Security
- Proper Role-Based Access Control (Laravel Policies / Gates or spatie-permission)
- Policy-based authorization on **every** `/papi/*` and workspace action
- **Deny-by-default** permissions (an unknown role can do nothing)
- Automated **tenant-isolation test** (guards the 160 manual scope-bypasses; one miss = cross-tenant leak)

### Reliability
- Error tracking (e.g. Sentry) — today production failures for field users are invisible
- Automatic backups **with a tested restore** — no launch on a system you can't recover
- System-wide audit logging (extend the accounting-path audit trail to all mutations)

### Inventory — consolidate to one engine
- Enable the **Stock Ledger**; backfill; switch reads
- **Remove** the legacy inventory paths as sources of truth
- Stock Ledger becomes the **only** source of inventory truth. No duplicate stock systems remain.

### Accounting — consolidate to one engine
- Execute the §4a decision (after sign-off): one GL, one reconciled P&L, cash view as a report
- Add VAT posting to the chosen engine
- Retire the other ledgers

### Offline — a proper queue
- **Never delete a failed sync.** (Today a failed outbox item is silently discarded — this is unacceptable for field-collected money data.)
- Retry automatically; user can manually retry
- Queue **survives app restart**
- Failed items surface in a visible "needs attention" state

### P0 money-path safety rule (carried from prior governance, still in force)
Every P0 change is an overlay or a reversible migration. **Money paths must never break.** Each consolidation ships behind verification (lint, simulation, reconciliation check) before the old path is removed.

---

## 6. P1 — Complete Business Workflows

Build **complete workflows, not isolated pages.** A screen's job is to answer *"what should this employee do next?"* — never *"which module should I open?"* The four spines that must work end-to-end on mobile:

**Sell:** Quotation → Sales Order → Picking → Packing → Delivery → Invoice → Payment → Receipt → Customer Timeline

**Buy:** Purchase Order → Supplier Delivery → Goods Receipt → Stock → Supplier Invoice → Supplier Payment

**Replenish:** Reorder Point → Suggested Purchase → Purchase Approval → Receiving → Stock Update

**Grow the customer:** Lead → Quotation → Sale → Collections → Follow-up → Retention

### P1 Inventory
Warehouse · Bin locations · Goods Receipt Note (GRN) · Stock Transfer · Cycle Count · Stock Adjustment · Barcode scanning · Batch numbers · Expiry dates

### P1 Finance
VAT · Credit Notes · Debit Notes · Credit Limits (enforced, with holds) · Customer Statements · Supplier Statements · Bank Reconciliation · Trial Balance · Accountant Export (QuickBooks / Xero / CSV)

### P1 Sales
Customer Price Lists · Promotions · Volume Pricing · Discount Rules · Customer Segments · Sales Targets · Route Planning · Collection Runs (make the guided Collect Run real + offline-capable)

---

## 7. AI is the operating system

The AI assistant is not a chatbot bolted on; it is the layer that turns data into the next action. It **proactively surfaces insight** instead of waiting to be asked:

- **Morning:** "Today's priorities."
- **Collections:** "Visit these five customers first."
- **Inventory:** "Order these products today."
- **Finance:** "Three customers are about to exceed their credit limit."
- **Management:** "Profit is down because the sugar margin dropped."

Every P1 workflow must expose the hooks the assistant needs to say the next sentence.

## 8. Mobile-first mandate

Everything works from mobile. A salesperson must be able to complete an entire workday — quote, sell, deliver, collect, follow up — **without ever opening the desktop application.** Desktop-only capabilities (credit notes, ledger, opportunity pipeline) are migrated to V3 as their workflows are completed.

---

## 9. Definition of done (for the program, not a ticket)

The program is "done enough to launch" when:

1. All §5 P0 foundations are complete and verified.
2. Every capability in §4 has **exactly one** implementation; obsolete parallels are deleted, not just hidden.
3. The four §6 spines run end-to-end on mobile.
4. No screen shows a number that isn't real. (Mocked figures — customer score, lifetime value, trend badges — are either wired to real data or removed.)

The goal is a **cohesive, production-ready system with a single source of truth for every business capability** — the operating system for a wholesale business, not the biggest ERP feature list.

---

*Ratified v1.0. Amendments require a new version number and a note here of what changed and why.*
