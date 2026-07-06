<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TradePurchaseTick;
use Illuminate\Http\Request;

/**
 * Today's Buy List — driven by LIVE confirmed orders.
 * The page reads /papi/orders (native orders with items_json + status), keeps the
 * "needs buying" window (Confirmed / Packed / Out for delivery) and subtracts what has
 * been marked bought (trade_purchase_ticks). This controller only stores/returns those
 * bought ticks — it never writes to ShopBot's orders table.
 */
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

    /** GET /papi/buy-list — the bought ticks for this tenant (client subtracts them). */
    public function data(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $ticks = TradePurchaseTick::where('tenant_id',$t->id)
            ->get(['order_no','item_name','qty'])
            ->map(fn($r)=>['order_no'=>$r->order_no,'item_name'=>$r->item_name,'qty'=>(float)$r->qty]);
        return response()->json(['ok'=>true,'ticks'=>$ticks]);
    }

    /** POST /papi/buy-list/mark — mark (or undo) items bought. items = [{order_no,item_name,qty}]. */
    public function mark(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);

        $raw   = $request->input('items');
        $items = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (! is_array($items) || ! count($items)) return response()->json(['ok'=>false,'error'=>'no_items'],422);
        $undo = filter_var($request->input('undo', false), FILTER_VALIDATE_BOOLEAN);

        $n = 0;
        foreach ($items as $it) {
            $on = trim((string)($it['order_no'] ?? ''));
            $nm = trim((string)($it['item_name'] ?? ''));
            $qy = (float)($it['qty'] ?? 0);
            if ($on === '' || $nm === '') continue;
            if ($undo) {
                TradePurchaseTick::where('tenant_id',$t->id)->where('order_no',$on)->where('item_name',$nm)->delete();
            } else {
                TradePurchaseTick::updateOrCreate(
                    ['tenant_id'=>$t->id, 'order_no'=>$on, 'item_name'=>$nm],
                    ['qty'=>$qy, 'created_by'=>$request->user()->name ?? '']
                );
            }
            $n++;
        }
        return response()->json(['ok'=>true,'updated'=>$n,'undo'=>$undo]);
    }
}
