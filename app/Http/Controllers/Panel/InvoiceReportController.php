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
        // E7.3 — this screen converted to USD with a hard-coded rate (3750), so it could disagree with
        // the rest of the app. It is replaced by the SSOT-backed Sales report (GET /papi/reports/sales,
        // which converts via the Fx service). Retire the hard-coded screen: send deep links to Reports.
        // Reversible: restore the file-serving block below to bring the old screen back.
        return redirect('/panel/pnl');
    }
}
