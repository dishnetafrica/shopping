<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TradeOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Today's buy list — totals the still-unbought line items across every trading order. */
class BuyListController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/buy_list.html');
        if (! is_file($path)) abort(500, 'Buy list asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/buy-list — items still needing purchase, aggregated by name. */
    public function data(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);

        $rows = TradeOrderItem::where('tenant_id',$t->id)
            ->whereColumn('bought_qty','<','qty')
            ->orderBy('name')->get();

        $agg = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string)$r->name));
            if ($key === '') continue;
            if (! isset($agg[$key])) {
                $agg[$key] = ['name'=>$r->name,'unit'=>$r->unit,'remaining'=>0.0,'ordered'=>0.0,'orders'=>[],'ids'=>[]];
            }
            $remaining = max(0.0, (float)$r->qty - (float)$r->bought_qty);
            $agg[$key]['remaining'] += $remaining;
            $agg[$key]['ordered']   += (float)$r->qty;
            $agg[$key]['ids'][]      = $r->id;
            if ($r->order_no) $agg[$key]['orders'][$r->order_no] = true;
            if (! $agg[$key]['unit'] && $r->unit) $agg[$key]['unit'] = $r->unit;
        }

        $items = array_values(array_map(function ($a) {
            return [
                'name'=>$a['name'],
                'unit'=>$a['unit'],
                'remaining'=>round($a['remaining'],2),
                'ordered'=>round($a['ordered'],2),
                'orders'=>array_keys($a['orders']),
                'ids'=>$a['ids'],
            ];
        }, $agg));

        return response()->json(['ok'=>true,'items'=>$items,'count'=>count($items)]);
    }

    /** POST /papi/buy-list/mark — mark given line ids bought (bought_qty = qty) or undo. */
    public function mark(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);

        $ids  = (array) $request->input('ids', []);
        $ids  = array_values(array_filter(array_map('intval', $ids)));
        if (! count($ids)) return response()->json(['ok'=>false,'error'=>'no_ids'],422);
        $undo = (bool) $request->input('undo', false);

        $q = TradeOrderItem::where('tenant_id',$t->id)->whereIn('id',$ids);
        $q->update(['bought_qty' => $undo ? 0 : DB::raw('qty')]);

        return response()->json(['ok'=>true,'updated'=>count($ids),'undo'=>$undo]);
    }
}
