# Win World MES — Phase 1, Batch 1 (engines + schema + models)

Drops into the CloudBSS app (`shopbot-saas`) at the repo root — paths are repo-relative.

## What's in this batch
**Engines (pure, framework-free, fully unit-tested):** `app/Services/Winworld/`
- `Formula.php` — gram/pcs (W×L×gauge/3300), order kg, manual-wins output resolution, required hours, elapsed/actual output.
- `ShiftCalendar.php` — 12-hour window (07:00–19:00) scheduling: planned_end spills to the next day, optional holidays/working-days.
- `Blending.php` — BOM recipe across Ext-A/B/C from mixing qty × %, with per-column balance validation.
- `Oee.php` — OEE = Availability × Performance × Quality (capped), plus uncapped efficiency %.

**Schema:** `database/migrations/2026_06_18_1000xx_*` (idempotent, `ww_`-prefixed, tenant-scoped)
- masters: `ww_items`, `ww_machines`, `ww_materials`, `ww_customers`
- indent: `ww_indents` (full OIF header + specs), `ww_indent_blends`, `ww_indent_qc`
- `ww_plannings`, `ww_production_entries`

**Models:** `app/Models/Ww*.php` — `BelongsToTenant`, relations, thin helpers that call the engines
(`recomputeGramPerPcs`, `recomputeOrderKg`, planning `recompute`, production `recompute`/`oee`).

**Tests:** `qa/ww_*.php` — 65 assertions, all green:
`ww_formula` 17 · `ww_shift` 10 · `ww_blending` 12 · `ww_oee` 17 · `ww_acceptance` 9.

## Run the tests
```
php qa/ww_formula.php && php qa/ww_shift.php && php qa/ww_blending.php && php qa/ww_oee.php && php qa/ww_acceptance.php
```

## Assumptions baked in (correct me if wrong)
- **A1** `final_output = manual` when set, else advisory auto. (Q2 — validate output rate vs a real daily report.)
- **A2** processes optional per indent via flags; one Planning row per active process; cross-process sequencing deferred. (Q3)
- **A3** no SAP dependency in Phase 1.
- **A4** PHP 8.3 / Laravel 11 / Postgres; single 12-h shift (Q6).

## NOT in this batch (next steps, in order)
1. **Seeders** — the 22 machines + the sample Item/Material masters, so the panel opens with real data.
2. **Filament resources** — Item/Machine/Material/Customer admin, the Indent screen (with clone-last + blending lines + QC), the Planning board, and the **mobile Production Entry** screen (the make-or-break UI).
3. **OIF PDF** + digital QC sign-offs (Phase 2).
4. **Machine Board / Target-vs-Actual / Daily Report / OEE Dashboard** + the WhatsApp notification engine (Phase 2).

Filament wiring needs one confirmation: these models use your existing `BelongsToTenant` trait, so they require the panel to run under `SetTenantFromUser` (same as your other tenant models) — confirmed against `app/Models/Concerns/BelongsToTenant.php`.
