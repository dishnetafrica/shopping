# CloudBSS — changes bundle (this session)

Extract at the repo root (paths already match) and commit. Every file is the latest version with all
of this session's edits applied. PHP lint-clean; JS `node --check`-clean.

## New files
- `app/Support/AgentNudge.php` — classifies a human agent's short "hi" (nudge) vs a real takeover.
- `app/Console/Commands/LeadFollowUpCommand.php` — `bot:lead-followup`: one catalogue follow-up to a warm lead who asked then went quiet (opt-in, once, guarded).
- `resources/panel/today.html` — Today / Needs-attention assistant (task list).
- `resources/panel/shared/api.js`, `utils.js`, `modal.js` — shared front-end layer (namespaced `window.CB`).
- `tests/Unit/Support/AgentNudgeTest.php`.

## Modified files
- `app/Jobs/ProcessIncomingMessage.php` — handover is nudge-aware (short "hi" → brief pause, real reply → full takeover window).
- `app/Http/Controllers/Panel/PanelApiController.php` — `today()` task aggregator; `quotationPdf()` streams the PDF (no /storage symlink, regenerates if wiped); quotation PDF URLs switched to the streaming route.
- `app/Http/Controllers/Panel/SellerPanelController.php` — serves `/panel/today` and `/panel/shared/{file}.js`.
- `routes/web.php` — routes: `papi/today`, `papi/quotation-pdf`, `panel/today`, `panel/shared/{file}`.
- `routes/console.php` — schedules `bot:lead-followup` (every 5 min, 08:00–20:00).
- `app/Console/Commands/OkeNudgeCommand.php` — vertical-aware daily nudge (manufacturers skipped; grocery/snacks get their own prompt).
- `app/Console/Commands/OrderStalenessWatchdogCommand.php` — neutralised (no-op); replaced by `orders:pending-digest`.
- `resources/panel/seller.html` — quotation detail view (View/edit/save/resend/convert/archive) + `/panel#section` deep-link handler.

## Post-deploy reminders
- Run migrations only if your branch is missing earlier ones (this bundle adds none).
- Server scheduler should have `orders:pending-digest` and `bot:lead-followup`, and **not** `orders:staleness-watchdog`.
- If product images / PDFs rely on `/storage`, run `php artisan storage:link` once (the quote PDF no longer needs it).
- `bot:lead-followup` is OFF until you set a tenant's `reengage_followup.enabled = true`.
