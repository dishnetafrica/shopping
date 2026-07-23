# Accounting §4a — One General Ledger: migration spec v1

**Status:** APPROVED direction · plan only (no code yet) · Constitution v1.0 · P0 Accounting
**Decision:** keep **`LedgerService`** as the single double-entry GL, **add VAT** to it, **retire the event-sourced kernel**. `CashService` (cash-on-hand position) is NOT retired — it's a legitimate cash view, not a competing P&L.
**Gate:** every step is shippable, reversible, and behind a reconciliation check. Nothing goes live until `reconcileCash` drift ≈ 0 and `php artisan test` is green. Money paths never break.

---

## 1. The reality we're consolidating

Three engines exist; **two are dormant**, one is live:

- **Cashbook (LIVE, single-entry, cash-basis)** — `order_costs` summed by a sign rule. Powers cash position (`CashService`), the running cashbook (`TradeCashbookController`), and the CEO income statement (`/papi/cash-pnl`, `PanelApiController::cashPnl`). This is the number the owner trusts today.
- **`LedgerService` GL (DORMANT — the KEEP)** — real 20-account double-entry, USD-functional, with FX clearing/gain/loss (`1900/4900/5900`), `backfillOrderCosts`, `reconcileCash`, and trial-balance / P&L / balance-sheet endpoints. `live_posting` defaults **false**; populated on demand by `ledger:backfill`. No tenant posts live today.
- **Kernel `cloudbss-kernel` (DORMANT — the RETIRE)** — event-sourced, posts on Order delivery, carries the Uganda 18% VAT policy, and is read by `BooksController`, `ProfitAndLossReport`, `TrialBalanceReport`, `NetProfitWidget`, Filament Books. `ledger_enabled` defaults **false**; off for every tenant.

Because both GLs are off, the migration is low-risk: we make `LedgerService` reproduce the live cashbook in **shadow**, prove it with `reconcileCash`, then switch — and the kernel can be removed without touching anything a tenant currently sees.

## 2. Target architecture

- **Capture (unchanged):** `order_costs` + sales documents stay the journal source. No change to how money is recorded.
- **One GL:** `LedgerService` posts every transaction (live), giving trial balance, P&L, balance sheet, VAT, and FX gain/loss from one place.
- **Cash views stay, as reports off the truth:** `CashService` = cash-on-hand position (a balance-sheet view, kept). The cash-basis P&L becomes a *reconciled* cash view derived alongside the GL P&L, not an independent computation.
- **Kernel gone:** its observers unregistered, its readers repointed to `LedgerService`, `ledger_enabled` removed.
- **One of each control:** a single canonical `order_costs` filter (status + amount basis) shared by CashService / cash-pnl / reconcile; one period-lock; VAT to account **2300** (not the kernel's colliding 2200).

## 3. The migration — staged, each stage shippable & reversible

### Phase 0 — Align the plumbing (no cutover, no behaviour change)
The three engines currently disagree on the `order_costs` filter, so `reconcileCash` can't be trusted until this is fixed:
| Surface | status filter | amount |
|---|---|---|
| CashService / TradeCashbook (headline) | `= approved` | `paid_native` |
| `/papi/cash-pnl` | `NOT IN (void,rejected)` | `usd_amount` |
| `reconcileCash` op-side | `IN (approved,pending)` | `paid_native` |
| CashbookCapability | `NOT IN (void,rejected)` | `native_amount`/`usd_amount` |

Action: define ONE canonical basis (recommend **`status = approved`, exclude `pay_status = credit`**, value at each row's `usd_amount` for the GL and `paid_native` for the cash view — the two are reconciled, not conflated) and route all four through a single helper (extend `CashService`). Unify period-lock (`AccountingGuard::period_lock_date` vs `ledger_config.lock_date`) onto one source. **Verify `tests/Unit/CashServiceTest.php` stays green** (it asserts the cash totals tie to TradeCashbook — that invariant must hold).

### Phase 1 — Shadow the GL (reconcile before trusting)
- Run `php artisan ledger:backfill` **with `live_posting` OFF** → populates `ledger_lines`/`ledger_journals` from `order_costs`.
- Run `reconcileCash` per tenant/currency → drive `drift_native` to ~0. This is the accounting equivalent of `stock:reconcile`: the GL must reproduce the cashbook before anyone relies on it.
- Compare the GL P&L (`/papi/ledger/pnl`) against the accrual target (`/papi/books-preview`) — explain any gap (it should be timing + recognized FX, below).
- Ship: nothing user-facing changes; this is validation only.

### Phase 2 — Add VAT to `LedgerService` (fully settings-driven)
The one thing the KEEP GL lacks. **VAT is never hardcoded — every part is a per-tenant setting**, so Uganda 18%, Kenya 16%, a zero-rated shop, or inclusive-vs-exclusive pricing are all just configuration.

- **Config (SHIPPED, dormant): `App\Support\VatConfig`** — the single reader + `split()` calc every screen and posting uses (one VAT math, not several). Settings it reads (per tenant, safe defaults, legacy keys honoured):
  | Setting | Type | Default | Meaning |
  |---|---|---|---|
  | `vat_enabled` | bool | `rate > 0` | master on/off |
  | `vat_rate` | number | falls back to legacy `quote_vat_pct`, else 0 | percentage, e.g. 18 |
  | `prices_include_vat` | bool | false | are catalogue prices VAT-inclusive? (existing key) |
  | `vat_label` | string | `VAT` | VAT / GST / Sales Tax … (invoice wording) |
  | `vat_number` | string | `''` | tenant tax-registration no. for invoice display |
- **Add these to the settings UI + writer** (Phase 2 code): entries in `app/Support/SettingsSchema.php` (a new "Tax / VAT" group) and the numeric/string/bool keys to `SettingsWriteService` (it currently only whitelists BOOL_KEYS — extend it for `vat_rate` number, `vat_number`/`vat_label` strings). `VatConfig::split()` is verified (inclusive/exclusive/rounding) and ready to wire.
- A tax field on the sales document (header-level `tax_total`; hardcoded 0 today, invoices have no tax field — small net-new).
- Sale posting splits gross via `VatConfig::split()` → **`Cr 4100` net revenue + `Cr 2300` output VAT**.
- Purchase posting splits → **`Dr 5100` cost + `Dr 2300` input VAT (recoverable)**.
- A **VAT-return report** (output − input over a period) reading `2300`, honouring `vat_label`/`vat_number` on the document.
- Tests: `VatConfig` split cases (inclusive/exclusive/rounding — done in the reader's verification) + a posting test that reconcile still ties out (VAT is a balance-sheet move, net P&L unchanged).

### Phase 3 — Retire the kernel (safe: it's off everywhere)
- Unregister the kernel observers (`LedgerServiceProvider::boot` → `Order::observe(OrderLedgerObserver)`, `LedgerEntry::observe(...)`).
- Repoint the five kernel readers (`BooksController`, `ProfitAndLossReport`, `TrialBalanceReport`, `NetProfitWidget`, Filament `Books`) to `LedgerService` trial-balance / P&L.
- Rewrite `tests/Feature/LedgerReportTest.php` (it asserts kernel behaviour) against `LedgerService`.
- Remove the `ledger_enabled` flag + the `CloudBSS\Kernel` autoload once nothing references it.

### Phase 4 — Cut over to live posting (per tenant, gated)
- For a tenant whose `reconcileCash` is clean: fresh `ledger:backfill`, then flip `live_posting = true` (via `LedgerController::toggleLive`). From then the GL posts every `order_cost` in real time.
- Schedule `recognizeFxClearing()` + `revalueForeignCash()` so trading FX gains/losses (which the cashbook nets to zero) are actually booked to `4900/5900`.
- Keep the cashbook capture running — it's the journal source, not a competitor.

### Phase 5 — One P&L truth
- The Finance screen presents the **GL P&L**; a cash-basis toggle is a derived view reconciled to it.
- Deprecate the independent `/papi/cash-pnl` and `/papi/books-preview` computations once the GL P&L is trusted (keep `CashService` cash position).

## 4. Reconciliation gates (the safety)

- **Before live-posting a tenant:** `reconcileCash` drift < 0.01 per currency.
- **Continuous:** a scheduled reconcile (like `stock:reconcile`) that alarms on drift between GL cash accounts and the cashbook.
- **Invariant:** `CashServiceTest` green throughout — the cash totals the owner trusts never move.
- **Explainable gap only:** GL P&L may differ from cash-basis P&L by (a) accrual timing and (b) recognized FX gain/loss — both correct, both documented for the owner.

## 5. Risks & mitigations (from the code audit)

- **Double-posting** if kernel + LedgerService both post → **retire the kernel observers before enabling `live_posting`** (Phase 3 before Phase 4). Since the kernel is off, this is clean.
- **Backfill races** → always backfill with `live_posting` OFF, then flip.
- **Engine filter drift** → Phase 0 canonical filter is a hard prerequisite; without it `reconcileCash` lies.
- **Per-currency vs consolidated / FX** → GL is USD-functional and books FX; the cash-basis view isn't. The difference is recognized FX — surface it, don't hide it.
- **Two period locks** → unify in Phase 0.
- **Account-code collision** → VAT goes to `2300 Tax Payable` (the kernel's `2200` clashes with `2200 Customer Advances`).
- **Behaviour-locking tests** → `CashServiceTest`, `FxTest`, `NoHardcodedFxTest`, `currency-basis.test.php` must stay green; `LedgerReportTest` gets rewritten in Phase 3.

## 6. Sequencing as small PRs (once `php artisan test` is green)

1. Phase 0 canonical filter + unified period-lock (guarded by `CashServiceTest`).
2. `stock:reconcile`-style scheduled `ledger:reconcile` alarm + shadow backfill (Phase 1).
3. VAT in `LedgerService` + VAT-return report + VAT test (Phase 2).
4. Retire kernel: unregister observers, repoint readers, rewrite `LedgerReportTest` (Phase 3).
5. Per-tenant live-posting cutover + scheduled FX recognition (Phase 4).
6. Finance screen → GL P&L; deprecate duplicate P&L computations (Phase 5).

## 7. What I need from you before Phase 2

VAT rate, inclusive/exclusive, label, and registration number are now all **tenant settings** (via `VatConfig`) — nothing to decide, you just configure them. Two design choices remain:

- Tax at **header or per-line** (recommend header-level `tax_total` first; per-line only if a shop mixes standard- and zero-rated items on one invoice).
- Which P&L is the **headline** for the owner after cutover: accrual GL P&L (recommended) with a cash toggle, or keep cash-basis as headline with the GL for the accountant.

Everything above is planning only. Phase 0 is the first code, and it's guarded so the owner's cash numbers cannot move.
