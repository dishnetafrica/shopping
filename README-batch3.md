# Win World MES — Phase 1, Batch 3 (Indent + Planning) — closes the loop

**Requires Batches 1 & 2.** This completes Phase 1: create indent → plan → record (Batch 2) → OEE.

## What's in this batch
- **Order Indent screen** `/panel/indents` (`resources/panel/indent.html` + `WinworldIndentController`)
  - Header (customer, item → product, qty, mixing qty, priority, sample, SDH remarks).
  - **Process routing** toggles → show only the relevant spec sections.
  - **Blending recipe** lines (material + % per Ext-A/B/C) with **live kg preview + per-column balance check (✓ / ⚠)**.
  - Extruder / Printing / Cutting spec sections (faithful to OIF WIL/MKT/OIF/001).
  - **Clone** ("same as last order") — pulls a previous indent's specs + blend lines into a fresh draft; new number assigned on save.
  - Server computes **order_kg** (item gram/pcs) and the blend quantities (engine), and assigns the controlled **indent number** (`seq-DDMMYY`).
- **Planning board** `/panel/planning` — every open indent, per active process: pick machine + speed + start (+ optional manual output). On save the engine fills **final output → required hours → shift-aware planned end**, and advances the indent to **Planned**. These rows are what the Production Entry screen runs against.
- **IndentBuilder** pure helper (clone mapping + indent-number format) + `qa/ww_indent_builder.php` (16 assertions).

## Install
1. Deploy files (paths repo-relative).
2. Append routes from `winworld-routes-append-batch3.txt`.
3. `php artisan config:cache`.
4. Open **/panel/indents** → create or clone an indent → **/panel/planning** → plan it → **/panel/production** → record. Done.

## QA
`php qa/ww_indent_builder.php` → 16. Full module sweep: **96 assertions green across 7 suites.**

## Honest notes
- Panel pages tested via `node --check` (JS) + `php -l`; not boot-tested (no vendor in build env). Smoke-test on staging.
- OIF **PDF + digital QC sign-offs** and the **dashboards + notification engine** are Phase 2.
- Output rate still manual-first until a real daily report validates the auto formula (Q2).
