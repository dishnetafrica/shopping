<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cash Position + Order Costs — the "how much USD / UGX / SSP do I have?" screen, styled like the
 * DishNet cashbook (green USD / blue SSP / amber UGX hero cards). Reads order_costs, grouped by
 * currency and holder. Costs REDUCE cash; a funding entry (stage=funding) ADDS cash.
 */
class CashPositionController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/cash_position.html');
        if (! is_file($path)) abort(500, 'Cash position asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/cash-position — per-currency balances + per-holder breakdown. */
    public function data(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false,'error'=>'no_tenant'],401);
        $tid = $t->id;

        // Per currency: funding IN minus costs OUT (native), and USD-equivalent.
        $rows = OrderCost::where('tenant_id',$tid)
            ->where('status','approved')
            ->select('currency','holder','stage',
                DB::raw('SUM(native_amount) as native'), DB::raw('SUM(usd_amount) as usd'))
            ->groupBy('currency','holder','stage')->get();

        $cur = [];   // currency => ['native'=>, 'usd'=>, 'holders'=>[holder=>native]]
        foreach ($rows as $r) {
            $sign = $r->stage === 'funding' ? 1 : -1;   // costs reduce cash, funding adds
            $c = $r->currency;
            $cur[$c]['native'] = ($cur[$c]['native'] ?? 0) + $sign * (float)$r->native;
            $cur[$c]['usd']    = ($cur[$c]['usd'] ?? 0) + $sign * (float)$r->usd;
            if ($r->holder) {
                $cur[$c]['holders'][$r->holder] = ($cur[$c]['holders'][$r->holder] ?? 0) + $sign * (float)$r->native;
            }
        }

        $out = [];
        foreach (['USD','UGX','SSP'] as $c) {
            $out[$c] = [
                'native'  => round($cur[$c]['native'] ?? 0, 2),
                'usd_equiv' => round($cur[$c]['usd'] ?? 0, 2),
                'holders' => array_map(fn($h,$v)=>['holder'=>$h,'native'=>round($v,2)],
                              array_keys($cur[$c]['holders'] ?? []), array_values($cur[$c]['holders'] ?? [])),
            ];
        }
        $combinedUsd = round(($out['USD']['usd_equiv']) + ($out['UGX']['usd_equiv']) + ($out['SSP']['usd_equiv']), 2);

        return response()->json([
            'ok'=>true, 'base'=>'USD', 'combined_usd'=>$combinedUsd,
            'currencies'=>$out, 'as_of'=>date('d M Y · H:i'),
        ]);
    }
}
