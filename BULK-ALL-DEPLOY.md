# BULK-ALL-DEPLOY.md — UCRM-style bulk select + export on EVERY major list

## Now selectable with a bulk bar (checkbox column + select-all-on-page)
| List | Bulk actions |
|---|---|
| **Quotations** | Export CSV · Export PDF · **Send on WhatsApp** (loops the proven per-quote send, ≤25, paced) |
| **Customers** | Export CSV · Export PDF — includes the V4 relationship columns: stage, health, flags, assigned rep, last contacted |
| **Orders** | Export CSV · Export PDF (totals + paid summed) |
| **Payments** | Export CSV · Export PDF (amount + USD summed, receipt/order/invoice refs) |
| **Products** | Export CSV · Export PDF (price AND cost — instant margin sheet in Excel) |
| Invoices | already live from the previous zip |

## One engine behind them all
`BulkExportController` — GET /papi/export/{entity}. CSV with UTF-8 BOM (Arabic names
open correctly in Excel) · PDF landscape A4 with a totals footer. Adding any future
list = one match-arm + two lines in its capability.

## Files (9) · Deploy
BulkExportController (NEW) · 5 list capabilities · PanelApiController
(quotationBulkSend) · routes · this doc — upload → Rebuild →
`php artisan deploy:check` → hard-refresh → open Quotations → tick a few → the
bulk bar appears. The Customers CSV is the fun one: your whole customer base with
AI relationship stages and health scores, straight into Excel.
