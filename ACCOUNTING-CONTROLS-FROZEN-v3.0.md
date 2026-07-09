# Accounting Controls — Baseline Release ❄️ FROZEN (kernel-v3.0)

**Status:** Frozen as of this release. From here: bug fixes, security fixes, and
regulatory changes ONLY. No architectural churn, no new accounting features without a
concrete real-world trigger.

## What this baseline includes (v67 → v69, + this final logger tweak)
- **Money engine** — unchanged, reconciled against real cashbook export (balances, conversions,
  signs all verified correct). Multi-currency (USD/UGX/SSP), per-transaction native + rate + USD.
- **Immutable audit trail** — JSONB before/after snapshots + actor + IP + user-agent + shared
  request-id (correlates with HTTP logs, Sentry, response header). Audit failures are logged
  (full exception) but never block a transaction.
- **Soft-void everywhere** — no physical deletion of money records; void sets status='void',
  auto-excluded from all balances, preserved for audit.
- **Period lock** — protects void, delete, edit, AND back-dated create. Admin-only, audited.
- **Approval logging** — approve/reject records prev→new status + actor.
- **Integrity check** — on-demand: journals balance, entries valid, voids not leaking,
  negative-cash flags.
- **Authorization** — every accounting-control surface (audit page/API, integrity, period
  status/lock) gated to owner/manager. Money actions gated to owner/manager (+accounts for
  approve).

## Triggers that would justify RE-OPENING accounting work (not before)
- VAT / tax registration → tax posting engine
- External audit or investor due diligence → FX revaluation, fiscal-year close, full GL/AR/AP
- Genuinely separate legal entities → multi-entity consolidation
- Real bank-account volume → bank reconciliation
- Regulatory reporting requirement → IFRS-shaped statements

## Deliberately deferred (documented, NOT forgotten)
Auto-reversing GL journals on void; daily integrity CRON (currently on-demand); DB-level CHECK
constraints; extended integrity rules (orphan lines, missing FX rates, duplicate doc numbers);
policy/gate-based RBAC (currently controller role checks — fine at this size).

## Next engineering focus (business ROI order, per review)
1. Purchasing workflow   2. Supplier payment planning   3. Inventory forecasting
4. Operations dashboard  5. ShopBot intelligence        6. Mobile operations
7. Reporting             8. Accounting (only on a trigger above)

## This file's companion change
app/Services/AuditLogger.php — logs the full Throwable (stack trace/file/line) on audit failure.
