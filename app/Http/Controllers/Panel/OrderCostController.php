<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\{Order, OrderCost};
use Illuminate\Http\Request;

/** Book a cost (purchase/freight/delivery) or funding against an order, in native currency. */
class OrderCostController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/book_cost.html');
        if (! is_file($path)) abort(500, 'Book-cost asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    public function ordersPage(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/orders_costs.html');
        if (! is_file($path)) abort(500, 'Orders asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/order-costs/orders — open orders to pick from. */
    public function orders(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $orders = Order::where('tenant_id',$t->id)
            ->orderByDesc('id')->limit(50)
            ->get(['id','order_no','customer_name','total','location']);
        return response()->json(['ok'=>true,'orders'=>$orders,
            'default_rates'=>['UGX'=>(float)$t->setting('rate_ugx_per_usd',3700),
                              'SSP'=>(float)$t->setting('rate_ssp_per_usd',5180),'USD'=>1]]);
    }

    /** POST /papi/order-costs — book one cost line. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $native = (float)$request->input('native_amount',0);
        $rate = (float)$request->input('rate_to_usd', $cur==='USD'?1:0);
        if ($native<=0 || $rate<=0) return response()->json(['ok'=>false,'error'=>'bad_amount']);

        $order = null;
        if ($request->filled('order_id')) $order = Order::where('tenant_id',$t->id)->find($request->input('order_id'));

        $path = null;
        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store("receipts/t{$t->id}", 'public');
        }

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'order_id'=>$order?->id, 'order_no'=>$order?->order_no,
            'stage'=>(string)$request->input('stage','purchase'),
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>(string)$request->input('holder',''),
            'receipt_path'=>$path, 'note'=>(string)$request->input('note',''),
            'created_by'=>$request->user()->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }

    /** POST /papi/orders/quick — create a Juba order with its customer-pays currency. */
    public function quickOrder(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $o = new Order();
        $o->tenant_id     = $t->id;
        $o->status        = 'New';
        $o->channel       = 'pos';
        $o->customer_name = (string)$request->input('customer','');
        $o->customer_phone= preg_replace('/[^0-9]/','',(string)$request->input('phone',''));
        $o->location      = (string)$request->input('location','Juba');
        $o->total         = (float)$request->input('total',0);      // revenue in the sell currency
        $o->items_text    = (string)$request->input('items','');
        $o->save();   // OrderObserver assigns order_no
        // record the sell currency as an order note-setting (kept on the order's payment field)
        $sellCur = strtoupper((string)$request->input('sell_currency','USD'));
        $o->payment = 'sell:'.$sellCur;
        $o->save();
        return response()->json(['ok'=>true,'id'=>$o->id,'order_no'=>$o->order_no,'sell_currency'=>$sellCur]);
    }

    /** GET /papi/orders/{id}/costs — per-order costs + landed cost + margin (all in USD base). */
    public function orderDetail(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $o = Order::where('tenant_id',$t->id)->find($id);
        if (! $o) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $costs = OrderCost::where('tenant_id',$t->id)->where('order_id',$id)
            ->where('stage','!=','funding')->orderBy('id')->get();
        $landedUsd = 0.0; $lines = [];
        foreach ($costs as $c) {
            $landedUsd += (float)$c->usd_amount;
            $lines[] = ['stage'=>$c->stage,'currency'=>$c->currency,
                'native'=>(float)$c->native_amount,'rate'=>(float)$c->rate_to_usd,
                'usd'=>(float)$c->usd_amount,'holder'=>$c->holder,'note'=>$c->note,
                'receipt'=>$c->receipt_path ? '/storage/'.$c->receipt_path : null];
        }
        $sellCur = 'USD';
        if (strpos((string)$o->payment,'sell:')===0) $sellCur = substr($o->payment,5);
        // revenue: order total is in sell currency; convert to USD for margin using tenant rate
        $rate = $sellCur==='USD' ? 1 : (float)$t->setting('rate_'.strtolower($sellCur).'_per_usd', $sellCur==='UGX'?3700:5180);
        $revenueUsd = $rate>0 ? round((float)$o->total / $rate, 2) : (float)$o->total;
        return response()->json([
            'ok'=>true,
            'order'=>['order_no'=>$o->order_no,'customer'=>$o->customer_name,'location'=>$o->location,
                'total'=>(float)$o->total,'sell_currency'=>$sellCur,'revenue_usd'=>$revenueUsd],
            'costs'=>$lines,
            'landed_usd'=>round($landedUsd,2),
            'margin_usd'=>round($revenueUsd - $landedUsd,2),
            'margin_pct'=>$revenueUsd>0 ? round(($revenueUsd-$landedUsd)/$revenueUsd*100,1) : 0,
        ]);
    }
}
