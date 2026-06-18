# Win World MES — COMPLETE bundle (one-pass deploy)

The entire converter MES for **Win World Impex / GalaxyPack**, built as a module on the CloudBSS
(Laravel 11 + Filament 3 + Postgres) stack. Everything in one drop. All files are `ww_` tables /
`Ww*` models / `App\Services\Winworld\*` engines / `Winworld*Controller`, gated to the Win World
tenant by the `module_winworld` setting (the seeder turns it on).

> **First deploy = STAGING.** These files are verified by `php -l`, framework-free pure-logic tests
> (`qa/*.php`, 199 assertions green), and `node --check` on page JS — but have **not** been booted
> against a live Laravel/DB here. Stand up on staging, smoke-test, then promote.

## Deploy sequence
1. **Copy files** — unzip into the repo root, preserving paths (`app/…`, `database/…`, `resources/…`, `qa/…`).
2. **Routes** — append everything in `01-routes-append-to-web.php.txt` to the END of `routes/web.php`.
3. **Scheduler** — append the line in `02-console-append-to-console.php.txt` to `routes/console.php`.
4. **Build** — `composer install` · `php artisan migrate --force` · `php artisan config:cache`.
5. **Tenant + seed** — create a tenant with slug `winworld` (or `galaxypack`), then
   `php artisan db:seed --class=WinworldSeeder` (flips `module_winworld` on; loads the 22 machines + sample items/materials).
6. **Settings** (per tenant): `owner_alert_phone`; `lead_recipients` with roles
   `production` / `stores` / `maintenance` / `sales` / `sales_coord` / `sm` / `md`;
   `ww_alerts_enabled` (default on); `ww_slow_pct` (default 70). Connect the WhatsApp/Evolution instance.
7. **Use it** — log in at `/app`, open **`/panel/winworld`** and bookmark it. That's the home base.

## What's inside
**Pure engines** (`app/Services/Winworld/`, all unit-tested): Formula, ShiftCalendar, Blending, Oee,
StatusFlow, IndentBuilder, Analytics, Alerts, QcStatus, SlaClock, SalesFlow, ExceptionFlow, plus the
WinworldNotifier dispatcher.

**Screens** (open from the `/panel/winworld` launcher):
- Sales orders (`/panel/sales`) — SLA pipeline, SM/MD approvals, credit/ageing MD gate.
- Exceptions (`/panel/exceptions`) — complaints, goods returns (10M MD gate), credit/debit notes.
- Order indents (`/panel/indents`) — controlled indent + clone-last; "Open / print OIF" link.
- OIF (`/panel/oif?id=`) — print-perfect WIL/MKT/OIF/001 + digital QC sign-offs.
- Planning (`/panel/planning`) — machine + speed → auto hours & finish.
- Production floor (`/panel/production`) — mobile run entry; fires loss-prevention alerts.
- OEE Dashboard (`/panel/dashboard`) — worst machine first, downtime Pareto, machine board.
- Masters — Filament CRUD at `/app` (Items, Machines, Materials, Customers).
- Staff training (`/panel/training`) — per-role how-to.

**Background:** `ww:scan` (every 30 min) sweeps delivery delay-risk + sales SLA breaches and sends
WhatsApp alerts to the right role, escalating to SM when 2h+ overdue.

## Migrations (run in this order — they are additive & guarded)
100001 masters · 100002 indents · 100003 planning/production · 100004 indent required_date ·
100005 sales · 100006 sales sla_alerted · 100007 exceptions.

## QA
From the repo: `for f in qa/ww_*.php; do php "$f"; done` → **199 assertions across 12 suites.**

## Known follow-ups (not blockers)
- Validate the output-rate formula against a real **daily production report** (design Q2) to make the
  efficiency % / slow-run threshold exact rather than directional.
- Optional: server-side OIF PDF (`barryvdh/laravel-dompdf`) to auto-attach to WhatsApp/email.
- Optional: true indent-bridge prefill from a sales order; WhatsApp-group auto-posts.
