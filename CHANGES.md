# CloudBSS — full session snapshot (GitHub-ready)

Extract at the repo root and commit. Current state of every file touched this session; safe to extract
over the repo whatever you've pushed. PHP lint-clean; JS node --check-clean. No migrations.

Includes: handover nudge · quotation detail UI + PDF streaming fix · Today assistant + shared JS layer ·
vertical nudge fix (watchdog disabled) · lead follow-up + Settings card · inventory count screen ·
**in-panel seller guide (`/panel/guide`)**, linked from Today and the stock screen.

Post-deploy: scheduler has `orders:pending-digest` + `bot:lead-followup`, NOT `orders:staleness-watchdog`;
`php artisan storage:link` once if other /storage assets need it; lead follow-up stays OFF until a
tenant's `reengage_followup.enabled = true` (Settings → Lead follow-up).

New page route added: `/panel/guide`. Optional: add a "Guide" link in the main panel nav (not done here
to avoid touching the big nav block — say the word and I'll add it).
