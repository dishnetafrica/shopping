# Win World MES — Phase 2, Batch 2: Notification engine (act-now alerts)

**Requires Phase 1 + the dashboard batch.** Turns the dashboard from "look back" into "act now".

## What fires (loss-prevention alerts)
| Event | When | Goes to (role) | Prevents |
|---|---|---|---|
| ⛔ STOP | a production entry logs a stop reason | Material→**stores**, Breakdown→**maintenance**, Power→**production** | downtime |
| ⚠ QC REJECT | entry qc_result = reject | **production** | scrap / rework |
| 🐢 SLOW RUN | efficiency < threshold (default 70%, not stopped) | **production** | speed loss |
| 📦 DELAY RISK | planned finish date > required date | **sales** | late delivery |

Delivery happens over the tenant's Evolution WhatsApp, reusing `NotifyOwner` + role-aware
`leadRecipients($role)`. Configure who gets what by adding `lead_recipients` entries with roles
`production` / `stores` / `maintenance` / `sales` (falls back to owner alert numbers so nothing is dropped).

## Pieces
- **Alerts** `app/Services/Winworld/Alerts.php` — pure rules engine (18 assertions in `qa/ww_alerts.php`).
- **WinworldNotifier** — dispatches alerts + the scheduled delay sweep (dedupes via `delay_alerted_at`, max 1/24h).
- **ww:scan** command — sweeps active indents for delivery delay risk.
- Migration adds `required_date` + `delay_alerted_at` to `ww_indents`.
- Wired into **Production Entry save** (event-driven) and the **Indent form** now has a Required date.

## Changed module files in this zip (replace your Batch 1–3 copies)
`app/Models/WwIndent.php`, `app/Http/Controllers/Panel/WinworldApiController.php`,
`app/Http/Controllers/Panel/WinworldIndentController.php`, `resources/panel/indent.html`.

## Install
1. Deploy files. 2. `php artisan migrate --force`. 3. Append the line from `console-schedule-append.txt` to `routes/console.php`. 4. `php artisan config:cache`.
5. Settings per tenant: `ww_alerts_enabled` (default on), `ww_slow_pct` (default 70), and `lead_recipients` roles.

## Tuning / off-switch
- Set `ww_alerts_enabled=false` to mute all Win World alerts.
- Raise/lower `ww_slow_pct` to tune the slow-run sensitivity.

## QA
`php qa/ww_alerts.php` → 18. Full module sweep: **138 assertions green across 9 suites.**

## Still pending in Phase 2
- **OIF PDF + digital QC sign-offs** (the controlled-document piece).
- Real **daily report** to validate the output-rate (Q2) so efficiency %/slow-run thresholds are exact.
