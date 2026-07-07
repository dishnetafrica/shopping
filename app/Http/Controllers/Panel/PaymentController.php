<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\OrderCost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments Received — money FROM customers (advance or on delivery).
 * Each payment: cash goes up (mirrored order_costs stage 'customer_payment') AND a
 * customer_payments record is kept. Advances are UNEARNED (kind='advance', applied=false)
 * so they don't inflate invoiced revenue. Access = anyone with the trading panel.
 */
class PaymentController extends Controller
{
    private function ok(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) return null;
        return $u;
    }

    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/payments.html');
        if (! is_file($path)) abort(500, 'Payments asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/payments — list + totals (advances held = unearned). */
    public function index(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;

        $pays = CustomerPayment::where('tenant_id',$t->id)->orderByDesc('id')->limit(300)->get();
        $advancesHeld = round((float) $pays->where('kind','advance')->where('applied',false)->sum('usd_amount'), 2);
        $totalReceived = round((float) $pays->sum('usd_amount'), 2);

        $list = $pays->map(function ($p) {
            return [
                'id'=>$p->id,'receipt_no'=>$p->receipt_no,'customer'=>$p->customer_name,'phone'=>$p->customer_phone,
                'order_no'=>$p->order_no,'kind'=>$p->kind,'currency'=>$p->currency,'amount'=>(float)$p->amount,
                'usd'=>(float)$p->usd_amount,'method'=>$p->method,'reference'=>$p->reference,'applied'=>(bool)$p->applied,
                'has_receipt'=>(bool)$p->receipt_path,'date'=>optional($p->created_at)->format('Y-m-d H:i'),
            ];
        });
        return response()->json(['ok'=>true,'payments'=>$list,'advances_held'=>$advancesHeld,'total_received'=>$totalReceived]);
    }

    /** POST /papi/payments — record a customer payment; mirror cash-in. */
    public function store(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;

        $customer = trim((string)$request->input('customer_name',''));
        if ($customer === '') return response()->json(['ok'=>false,'error'=>'no_customer'],422);
        $cur    = strtoupper((string)$request->input('currency','USD'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);

        $kind    = in_array((string)$request->input('kind'), ['advance','settlement'], true) ? (string)$request->input('kind') : 'advance';
        $orderNo = trim((string)$request->input('order_no','')) ?: null;
        $method  = in_array((string)$request->input('method'), ['cash','momo','bank','other'], true) ? (string)$request->input('method') : 'cash';
        $ref     = trim((string)$request->input('reference','')) ?: null;
        $phone   = trim((string)$request->input('customer_phone','')) ?: null;

        $path = null;
        if ($request->hasFile('receipt')) $path = $request->file('receipt')->store("receipts/t{$t->id}", 'public');

        $result = DB::transaction(function () use ($t,$u,$customer,$phone,$orderNo,$kind,$cur,$amount,$rate,$usd,$method,$ref,$path) {
            // cash side: money IN
            $cost = OrderCost::create([
                'tenant_id'=>$t->id,'order_id'=>null,'order_no'=>$orderNo,
                'stage'=>'customer_payment','currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,
                'usd_amount'=>$usd,'holder'=>$u->name ?? '','holder_user_id'=>$u->id,
                'pay_status'=>'cash','paid_native'=>$amount,
                'note'=>'Payment from '.$customer.($orderNo?(' ('.$orderNo.')'):''),'status'=>'approved','created_by'=>$u->name ?? '',
            ]);
            $pay = CustomerPayment::create([
                'tenant_id'=>$t->id,'receipt_no'=>'TMP','customer_name'=>$customer,'customer_phone'=>$phone,
                'order_no'=>$orderNo,'kind'=>$kind,'currency'=>$cur,'amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'method'=>$method,'reference'=>$ref,'receipt_path'=>$path,'applied'=>($kind==='settlement'),
                'order_cost_id'=>$cost->id,'holder'=>$u->name ?? '','holder_user_id'=>$u->id,'created_by'=>$u->name ?? '',
            ]);
            $pay->receipt_no = 'RCP-'.str_pad((string)$pay->id, 5, '0', STR_PAD_LEFT);
            $pay->save();
            return ['id'=>$pay->id,'receipt_no'=>$pay->receipt_no];
        });

        return response()->json(['ok'=>true] + $result + ['usd'=>$usd,'currency'=>$cur,'amount'=>$amount]);
    }

    /** GET /papi/payments/{id} — one payment (for the receipt). */
    public function show(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $p = CustomerPayment::where('tenant_id',$u->tenant_id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        return response()->json(['ok'=>true,'payment'=>[
            'id'=>$p->id,'receipt_no'=>$p->receipt_no,'customer'=>$p->customer_name,'phone'=>$p->customer_phone,
            'order_no'=>$p->order_no,'kind'=>$p->kind,'currency'=>$p->currency,'amount'=>(float)$p->amount,
            'usd'=>(float)$p->usd_amount,'method'=>$p->method,'reference'=>$p->reference,
            'business'=>$u->tenant->name ?? 'Family Shoppers','date'=>optional($p->created_at)->format('Y-m-d H:i'),
        ]]);
    }

    /** POST /papi/payments/{id}/apply — mark an advance applied (consumed against an invoice). */
    public function apply(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $p = CustomerPayment::where('tenant_id',$u->tenant_id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $p->applied = ! filter_var($request->input('undo', false), FILTER_VALIDATE_BOOLEAN);
        $p->save();
        return response()->json(['ok'=>true,'applied'=>(bool)$p->applied]);
    }
}
