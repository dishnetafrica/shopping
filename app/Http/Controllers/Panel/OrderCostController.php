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
}
