WIN WORLD — Maintenance / CMMS-lite (MTTR · MTBF · PM compliance)

WHAT THIS ADDS
  * A Maintenance screen at /panel/maintenance: breakdown log + preventive (PM) work orders.
    Report a breakdown, Start repair, Complete (logs downtime) -> the system computes:
      - MTTR  (mean time to repair  = how fast you fix)
      - MTBF  (mean time between failures = operating hours / breakdowns)
      - PM compliance (preventive jobs done on/before due)
    Plus a worst-machine-by-downtime board.
  * A "Response" KPI strip now sits on the OEE Dashboard right under the OEE tiles — so the
    diagnosis (OEE) and the response (MTTR/MTBF/PM) are on one screen, the way world-class plants run it.
  * New "Maintenance" item in the Win World hub.

FILES IN THIS UPLOAD (exact repo paths):
  NEW      app/Services/Winworld/Maintenance.php
  NEW      app/Models/WwMaintOrder.php
  NEW      app/Http/Controllers/Panel/WinworldMaintController.php
  NEW      resources/panel/maintenance.html
  NEW      database/migrations/2026_06_18_100009_create_ww_maint_orders.php
  NEW      database/seeders/WinworldMaintDemoSeeder.php
  NEW      qa/ww_maintenance.php                       (dev test, optional)
  REPLACE  app/Http/Controllers/Panel/WinworldDashboardController.php
  REPLACE  resources/panel/dashboard.html
  REPLACE  resources/panel/winworld-hub.html

HOW TO UPLOAD ON GITHUB
  1. Unzip.
  2. Repo -> Add file -> Upload files.
  3. Drag the "app", "database", "resources", and "qa" folders in (paths preserved).
  4. Commit.

ONE MANUAL EDIT (routes/web.php — not in this zip, to avoid overwriting your core routes file):
  Find this line (in the WINWORLD DASHBOARD ROUTES group):
      Route::get('papi/ww-dashboard', [\App\Http\Controllers\Panel\WinworldDashboardController::class, 'data']);
  Add these 4 lines right AFTER it (inside the same group { ... }):
      Route::get('/panel/maintenance', [\App\Http\Controllers\Panel\WinworldMaintController::class, 'maintPage']);
      Route::get('papi/ww-maint', [\App\Http\Controllers\Panel\WinworldMaintController::class, 'data']);
      Route::get('papi/ww-maint-save', [\App\Http\Controllers\Panel\WinworldMaintController::class, 'save']);
      Route::get('papi/ww-maint-action', [\App\Http\Controllers\Panel\WinworldMaintController::class, 'action']);

THEN
  * EasyPanel -> Deploy. The new migration (ww_maint_orders table) runs automatically.
  * Load demo maintenance data (run once in the console; safe on your already-seeded tenant):
        php artisan db:seed --class=WinworldMaintDemoSeeder
  * Open the hub -> Maintenance. The Dashboard now shows the Response strip too.

QA: php qa/ww_maintenance.php -> 16. Full module sweep: 237 assertions across 15 suites.
