# Win World MES — Phase 1, Batch 2 (seeders + Filament masters + Production Entry)

**Requires Batch 1** (engines + migrations + models) deployed first.

## What's in this batch
- **Seeder** `database/seeders/WinworldSeeder.php` — 22 machines, sample items & materials; flips on the
  `module_winworld` tenant flag. Idempotent. Resolves the Win World tenant by slug/name.
- **Filament masters** (App panel, nav group "Win World") — Items (auto gram/pcs), Machines, Materials, Customers.
  Gated by `Concerns/WinworldModule` so they appear ONLY for the Win World tenant.
- **Production Entry (mobile)** — `resources/panel/production.html` + `WinworldPanelController` +
  `WinworldApiController` (papi: `ww-machines`, `ww-jobs`, `ww-entry-save`). Operator picks a machine → open job →
  start/stop, pcs/kg, scrap, stop reason, QC → Save. Server derives actual hours, output, efficiency and OEE via the
  proven engines, and rolls up planning + indent status.
- **StatusFlow** pure helper + `qa/ww_statusflow.php` (15 assertions).

## Install
1. Deploy files (paths are repo-relative).
2. Append the routes from `winworld-routes-append.txt` to `routes/web.php`.
3. `php artisan migrate --force` (Batch 1 migrations) — already additive on deploy.
4. Create the Win World **tenant** (slug `winworld`), then:
   `php artisan db:seed --class=Database\Seeders\WinworldSeeder`
5. `php artisan config:cache` (and `optimize:clear` if Filament nav doesn't refresh).
6. Open **/panel/production** on a phone (logged in as a Win World staff user).
   Masters live in the Filament **/app** panel under "Win World".

## QA
`php qa/ww_statusflow.php` → 15 passed. Full module sweep: 80 assertions green across 6 suites.

## Notes / assumptions still open
- Filament resources are pattern-matched to your CategoryResource (vendor not present in build env to boot-test) — `php -l` clean.
- Jobs appear on the Production Entry screen once an indent has **Planning rows** (next batch builds the Indent + Planning screens; for now plan rows can be seeded or created via tinker to exercise the flow).
- Output rate still manual-first until Q2 (real daily report) is validated.
