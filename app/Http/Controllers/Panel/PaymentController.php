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
        if (\App\Support\Access::blocks($request->user(), 'payment.record')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;

        $customer = trim((string)$request->input('customer_name',''));
        if ($customer === '') return response()->json(['ok'=>false,'error'=>'no_customer'],422);
        $cur    = strtoupper((string)$request->input('currency','USD'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // E3.4 — one FX source
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);

        $kind    = in_array((string)$request->input('kind'), ['advance','settlement'], true) ? (string)$request->input('kind') : 'advance';
        $orderNo = trim((string)$request->input('order_no','')) ?: null;
        if (! $orderNo) return response()->json(['ok'=>false,'error'=>'no_order'],422);   // a receipt must be tied to an order
        // receiver = who physically got the cash (lands in THEIR cashbook). Owner/manager may set; default = current user.
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);
        $holderName = (string)($u->name ?? ''); $holderUid = $u->id;
        $recv = trim((string)$request->input('receiver',''));
        if ($isOwner && $recv !== '' && mb_strtolower($recv) !== mb_strtolower($holderName)) {
            $holderName = $recv;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$recv)->value('id'); } catch (\Throwable $e) { $holderUid = null; }
        }
        $method  = trim((string)$request->input('method','Cash')) ?: 'Cash';
        if (mb_strlen($method) > 24) $method = mb_substr($method, 0, 24);
        $invoice = trim((string)$request->input('invoice_no','')) ?: null;
        $ref     = trim((string)$request->input('reference','')) ?: null;
        $phone   = trim((string)$request->input('customer_phone','')) ?: null;
        $note    = trim((string)$request->input('note','')) ?: null;
        $sendReceipt = filter_var($request->input('send_receipt', true), FILTER_VALIDATE_BOOLEAN);
        // optional created-date override
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        $path = null;
        if ($request->hasFile('receipt')) $path = $request->file('receipt')->store("receipts/t{$t->id}", 'public');

        // E4.1 — record through the ONE payment recorder (behaviour-identical to the previous inline
        // transaction). This is the single ledger the invoice "Pay", staff-cash and cashbook paths
        // converge on in E4.2–E4.4.
        $result = app(\App\Services\Money\PaymentService::class)->record($t, [
            'customer'      => $customer,
            'phone'         => $phone,
            'order_no'      => $orderNo,
            'invoice_no'    => $invoice,
            'kind'          => $kind,
            'currency'      => $cur,
            'amount'        => $amount,
            'rate'          => $rate,
            'usd'           => $usd,
            'method'        => $method,
            'reference'     => $ref ?: $note,
            'receipt_path'  => $path,
            'holder'        => $holderName,
            'holder_user_id'=> $holderUid,
            'created_by'    => $u->name ?? '',
            'cost_note'     => 'Payment from '.$customer.($orderNo?(' ('.$orderNo.')'):''),
            'created_at'    => $createdAt,
        ]);

        $this->recomputeOrderPaid($t, $orderNo);   // keep the order's paid tracker in step with the ledger

        return response()->json(['ok'=>true,'send_receipt'=>$sendReceipt] + $result + ['usd'=>$usd,'currency'=>$cur,'amount'=>$amount]);
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
            'holder'=>$p->holder,'rate_to_usd'=>(float)$p->rate_to_usd,
            'business'=>$u->tenant->name ?? 'Family Shoppers','date'=>optional($p->created_at)->format('Y-m-d H:i'),
            'created_date'=>optional($p->created_at)->format('Y-m-d\TH:i'),
        ]]);
    }

    /** POST /papi/payments/{id}/update — owner/manager edits a receipt; fixes the cash row AND
     *  the customer-payment record together so nothing drifts. */
    public function update(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $t = $u->tenant;
        $p = CustomerPayment::where('tenant_id',$t->id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'not_found'],404);

        $oldOrderNo = (string) $p->order_no;   // capture before we overwrite it, to re-sync the old order too
        $customer = trim((string)$request->input('customer_name', $p->customer_name)) ?: $p->customer_name;
        $orderNo  = trim((string)$request->input('order_no',''));
        if ($orderNo === '') return response()->json(['ok'=>false,'error'=>'no_order'],422);   // must stay tied to an order
        $cur    = strtoupper((string)$request->input('currency', $p->currency));
        $amount = round((float)$request->input('amount', (float)$p->amount), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // E3.4 — one FX source
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);
        $kind = in_array((string)$request->input('kind', $p->kind), ['advance','settlement'], true) ? (string)$request->input('kind', $p->kind) : $p->kind;
        $method = trim((string)$request->input('method', $p->method)) ?: $p->method;
        if (mb_strlen($method) > 24) $method = mb_substr($method, 0, 24);
        $phone  = trim((string)$request->input('customer_phone', (string)$p->customer_phone)) ?: null;
        $note   = trim((string)$request->input('note','')) ?: null;

        // receiver (who got the cash) — defaults to existing holder; owner may change
        $holderName = (string)($p->holder ?: ($u->name ?? ''));
        $holderUid  = $p->holder_user_id;
        $recv = trim((string)$request->input('receiver',''));
        if ($recv !== '' && mb_strtolower($recv) !== mb_strtolower($holderName)) {
            $holderName = $recv;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$recv)->value('id'); } catch (\Throwable $e) { $holderUid = null; }
        }
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        DB::transaction(function () use ($p,$customer,$phone,$orderNo,$kind,$cur,$amount,$rate,$usd,$method,$note,$holderName,$holderUid,$createdAt) {
            // 1) the customer-payment record
            $p->customer_name = $customer; $p->customer_phone = $phone; $p->order_no = $orderNo;
            $p->kind = $kind; $p->applied = ($kind==='settlement');
            $p->currency = $cur; $p->amount = $amount; $p->rate_to_usd = $rate; $p->usd_amount = $usd;
            $p->method = $method; if ($note !== null) $p->reference = $note;
            $p->holder = $holderName; $p->holder_user_id = $holderUid;
            if ($createdAt) $p->created_at = $createdAt;
            $p->save();
            // 2) the mirrored cash row (money IN in the receiver's cashbook)
            if ($p->order_cost_id) {
                $cost = OrderCost::find($p->order_cost_id);
                if ($cost) {
                    $cost->order_no = $orderNo; $cost->currency = $cur; $cost->native_amount = $amount;
                    $cost->rate_to_usd = $rate; $cost->usd_amount = $usd; $cost->paid_native = $amount;
                    $cost->holder = $holderName; $cost->holder_user_id = $holderUid;
                    $cost->note = 'Payment from '.$customer.($orderNo?(' ('.$orderNo.')'):'');
                    if ($createdAt) $cost->created_at = $createdAt;
                    $cost->save();
                }
            }
        });

        try { app(\App\Services\Invoicing\InvoiceService::class)->syncByPayment($t, $p->invoice_no, $orderNo); } catch (\Throwable $e) {}
        $this->recomputeOrderPaid($t, $orderNo);
        if ($oldOrderNo !== '' && strcasecmp($oldOrderNo, $orderNo) !== 0) $this->recomputeOrderPaid($t, $oldOrderNo);

        return response()->json(['ok'=>true,'id'=>$p->id,'receipt_no'=>$p->receipt_no,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd,'holder'=>$holderName,'order_no'=>$orderNo]);
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
        try { app(\App\Services\Invoicing\InvoiceService::class)->syncByPayment($u->tenant, $p->invoice_no, $p->order_no); } catch (\Throwable $e) {}
        $this->recomputeOrderPaid($u->tenant, $p->order_no);
        return response()->json(['ok'=>true,'applied'=>(bool)$p->applied]);
    }

    /** POST /papi/payments/{id}/delete — owner/manager hard-deletes a receipt and its mirrored cash row. */
    public function destroy(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $p = CustomerPayment::where('tenant_id',$u->tenant_id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'not_found'],404);

        DB::transaction(function () use ($p, $u) {
            if ($p->order_cost_id) {
                $cost = OrderCost::where('tenant_id',$u->tenant_id)->find($p->order_cost_id);
                if ($cost) {
                    $before = ['status'=>$cost->status,'currency'=>$cost->currency,'native_amount'=>(float)$cost->native_amount];
                    $cost->status='void'; $cost->voided_at=now(); $cost->voided_by=$u->name ?? ''; $cost->void_reason='payment voided'; $cost->save();
                    \App\Services\AuditLogger::log($u->tenant_id,'order_cost',$cost->id,'void',$u,$before,['status'=>'void'],'payment voided');
                }
            }
            \App\Services\AuditLogger::log($u->tenant_id,'customer_payment',$p->id,'void',$u,['amount'=>(float)($p->amount ?? 0)],null,'payment voided');
            $p->delete();
        });
        try { app(\App\Services\Invoicing\InvoiceService::class)->syncByPayment($u->tenant, $p->invoice_no, $p->order_no); } catch (\Throwable $e) {}
        $this->recomputeOrderPaid($u->tenant, $p->order_no);   // roll back the order's paid tracker from the ledger
        return response()->json(['ok'=>true,'voided'=>true]);
    }

    /**
     * Recompute an order's amount_paid from the ONE payment ledger (settlement CustomerPayments),
     * expressed in the order's own transaction currency — the same rule the invoice reconciler uses.
     * Idempotent: after any create/edit/delete/allocate the order, invoice and cashbook all agree,
     * so amount_paid can never drift (the bug where an order stayed "paid" after its payment changed).
     */
    private function recomputeOrderPaid($tenant, ?string $orderNo): void
    {
        if (! $tenant || ! $orderNo) return;
        try {
            $order = \App\Models\Order::where('tenant_id', $tenant->id)->where('order_no', $orderNo)->first();
            if (! $order) return;
            $oc  = \App\Services\Money\SalesBaseCurrency::txnCurrency($order, $tenant);
            $fx  = \App\Services\Money\Fx::forTenant($tenant);
            $pays = CustomerPayment::where('tenant_id', $tenant->id)->where('order_no', $orderNo)
                ->where('kind', 'settlement')->where('applied', true)->get();
            $paid = 0.0;
            foreach ($pays as $p) {
                $paid += strtoupper((string) $p->currency) === $oc ? (float) $p->amount
                       : ($oc === 'USD' ? (float) $p->usd_amount : (float) $p->usd_amount * $fx->ratePerUsd($oc));
            }
            $order->amount_paid = round($paid, 2);
            $order->save();
        } catch (\Throwable $e) { /* never break a payment write on the denormalised tracker */ }
    }
}
