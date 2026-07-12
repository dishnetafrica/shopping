<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Money\Fx;
use Illuminate\Http\Request;

/**
 * GET /papi/fx — the tenant's canonical exchange rate (Roadmap E3.3, Constitution R8).
 *
 * Returns the effective rate from the single FX service (App\Services\Money\Fx), plus the `enabled`
 * flag (tenant setting fx_v2). The panel always reads this to LOG any divergence from its current
 * client rate (parallel-run reconciliation), but only switches its display onto it when fx_v2 is on.
 * Read-only.
 */
class FxController extends Controller
{
    public function rates(Request $request)
    {
        $u = $request->user();
        if (! $u) {
            return response()->json(['ok' => false], 401);
        }
        $t    = $u->tenant;
        $fx   = Fx::forTenant($t);
        $date = date('Y-m-d');

        return response()->json([
            'ok'          => true,
            'enabled'     => $t ? (bool) $t->setting('fx_v2', false) : false,
            'base'        => Fx::BASE,
            'ugx_per_usd' => $fx->ratePerUsd('UGX', $date),
            'ssp_per_usd' => $fx->ratePerUsd('SSP', $date),
            'date'        => $date,
        ]);
    }
}
