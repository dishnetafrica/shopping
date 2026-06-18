# Win World MES — Phase 3, Batch 2: SLA escalation + exception workflows

Builds on the sales workflow batch. Completes the CRM SOP: overdue work chases itself, and the
SOP exceptions get the same SLA + approval treatment.

## A) Sales SLA-breach escalation (WhatsApp)
- `Alerts::slaBreach()` (tested) + `WinworldNotifier::scanSalesSla()` — open orders past their stage SLA
  fire a WhatsApp alert to the **owner role**; once **2h+ overdue** they **escalate to SM**.
- Deduped via new `sla_alerted_at` column (max once / 2h).
- Rides the **existing `ww:scan`** schedule — the command now runs delay-risk **and** sales-SLA sweeps. No new schedule line.

## B) Exception workflows  `/panel/exceptions`
SOP §4-6, one screen. Each type carries its owner role, SLA clock and approval chain:

| Type | Owner | SLA | Approvals |
|---|---|---|---|
| Complaint | PD.M | 8 working h | none (PD.M handles) |
| Goods return | SDH/SM | 3 working h | **SM**, plus **MD when amount > 10,000,000** |
| Credit note | Sales Coord | 4 h | SM → MD |
| Debit note | Sales Coord | 4 h | SM → MD |

- **ExceptionFlow** pure engine (the 10M goods-return MD gate lives here), tested.
- Capture (type, customer, link a sales order, subject, amount — with a live hint showing the approval path),
  SLA badge, **Approve SM/MD** (only when required), **Resolve** (unlocks once approvals are in), Reject/Reopen.
- Migration `ww_exceptions`; `WwException` model.
- `qa/ww_exceptions.php` — 24 assertions (ExceptionFlow + slaBreach).

## Files that REPLACE earlier copies
`app/Services/Winworld/Alerts.php` (added slaBreach), `app/Services/Winworld/WinworldNotifier.php` (added scanSalesSla),
`app/Console/Commands/WinworldScan.php` (added sales sweep), `app/Models/WwSalesOrder.php` (added sla_alerted_at).

## Install
1. Deploy files. 2. Append routes from `winworld-routes-append-exceptions.txt`. 3. `php artisan migrate --force`. 4. `php artisan config:cache`.
5. Open **/panel/exceptions**. Escalation works as soon as `ww:scan` is scheduled (from the notify batch).

## QA
`php qa/ww_exceptions.php` → 24. Full module sweep: **199 assertions green across 12 suites.**
