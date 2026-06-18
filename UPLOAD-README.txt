WIN WORLD — Material Yield + Floor Scoreboard + Changeover loss

WHAT THIS ADDS
  * Material yield  : resin-in vs good-kg-out, with regrind tracking (the converter money metric).
                      New optional "Resin in kg" + "Regrind kg" fields on the Production (floor) screen.
                      Shows as a new tile on the OEE Dashboard. If operators leave resin blank,
                      yield falls back to produced/(produced+scrap) so it still shows a number.
  * Floor scoreboard: a big, dark, auto-refreshing TV view at /panel/scoreboard — live OEE per machine
                      (worst first), Availability/Speed/Quality bars, material yield, FPY, top downtime.
                      Refreshes every 30s. New "Floor scoreboard" item in the Win World hub.
  * Changeover loss : total changeover hours now shown on the dashboard + scoreboard.

FILES IN THIS UPLOAD (exact repo paths):
  NEW      app/Services/Winworld/MaterialYield.php
  NEW      resources/panel/scoreboard.html
  NEW      database/migrations/2026_06_18_100008_add_yield_to_ww_production.php
  NEW      qa/ww_material_yield.php                       (dev test, optional)
  REPLACE  app/Services/Winworld/Analytics.php
  REPLACE  app/Http/Controllers/Panel/WinworldDashboardController.php
  REPLACE  app/Http/Controllers/Panel/WinworldApiController.php
  REPLACE  app/Models/WwProductionEntry.php
  REPLACE  resources/panel/dashboard.html
  REPLACE  resources/panel/production.html
  REPLACE  resources/panel/winworld-hub.html

HOW TO UPLOAD ON GITHUB
  1. Unzip.
  2. Repo -> Add file -> Upload files.
  3. Drag the "app", "database", "resources", and "qa" folders in (paths are preserved).
  4. Commit.

ONE MANUAL EDIT (not in this zip, to avoid overwriting your core routes file):
  In routes/web.php, find this line (around line 216):
      Route::get('/panel/dashboard', [\App\Http\Controllers\Panel\WinworldDashboardController::class, 'dashboardPage']);
  Add this line right AFTER it (same group):
      Route::get('/panel/scoreboard', [\App\Http\Controllers\Panel\WinworldDashboardController::class, 'scoreboardPage']);

THEN
  * EasyPanel -> Deploy. The new migration (input_kg / regrind_kg columns) runs automatically.
  * Open the Win World hub -> "Floor scoreboard" (or go to /panel/scoreboard). Put it full-screen on a TV.
  * The existing demo data already shows a material-yield number (fallback). It sharpens once operators
    start entering "Resin in kg" on the floor screen.

QA: php qa/ww_material_yield.php -> 11. Full module sweep: 221 assertions across 14 suites.
