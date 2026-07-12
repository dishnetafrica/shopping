# CloudBSS — Implementation Status & Handover

_Reference for the consolidation work delivered across Epics 4–11 and the design system (E9).
Every change in this document is additive and gated behind a default-off feature flag, so deploying
the code changes nothing for existing tenants until a flag is switched on per tenant._

---

## 1. The core idea

The work followed one principle from the ERP Constitution: **one source of truth per fact.** Instead
of many screens each recomputing money, cash, stock, and reports with their own slightly-different
formulas (which drift apart), every number now flows from a single service or projection:

| Fact | Single source of truth |
|------|------------------------|
| Currency conversion | `App\Services\Money\Fx` (effective-dated) |
| Customer payments | `App\Services\Money\PaymentService` → one `CustomerPayment` ledger |
| Cash on hand | `App\Services\Money\CashService` (one signed-sum rule) |
| Stock on hand | `App\Services\Inventory\StockLedger` (append-only `stock_movements`) |
| Accounting / P&L | existing **frozen** `LedgerService` + `TrialBalance` (unchanged) |
| Reports | `App\Reports\*` definitions (read SSOT only) |
| Dashboards | `App\Dashboard\*` widgets (read SSOT only) |
| Lists / registers | `App\Support\Table\ListBuilder` (one search/filter/sort/page engine) |

Reconciliation probes prove these agree with the legacy numbers before any read is switched over.

---

## 2. What was built, by epic

**Epic 4 — Payment unification.** All four "money in from a customer" paths (seller store, invoice
Pay, staff receive, cashbook record-payment) converge on `PaymentService` → one `CustomerPayment`
ledger when `pay_unify` is on. `orders.payment` was un-overloaded into typed `sell_currency` +
`payment_method` columns (E4.5), and the duplicate `ledger_entries` payment write was retired with a
reconcile probe (E4.6).

**Epic 5 — Cashbook consolidation.** `CashService` is the one signed-sum cash rule; Cash Position /
Staff Cash / My Cash source it (E5.2) and are declared as tabs of one Cashbook (E5.3); the legacy
`ledger_entries` cashbook redirects to the unified one after cutover (E5.4).

**Epic 6 — Inventory ledger.** A new append-only `stock_movements` table + `StockLedger` service
(IN/OUT/MOVE/ADJUST, on-hand = Σ signed movements per SKU/location). Consignment sale/receive
dual-write it, catalog stock edits mirror into it, it's backfilled to match today, and on-hand reads
switch to the projection behind `stock_v2` with `products.stock` becoming a derived cache.

**Epic 7 — Reporting.** A declarative `Report` contract (columns/filters/source) that reads **only**
SSOT services, a generic `GET /papi/reports/{key}` endpoint (filter-validated, CSV/PDF export), a
Sales report replacing the hard-coded-FX `invoiced.html`, and P&L + Trial Balance report views over
the existing ledger.

**Epic 8 — Shared table (partial).** A server list contract (`ListQuery`/`ListBuilder`/`ListResult`),
CSV+PDF export of the full filtered set, a framework-free JS `DataTable` component, and server list
endpoints for Invoices + Payments. _In-screen mounts (E8.4b/E8.5) remain._

**Epic 9 — Design system.** One `tokens.css` (palette/typography/spacing) linked on all 56 panel
pages; shared `toast`/`confirmDialog`/`promptDialog`/`selectDialog`/`errorMessage` helpers; and all
four money flows (invoice Pay, credit note, payments save/delete, consignment sale) migrated off
blocking `prompt()`/`alert()`/`confirm()`. _Cosmetic primitive migration (E9.4) remains._

**Epic 10 — Role dashboards.** A widget engine + `GET /papi/dashboard` (cash, open invoices, payments
today, low stock, net profit — by role, all reading SSOT), rendered on the home screen behind
`dash_v2`.

**Epic 11 — Accounting.** Recognized as **already implemented and frozen**
(`ACCOUNTING-CONTROLS-FROZEN-v3.0.md`): a full double-entry GL, trial balance, P&L, period locks. No
new accounting work was done; its numbers are now exposed through the report contract (E7.4).

---

## 3. Feature flags (all default OFF; per-tenant in settings)

| Flag | Enables | Turn on when |
|------|---------|--------------|
| `nav_v2` | Single grouped sidebar + Cashbook tabs | Anytime (nav only) |
| `pay_unify` | All payment paths → one CustomerPayment ledger; retires duplicate cashbook write | After reviewing `payments_reconcile` probe |
| `fx_v2` | Client/bot uses canonical Fx rate | Anytime |
| `perm_strict` / `perm_enforce` | Deny-by-default menus / role action gate | When roles are assigned |
| `stock_ledger` | Consignment + catalog stock events dual-write `stock_movements` | Enable **first**, then backfill |
| `stock_v2` | On-hand reads come from the ledger projection (requires `stock_ledger`) | After backfill + `stock_backfill` probe green |
| `table_v2` | (Reserved) register screens use the DataTable | After in-screen mounts land |
| `dash_v2` | Role dashboard renders on the home screen | Anytime |
| `ledger_enabled` | (Existing) accounting GL + P&L/trial-balance reports | Existing accounting flag |

**Recommended per-tenant turn-on order for a pilot:** `nav_v2` → `pay_unify` (check reconcile) →
`stock_ledger` → run migrations/backfill → `stock_v2` (check reconcile) → `dash_v2`.

---

## 4. Migrations to run on deploy

Run `php artisan migrate` (or `--force` in production). New this run:

- `2026_07_12_000005_add_order_currency_method` — typed `orders.sell_currency` + `payment_method` (+ backfill).
- `2026_07_12_000006_create_stock_movements` — the stock ledger table.
- `2026_07_12_000007_backfill_stock_movements` — seeds opening balances so the projection matches today (idempotent).

All are additive and reversible. After a bulk product import, re-running the (idempotent) backfill
seeds openings for any new products.

---

## 5. New endpoints & assets

- `GET /papi/reports` and `GET /papi/reports/{key}` (+ `?format=csv|pdf`) — reports (owner/manager/accountant).
- `GET /papi/dashboard` — role dashboard widgets.
- `GET /papi/invoices/table`, `GET /papi/payments/table` — DataTable list endpoints.
- `POST /papi/stock/reconcile-cache` — derive `products.stock` from the ledger (owner, requires `stock_v2`).
- `GET /panel/shared/tokens.css`, `GET /panel/shared/ui.js` — the shared design assets.

---

## 6. Validation — the selfcheck page

Every task shipped a probe on the Selfcheck page. Green = the piece is deployed and (where relevant)
reconciles. Key probes from this run: `order_typed_money`, `payments_reconcile`, `cash_service`,
`cash_rule_shared`, `cashbook_tabs`, `cashbook_retired`, `stock_ledger`, `stock_dual_write`,
`stock_backfill`, `stock_catalog_write`, `stock_read_v2`, `stock_derived`, `report_contract`,
`report_api`, `sales_report`, `ledger_reports`, `list_contract`, `list_export`, `datatable_component`,
`register_table`, `role_dashboard`, `dash_render`, `design_tokens`, `ui_helpers`, `money_flow_ui`.

A good pre-production pass: enable the flags on a test tenant and confirm these probes are green.

---

## 7. Test coverage

- **Feature/Unit tests** (run in CI with `vendor/`): FxTest, PaymentServiceTest, InvoicePayTest,
  StaffReceiveSyncTest, CashbookDualWriteTest, PaymentRetireTest, OrderTypedMoneyTest, CashServiceTest,
  NavConfigTest, CashbookRetireTest, StockLedgerTest, StockDualWriteTest, StockBackfillTest,
  StockCatalogWriteTest, StockReadV2Test, StockDeriveTest, ReportContractTest, ReportApiTest,
  SalesReportTest, LedgerReportTest, ListContractTest, ListExportTest, RegisterTableTest,
  DashboardTest, DesignTokensTest, and the earlier Epic 1–3 suites.
- **Render-simulations** (run with `node tests/render-sim/*.js`, no browser needed): `navv2_render`,
  `datatable_render`, `dashboard_render`, `ui_render`, `invoice_pay_render`, `credit_note_render`,
  `payments_delete_render`, `consignment_sell_render` — all green.
- Every backend change was also `php -l` syntax-checked and, where DB-free logic allowed, exercised by
  a standalone harness against the real classes.

---

## 8. What remains (all lower-risk polish)

- **E8.4b / E8.5** — mount the DataTable inside the Invoices/Payments screens (behind `table_v2`) and
  retrofit the remaining registers (credit-notes, suppliers, expenses, orders).
- **E9.4** — migrate remaining pages onto the shared `.ds-btn` / `.ds-card` / `.ds-empty` /
  `.ds-spinner` primitives.
- **Consignment logistics dialogs** — the non-money `prompt()`/`confirm()` chains (transporter/bus/
  boxes, cancel/notify/remove) were left for a follow-up.
- **E12 / E13** — hardening.

Two flagged product decisions are still open (deliberately not changed): the approved-vs-all-status
cash filter (Staff Cash vs Cash Position, from E5.2), and whether/when to make catalog stock edits
ledger-first and eventually drop the `products.stock` column (from E6.5).

---

## 9. Frozen constraint — accounting

`ACCOUNTING-CONTROLS-FROZEN-v3.0.md` freezes the accounting engine: **bug/security/regulatory fixes
only, no new accounting features without a real-world trigger** (VAT/tax registration, external audit,
separate legal entities, bank reconciliation, regulatory reporting). Epic 11 was therefore _not_
rebuilt; the existing GL/P&L is exposed through reporting instead. Do not add a second ledger.
