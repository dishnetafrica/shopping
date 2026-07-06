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
        if ($u && $u->tenant_id
            && (bool) $u->tenant->setting('order_system_enabled', false)
            && in_array((string) $u->role, ['purchaser', 'field'], true)) {
            return redirect('/panel/app');
        }
        // Unchanged for owner/manager/sales: run the real dashboard controller.
        return app(\App\Http\Controllers\Panel\SellerPanelController::class)->show($request);
    }
}
