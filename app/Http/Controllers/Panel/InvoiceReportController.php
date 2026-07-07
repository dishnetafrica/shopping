<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Monthly invoiced revenue report. Delivered orders = invoiced. Owner/manager only. */
class InvoiceReportController extends Controller
{
    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        if (! (method_exists($u,'isOwnerLike') ? $u->isOwnerLike() : false)) abort(403);
        $path = resource_path('panel/invoiced.html');
        if (! is_file($path)) abort(500, 'Invoiced report asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }
}
