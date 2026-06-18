# Win World MES — Phase 3, Batch 1: Sales workflow + SLA engine

Digitises the CRM SOP (WW/SM/CRM/SOP/01): the order pipeline with SLA clocks and SM/MD approvals.

## What's in it
- **SlaClock** (pure, tested) — due time + ok / due-soon / overdue, for clock-hours, working-hours and working-days
  (office calendar 08:00–17:00 Mon–Sat).
- **SalesFlow** (pure, tested) — the stages and their owner role + SLA:
  Enquiry (3h) → Order received (1h) → Credit & ageing check (1h) → SAP posting & approval (1h) →
  Order indent (2h) → Delivery (3 working days). Plus the approval gates:
  **SAP = SM then MD**; **credit check needs MD when the customer is >30 days overdue** (SOP 3.3.2).
- **Sales board** `/panel/sales` (`sales.html` + `WinworldSalesController`):
  - Capture an order (customer, source visit/whatsapp/email/call, product, qty, value, evidence note).
  - Each order shows its **stage**, a live **SLA badge** (on time / due soon / overdue + due time), and the owner role.
  - **Approve SM / Approve MD** buttons appear only when that approval is required; **Advance** unlocks once approvals are in.
  - Overdue-customer orders show a red "MD" gate. Won / Lost / Hold / Reopen. At the indent stage, an **Open indent** bridge to production.
  - Filters: Open / Overdue / Won / All.
- **Audit trail** — every capture/advance/approve/result is logged to `ww_sales_events` (who, role, stage, when).
- Migration: `ww_sales_orders` + `ww_sales_events`.
- `qa/ww_salesflow.php` — 25 assertions.

## Install
1. Deploy files. 2. Append routes from `winworld-routes-append-sales.txt`. 3. `php artisan migrate --force`. 4. `php artisan config:cache`.
5. Open **/panel/sales**.

## QA
`php qa/ww_salesflow.php` → 25. Full module sweep: **175 assertions green across 11 suites.**

## Next in Phase 3 (Batch 2)
- **WhatsApp SLA-breach escalation** (overdue stage → owner role, then up the chain) via the existing notifier + `ww:scan`.
- **Exceptions**: complaints, goods returns (10M / MD limit), credit/debit notes.
- True indent bridge (prefill the indent from the order at the indent stage).
