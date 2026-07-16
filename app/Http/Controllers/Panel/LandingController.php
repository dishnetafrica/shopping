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
            if (in_array((string) $u->role, ['purchaser', 'field'], true)) {
                return redirect('/panel/app');
            }
            // v3 is now the primary working screen for owner/manager/sales. The classic panel
            // stays fully reachable with ?classic=1 (the account menu's "Open classic panel" link
            // uses it), so this is a soft switch — reversible by removing this redirect.
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
        if ($u && $u->tenant_id
            && (bool) $u->tenant->setting('order_system_enabled', false)
            && in_array((string) $u->role, ['purchaser', 'field'], true)) {
            return redirect('/panel/app');
        }
        return app(\App\Http\Controllers\Panel\SellerPanelController::class)->mobile($request);
    }
}
