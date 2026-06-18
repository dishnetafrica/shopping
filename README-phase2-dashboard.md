# Win World MES — Phase 2, Batch 1: OEE Dashboard

**Requires Phase 1 (Batches 1–3).** Turns the captured production data into the efficiency picture.

## What's in it
- **Analytics engine** `app/Services/Winworld/Analytics.php` (pure, tested): downtime Pareto, per-machine OEE
  (time-weighted performance; availability falls back to run-hours where no plan exists), machine board
  (next-available + booked-7d), headline summary (produced/order kg, first-pass yield, avg efficiency, status counts).
- **Dashboard** `/panel/dashboard` (`dashboard.html` + `WinworldDashboardController`):
  - KPI strip: Avg OEE, Produced kg, First-pass yield, Avg efficiency, Active orders.
  - **Machine OEE — worst first** (so the biggest loss is top of the page), with A/P/Q bars.
  - **Downtime by reason** (Pareto — attack the top bar).
  - **Machine board** — next available + booked hours next 7d vs 84h capacity (utilisation).
  - 7d / 30d / All range toggle.
- `qa/ww_analytics.php` — 24 assertions.

## Install
1. Deploy files. 2. Append routes from `winworld-routes-append-dashboard.txt`. 3. `php artisan config:cache`.
4. Open **/panel/dashboard** (Win World staff).

## QA
`php qa/ww_analytics.php` → 24. Full module sweep: **120 assertions green across 8 suites.**

## Honest notes
- Availability is approximate on machines without planned hours (marked with `*`); it becomes exact once Planning is used and, later, when real downtime duration is captured.
- Still pending (next Phase-2 batches): **WhatsApp notification engine** (idle/stop/slow/QC-reject/delay-risk alerts), **OIF PDF + digital QC sign-offs**.
- Trustworthy numbers still depend on validating the output-rate formula against a real daily report (Q2).
