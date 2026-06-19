PANEL 500 FIX — missing Tenant import

THE BUG
  PanelApiController::tenantFeatures(Tenant $t) used a bare "Tenant" type-hint, but the file
  never imported App\Models\Tenant. PHP resolved it to App\Http\Controllers\Panel\Tenant
  (which doesn't exist), so the /papi/settings endpoint threw a TypeError 500 and the whole
  seller panel got stuck on "Connecting..." / "0 categories".

THE FIX
  Added:  use App\Models\Tenant;
  (Nothing else changed. This is unrelated to the category re-mapping, which is fine.)

FILE
  REPLACE  app/Http/Controllers/Panel/PanelApiController.php

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" folder -> Commit -> EasyPanel Deploy.
  After deploy, reload mycloudbss.com/panel (fresh tab) — the 500 is gone and your
  21 categories show.
