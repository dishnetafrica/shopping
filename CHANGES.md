# CloudBSS — full session snapshot (GitHub-ready)

Extract at the repo root (paths already match) and commit. This is the **current** state of every file
touched this session — it supersedes the earlier `cloudbss-changes` bundle and is safe to extract over
the repo whatever you've already pushed. PHP lint-clean; JS `node --check`-clean.

## Features included
- **Handover nudge** — short "hi" pauses the bot briefly, a real reply takes over fully.
- **Quotation detail UI** + **PDF streaming fix** (no `/storage` symlink; regenerates if wiped).
- **Today assistant** (task list) + **shared JS layer** (`resources/panel/shared/*`).
- **Vertical nudge fix** — manufacturers no longer get restaurant prompts; staleness watchdog disabled.
- **Lead follow-up** (`bot:lead-followup`) + its **Settings UI card** (enable/delay/link/warm-lead).
- **Inventory count screen** (`/panel/inventory-count`) — record counts → bot gives real numbers.

## Files
- app/Support/AgentNudge.php
- app/Jobs/ProcessIncomingMessage.php
- app/Http/Controllers/Panel/PanelApiController.php
- app/Http/Controllers/Panel/SellerPanelController.php
- app/Console/Commands/{OkeNudgeCommand,OrderStalenessWatchdogCommand,LeadFollowUpCommand}.php
- routes/web.php · routes/console.php
- resources/panel/seller.html · today.html · inventory-count.html
- resources/panel/shared/{api,utils,modal}.js
- tests/Unit/Support/AgentNudgeTest.php

## Post-deploy
- No migrations added.
- Scheduler should have `orders:pending-digest` + `bot:lead-followup`, NOT `orders:staleness-watchdog`.
- `php artisan storage:link` once if other `/storage` assets need it (the quote PDF no longer does).
- Lead follow-up stays OFF until a tenant's `reengage_followup.enabled = true` (Settings → Lead follow-up).
