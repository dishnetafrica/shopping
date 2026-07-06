# CloudBSS Kernel v2 — Architecture Decision Records

- **ADR-000** ERP Architecture Principles — the constitution EVERY future ADR must obey (rules, not
  features). Read this first.
- **ADR-001** Kernel v2: dual-currency ledger core (frozen kernel only — JournalAmount{foreign,base})
- **ADR-002** Treasury bounded context (custodians, branches, transfers, cash counts, dashboard)
- **ADR-003** Exchange Rate Service (rate table, providers, override/approval; metadata on the event)
- **ADR-004** Reporting & FX (trial balance, foreign balances, realized/unrealized FX, revaluation)
- **MILESTONES.md** M1–M5 with test gates and sequencing discipline
- **ADR-TEMPLATE.md** the structure every new ADR (005+) follows

Build order (reordered per review): **M1 Kernel → M2 Treasury (same-currency) → M3 Exchange Rates →
M4 Settlement (unlocks cross-currency treasury) → M5 Reporting.** Treasury moves earlier because
same-currency custody/transfers/counts need no FX and are usable immediately; cross-currency parts
wait for M3+M4.

Design boundary (ADR-000 §7): the KERNEL owns double-entry integrity, base balancing, immutability,
currency correctness. TREASURY / RATES / REPORTING are bounded contexts that produce or read ledger
entries through events — without expanding the kernel.

Status: APPROVED for implementation. Prove production single-currency with real orders first (ADR-000 §12).
