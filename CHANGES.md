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

## This update
- Client guide (`/panel/guide`) is now **vertical-aware** — manufacturer / grocery / snacks / restaurant
  tenants see copy tailored to them (off-vertical sections hidden). It describes *what* features do, not
  *how* they work — safe to show clients.
- Added `docs/INTERNAL/PLATFORM-LOGIC.md` — the real engine logic for OUR team only. No route serves
  `docs/`, so clients cannot reach it. Do not link it from the panel.
