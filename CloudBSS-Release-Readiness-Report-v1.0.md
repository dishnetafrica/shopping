# CloudBSS — Release Readiness Report v1.0

_Evidence-based, not narrative. Every "complete" below is backed by a verification that was actually
run, and the report is explicit about which evidence was executed in the build environment versus
which must still be produced in your CI / staging environment._

---

## 0. A note on evidence types (read this first)

There are two classes of evidence in this report, and they are **not** equal:

- **Executed here ✅** — commands actually run in the build environment: `php -l` syntax checks (609
  `app/**.php` files, 0 errors), the 9 Node render-simulations (all pass), standalone PHP harnesses
  that load the real classes, and `grep`/wiring checks.
- **Written, not yet executed ⏳** — the 35 PHPUnit feature/unit test files. This environment has **no
  `vendor/` and no database**, so the PHPUnit suite has **not been run**. It is syntax-valid and
  logically complete, but "the tests pass" is a claim your CI must prove, not one I can make here.

This distinction is the single most important input to the Go/No-Go decision below.

---

## 1. Executive Summary

| Metric | Value | Basis |
|--------|-------|-------|
| Roadmap scope completed (code) | **~80%** | Epics 4–7, 10, 11 code-complete; 8 and 9 partial; 12–13 not started |
| Production readiness | **~55%** | Gated by un-executed CI suite, un-run migrations, and un-verified reconciliation on real data |
| Architecture readiness | **High** | One SSOT per fact, flags default-off, reconciliation designed in |
| Critical blockers to pilot | **3** | (a) CI suite not yet run green, (b) migrations + rollback not yet run on staging, (c) reconciliation probes not yet green on a real tenant |

**Known limitations (by design or scope):**
- Money-flow UI migration is complete for the **four named flows**, but the invoice/payments/
  consignment **screens still contain other blocking dialogs** (WhatsApp send, print, consignment
  logistics) — counted: invoices 11, payments 3, consignment 22.
- Epic 8's DataTable is **not yet mounted in any screen** (server endpoints exist; `table_v2` is
  reserved).
- Performance, security hardening, monitoring, and backups were **out of scope** for this run and are
  unverified here.
- Two product decisions are deliberately **open** (approved-vs-all-status cash filter; ledger-first
  stock writes / dropping `products.stock`).

---

## 2. Epic Status — with evidence

Legend: ✅ Complete (code) · 🟡 Partial · ⛔ Blocked · ⏸ Deferred. "Complete (code)" means the code and
executed-here checks pass; it does **not** mean the PHPUnit suite has been run in CI.

| Epic | Acceptance criterion | Evidence (executed here unless noted) | Status |
|------|----------------------|----------------------------------------|--------|
| **E4** Payments | One payment ledger; `orders.payment` un-overloaded; duplicate write retired | `PaymentService::record` present; `add_order_currency_method` migration; `E4.6 … retired` marker present; PaymentServiceTest/PaymentRetireTest/OrderTypedMoneyTest written ⏳ | ✅ code |
| **E5** Cashbook | One cash source | `CashService::IN_STAGES` referenced by **both** Cash Position + Staff Cash; CashServiceTest asserts service == cashbook oracle ⏳; cashbook_retired probe | ✅ code |
| **E6** Inventory | Stock ledger; dual-write; backfill; read-switch; derived cache | `create_stock_movements` migration + `StockLedger`; `consignment_sale`/`consignment_receive` markers; `readsFromLedger` + `syncProductStockCache`; StockLedger/Backfill/DualWrite/Read/Derive tests written ⏳ | ✅ code |
| **E7** Reporting | Report contract reading SSOT; endpoint; P&L over existing ledger | 5 registered report defs; `reports/{key}` route; P&L reads `CloudBSS\Infra\Reporting\TrialBalance` (no new ledger); ReportContract/Api/Sales/Ledger tests written ⏳ | ✅ code |
| **E8** Shared table | List contract + export + component + retrofit | ListQuery/Builder/Result/Exporter present; `invoices/table`+`payments/table` routes; DataTable render-sim ✅. **No in-screen mount** (grep: `CloudBSSDataTable` not in any screen) | 🟡 partial |
| **E9** Design system | Tokens + UI helpers on all pages; money flows off blocking dialogs | tokens.css + ui.js linked on **56/56** pages; ui/invoice-pay/credit-note/payments-delete/consignment-sell render-sims ✅. **Other screen dialogs remain** | 🟡 partial |
| **E10** Dashboards | Widget engine + role dashboard on home | DashboardController + `dashboard` route + `Roadmap E10.2` block in seller.html; dashboard render-sim ✅; DashboardTest written ⏳ | ✅ code |
| **E11** Accounting | One ledger; trustworthy P&L | Existing `LedgerService`+`TrialBalance` present and **frozen**; only `StockLedger` (inventory) added this run — **no second accounting ledger** | ✅ (pre-existing, frozen) |
| **E12/E13** Hardening | — | Not started | ⏸ deferred |

_Epics 1–3 (nav, permissions, FX) were completed in the prior run and are not re-verified here._

---

## 3. Production Checklist

| Item | State | Evidence / note |
|------|-------|-----------------|
| Database migrations | 🟡 Written, not run | 3 new migrations, all additive; **not executed** (no DB here) |
| Rollback tested | 🟡 `down()` present, not exercised | All 3 migrations have `down()`; not run |
| Feature flags | ✅ | 9 flags, all default-off; verified in code + handover |
| Self-checks / probes | ✅ Wired | ~25 new probes; **not yet observed green on a live tenant** |
| Reconciliation (0-diff) | 🟡 Designed + unit-asserted, not on real data | CashServiceTest asserts equality; `payments_reconcile`/`stock_backfill` probes exist; not run against production data |
| Automated tests | ⏳ Written, not executed | 35 test files, syntax-valid; **CI must run them** |
| Static syntax | ✅ | 609 `app/**.php` files, 0 `php -l` errors |
| Render-sim (client JS) | ✅ | 9/9 pass |
| Monitoring | ⛔ Not addressed | Out of scope this run |
| Backups | ⛔ Not addressed | Out of scope; **must exist before any migration** |
| Performance | ⛔ Unverified | New endpoints not load-tested; N+1 avoided by design in list/dashboard reads |
| Security | 🟡 Partial | Endpoints role-gated (owner/manager/accountant); no security review/pen-test |
| Permissions | ✅ (code) | RBAC from Epic 2; `perm_strict`/`perm_enforce` flags |
| Accounting | ✅ Frozen, respected | No changes to the frozen GL; P&L exposed read-only |

---

## 4. Deployment Plan

**Day 1 — deploy, flags OFF (zero user-facing change)**
```
- Take a full database backup (mandatory prerequisite).
- Deploy code.
- php artisan migrate --force   (3 additive migrations; backfill is idempotent)
- All feature flags remain OFF. Verify the app behaves exactly as before.
- Open the Selfcheck page: "deployed" probes green, flag-gated probes show "wired".
```
**Day 2 — presentation flags (no data path change)**
```
- Enable nav_v2 on the pilot tenant. Confirm the single grouped menu + Cashbook tabs.
- Enable dash_v2. Confirm the role dashboard cards on the home screen.
```
**Day 3 — payments cutover**
```
- Enable pay_unify. Record a test payment via each path.
- Confirm the payments_reconcile probe is GREEN. If not, disable and investigate.
```
**Day 4 — stock ledger (write, then read)**
```
- Enable stock_ledger. Do a consignment receive + sale and a catalog stock edit.
- Re-run the backfill migration (idempotent) so all products have opening balances.
- Confirm stock_backfill probe green. THEN enable stock_v2 and confirm on-hand reads match.
```
**Day 5+ — observe, gather pilot feedback, then iterate on remaining polish (E8 mounts, E9.4).**

Each step is independently reversible by turning its flag back off.

---

## 5. Go / No-Go Decision

```
NOT READY  (for production; conditionally READY FOR PILOT once 3 gates pass)
```

**Why NOT READY today:** the code and all *executed-here* checks are clean (609 files 0 syntax errors,
9/9 render-sims, harnesses green, wiring verified), but three pieces of gating evidence **have not been
produced** and cannot be produced in this build environment:

1. The **35 PHPUnit tests have not been run** (no `vendor/`/DB here). A pilot must not launch on
   unexecuted tests.
2. The **migrations have not been run**, and rollback (`down()`) has not been exercised, on a real
   database.
3. The **reconciliation probes** (`payments_reconcile`, `stock_backfill`, `cash_service`) have not
   been observed green against a real tenant's data.

**This flips to READY FOR PILOT the moment all three are satisfied:**
```
[ ] CI runs `php artisan test` (or phpunit) → all green, including the 35 new files
[ ] Migrations run on a staging clone → succeed; a down()/rollback dry-run succeeds
[ ] On a staging tenant with real data, enable the flags in the Day 2–4 order and confirm
    payments_reconcile + stock_backfill + cash_service probes are GREEN
```
None of these are code changes — they are verification runs in an environment I don't have access to.
The architecture, flags, migrations, and safe rollout order are all in place to make them pass.

**Explicitly out of pilot scope (accept as known gaps):** monitoring, backups automation, load/perf
testing, a security review, the E8 in-screen table mounts, the E9.4 cosmetic migration, and the
remaining non-money-flow dialogs. None block a controlled pilot; all should precede a full rollout.

---

## 6. Recommendation

Treat the three gates above as your pilot entry criteria. Run them in CI/staging; if they pass, this
is **READY FOR PILOT** on one friendly tenant with the Day-by-Day flag sequence and a database backup
in hand. Then switch to the operating loop — implement one roadmap task, review, test, deploy to the
pilot, gather feedback, repeat — and stop producing design documents. The documentation set (UX Audit
→ Architecture → Constitution → Roadmap → Handover → this Readiness Report) is complete.
