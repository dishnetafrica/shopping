<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Post-login landing router for /panel.
 * Purchasing/field staff (Jayesh, Asish) land on the tap-tile app home so they never
 * see URLs or the full dashboard. Everyone else gets the existing seller dashboard,
 * rendered by the original SellerPanelController::show() exactly as before.
 */
class LandingController extends Controller
{
    public function panel(Request $request)
    {
        $u = $request->user();
        if ($u && $u->tenant_id && (bool) $u->tenant->setting('order_system_enabled', false)) {
            // Phase C (V3-DEFAULT-MIGRATION-PLAN): purchaser/field land on /v3 like everyone
            // else. Runtime-reversible WITHOUT a deploy: `php artisan v3:staff {tenant} --off`
            // (tenant setting v3_all_roles=false) restores their old tap-tile home instantly.
            if (in_array((string) $u->role, ['purchaser', 'field'], true)
                && ! (bool) $u->tenant->setting('v3_all_roles', true)) {
                return redirect('/panel/app');
            }
            // The classic panel stays fully reachable with ?classic=1 (the account menu's
            // "Open classic panel" link uses it) until Phase D retires it.
            if (! $request->boolean('classic')) {
                return redirect('/v3');
            }
        }
        // Classic dashboard: ?classic=1, or tenants without the order system enabled.
        return app(\App\Http\Controllers\Panel\SellerPanelController::class)->show($request);
    }

    /** Same guard for the mobile order panel (/panel/m) — mobile logins often land here. */
    public function mobile(Request $request)
    {
        $u = $request->user();
        if ($u && $u->tenant_id && (bool) $u->tenant->setting('order_system_enabled', false)) {
            // Purchaser/field who kept the tap-tile home go to /panel/app; EVERYONE ELSE — owners
            // included — gets the mobile-first V3 shell. (Owners used to fall through to the legacy
            // mobile dashboard here, so the phone never reached /v3. This mirrors panel() above.)
            if (in_array((string) $u->role, ['purchaser', 'field'], true)
                && ! (bool) $u->tenant->setting('v3_all_roles', true)) {
                return redirect('/panel/app');
            }
            if (! $request->boolean('classic')) {
                return redirect('/v3');
            }
        }
        return app(\App\Http\Controllers\Panel\SellerPanelController::class)->mobile($request);
    }
}
