<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Customer 360 — client-side page that aggregates a customer's orders + payments
 * (from /papi/orders and /papi/payments) into one view: balance owed, advances,
 * order history, payment history, and an activity timeline. Access = trading panel.
 */
class CustomerController extends Controller
{
    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        // Available when the order system is on, OR to an owner/manager/accountant regardless — so the
        // v3 Customers screen (which embeds this page) works on tenants without order_system_enabled set.
        $allowed = (bool) $u->tenant->setting('order_system_enabled', false)
            || $u->isOwnerLike() || in_array((string) $u->role, ['manager', 'accountant'], true);
        if (! $allowed) abort(404);
        $path = resource_path('panel/customers360.html');
        if (! is_file($path)) abort(500, 'Customers asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }
}
