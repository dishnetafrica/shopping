<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
use App\Models\CustomerPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shree-only currency-aware transactional cashbook. Entries are order_costs with
 * stage in {cash_in, cash_out}. Money-in adds to a holder's cash; money-out reduces it.
 * Fully filterable by currency (USD/SSP/UGX), paginated. Same engine as Cash Position + P&L.
 */
class TradeCashbookController extends Controller
{
    private function gate(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        return null;
    }

    public function page(Request $request)
    {
        if ($g = $this->gate($request)) return $g;
        $path = resource_path('panel/trade_cashbook.html');
        if (! is_file($path)) abort(500, 'Cashbook asset missing.');
        return response(file_get_contents($path),200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/trade-cashbook?currency=ALL|USD|SSP|UGX&page=1 */
    public function data(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->query('currency','ALL'));
        $per = min(50, max(5,(int)$request->query('per',20)));
        $page = max(1,(int)$request->query('page',1));
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        // running per-currency balance (approved only) across ALL cash movements
        $balQ = OrderCost::where('tenant_id',$t->id)->where('status','approved');
        if (! $isOwner) $balQ->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        $balRows = (clone $balQ)->select('currency','stage',
                DB::raw('SUM(paid_native) as native'))->groupBy('currency','stage')->get();
        $bal = ['USD'=>0,'UGX'=>0,'SSP'=>0];
        foreach ($balRows as $r) {
            $sign = in_array($r->stage,['funding','cash_in','customer_payment'],true) ? 1 : -1;   // in adds, everything else reduces
            if (isset($bal[$r->currency])) $bal[$r->currency] += $sign * (float)$r->native;
        }

        // the transaction list (all cash-affecting entries), filterable by currency
        $listQ = OrderCost::where('tenant_id',$t->id);
        if (! $isOwner) $listQ->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        if ($cur !== 'ALL') $listQ->where('currency',$cur);
        $total = (clone $listQ)->count();

        // running balance per entry (per-currency, by cash actually moved), oldest->newest,
        // over the SAME set shown in the list so each row shows the balance after it.
        $allRows = (clone $listQ)->orderBy('created_at')->orderBy('id')->get(['id','currency','stage','paid_native','rate_to_usd']);
        $runCur = ['USD'=>0.0,'UGX'=>0.0,'SSP'=>0.0]; $runUsd = 0.0; $runById = [];
        foreach ($allRows as $r) {
            $sign = in_array($r->stage,['funding','cash_in','customer_payment'],true) ? 1 : -1;
            $pn = (float)$r->paid_native;
            if (isset($runCur[$r->currency])) $runCur[$r->currency] += $sign * $pn;
            $rate = (float)$r->rate_to_usd; $runUsd += $sign * ($rate>0 ? $pn/$rate : 0);
            $runById[$r->id] = ['run'=>round($runCur[$r->currency] ?? 0, 2), 'run_usd'=>round($runUsd, 2)];
        }

        $rows = $listQ->orderByDesc('created_at')->orderByDesc('id')->forPage($page,$per)->get()->map(function($c) use ($runById) {
            $isIn = in_array($c->stage,['funding','cash_in','customer_payment'],true);
            $stageLabels = ['cash_in'=>'Cash In','cash_out'=>'Cash Out','funding'=>'Funding','purchase'=>'Purchase',
                'customer_payment'=>'Receipt','drawing'=>'Drawing','supplier_payment'=>'Supplier Pay'];
            $stageLabel = $stageLabels[$c->stage] ?? ucfirst(str_replace('_',' ',$c->stage));
            $ref = in_array($c->stage,['cash_in','cash_out'],true)
                ? 'CB-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT)
                : ($c->order_no ?: strtoupper(substr($c->stage,0,3)).'-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT));
            $editable = in_array($c->stage,['cash_in','cash_out','funding','purchase','customer_payment','expense','drawing','supplier_payment'],true);
            return ['id'=>$c->id,'stage'=>$c->stage,'dir'=>$isIn?'in':'out','ref'=>$ref,
                'currency'=>$c->currency,'native'=>(float)$c->native_amount,'usd'=>(float)$c->usd_amount,
                'rate'=>(float)$c->rate_to_usd,'order_no'=>$c->order_no,'pay_status'=>$c->pay_status,'editable'=>$editable,
                'run'=>$runById[$c->id]['run'] ?? 0, 'run_usd'=>$runById[$c->id]['run_usd'] ?? 0,
                'category'=>$c->category ?: $stageLabel,
                'holder'=>$c->holder,'note'=>$c->note ?: ($c->order_no ?: ''),
                'status'=>$c->status,'when'=>$c->created_at?->format('d M H:i'),
                'created_date'=>$c->created_at?->format('Y-m-d\TH:i')];
        });

        return response()->json(['ok'=>true,'currency'=>$cur,'balances'=>$bal,
            'view'=>$isOwner?'everyone':'mine','whoami'=>$u->name,'is_owner'=>$isOwner,
            'entries'=>$rows,'total'=>$total,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per),
            'default_rates'=>['UGX'=>(float)$t->setting('rate_ugx_per_usd',3700),
                              'SSP'=>(float)$t->setting('rate_ssp_per_usd',5180),'USD'=>1]]);
    }

    /** GET /papi/trade-cashbook/all — every filtered entry (no pagination) for export. */
    public function all(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->query('currency','ALL'));
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $q = OrderCost::where('tenant_id',$t->id);
        if (! $isOwner) $q->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        if ($cur !== 'ALL') $q->where('currency',$cur);

        $rows = $q->orderBy('created_at')->orderBy('id')->get();
        $runCur = ['USD'=>0.0,'UGX'=>0.0,'SSP'=>0.0];
        $stageLabels = ['cash_in'=>'Cash In','cash_out'=>'Cash Out','funding'=>'Funding','purchase'=>'Purchase',
            'customer_payment'=>'Receipt','drawing'=>'Drawing','supplier_payment'=>'Supplier Pay'];
        $out = [];
        foreach ($rows as $c) {
            $isIn = in_array($c->stage,['funding','cash_in','customer_payment'],true);
            if (isset($runCur[$c->currency])) $runCur[$c->currency] += ($isIn?1:-1) * (float)$c->paid_native;
            $label = $stageLabels[$c->stage] ?? ucfirst(str_replace('_',' ',$c->stage));
            $ref = in_array($c->stage,['cash_in','cash_out'],true)
                ? 'CB-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT)
                : ($c->order_no ?: strtoupper(substr($c->stage,0,3)).'-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT));
            $out[] = [
                'ref'=>$ref,'date'=>$c->created_at?->format('Y-m-d H:i'),
                'category'=>$c->category ?: $label,'note'=>$c->note ?: ($c->order_no ?: ''),
                'person'=>$c->holder,'currency'=>$c->currency,
                'in'=>$isIn?(float)$c->native_amount:0,'out'=>$isIn?0:(float)$c->native_amount,
                'usd'=>(float)$c->usd_amount,'balance'=>round($runCur[$c->currency] ?? 0,2),
                'status'=>$c->status,
            ];
        }
        // newest first for display/export
        $out = array_reverse($out);
        return response()->json(['ok'=>true,'currency'=>$cur,'entries'=>$out,'count'=>count($out)]);
    }

    /** POST /papi/trade-cashbook — record a cash in/out entry. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $dir = $request->input('dir')==='out' ? 'cash_out' : 'cash_in';
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $native = (float)$request->input('native_amount',0);
        $rate = (float)$request->input('rate_to_usd', $cur==='USD'?1:0);
        if ($native<=0 || $rate<=0) return response()->json(['ok'=>false,'error'=>'bad_amount']);

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'stage'=>$dir,
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>(string)($u->name ?? ''), 'holder_user_id'=>$u->id,
            'note'=>(string)$request->input('note',''),
            'category'=>trim((string)$request->input('category','')) ?: null,
            'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'=>$u->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }

    /** POST /papi/trade-cashbook/convert — convert one currency to another as TWO linked legs
     *  (money OUT of the source currency, money IN to the target) in a single transaction. */
    public function convert(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);

        $from = strtoupper((string)$request->input('from_currency',''));
        $to   = strtoupper((string)$request->input('to_currency',''));
        $ok3  = ['USD','UGX','SSP'];
        if (! in_array($from,$ok3,true) || ! in_array($to,$ok3,true) || $from===$to)
            return response()->json(['ok'=>false,'error'=>'bad_currency'],422);

        $fromAmt = round((float)$request->input('from_amount',0), 2);
        if ($fromAmt <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        // rate = target native per 1 source native (e.g. USD->UGX rate 3655)
        $rate = round((float)$request->input('rate',0), 6);
        if ($rate <= 0) return response()->json(['ok'=>false,'error'=>'bad_rate'],422);
        $toAmt = round($fromAmt * $rate, 2);

        // USD value each leg (so both legs carry a consistent usd_amount)
        $usdPerFrom = $from==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($from).'_per_usd', $from==='UGX'?3655:5180);
        if ($usdPerFrom <= 0) $usdPerFrom = 1;
        $usdValue = OrderCost::toUsd($fromAmt, $usdPerFrom);   // value moved, in USD

        // holder: owner may name one; default current user
        $holderName = (string)($u->name ?? ''); $holderUid = $u->id;
        $recv = trim((string)$request->input('holder',''));
        if ($recv !== '' && mb_strtolower($recv) !== mb_strtolower($holderName)) {
            $holderName = $recv;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$recv)->value('id'); } catch (\Throwable $e) { $holderUid = null; }
        }
        $note = trim((string)$request->input('note','')) ?: null;
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }
        $status = ((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved';

        $rateFromToUsd = $from==='USD' ? 1.0 : $usdPerFrom;         // native/USD for the FROM leg
        $rateToToUsd   = $to==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($to).'_per_usd', $to==='UGX'?3655:5180);
        if ($rateToToUsd <= 0) $rateToToUsd = ($usdValue>0 ? $toAmt/$usdValue : 1);

        $res = DB::transaction(function () use ($t,$u,$from,$to,$fromAmt,$toAmt,$rate,$rateFromToUsd,$rateToToUsd,$usdValue,$holderName,$holderUid,$note,$createdAt,$status) {
            $out = OrderCost::create([
                'tenant_id'=>$t->id,'stage'=>'cash_out','currency'=>$from,
                'native_amount'=>$fromAmt,'rate_to_usd'=>$rateFromToUsd,'usd_amount'=>$usdValue,
                'holder'=>$holderName,'holder_user_id'=>$holderUid,'pay_status'=>'cash','paid_native'=>$fromAmt,
                'category'=>'Conversion',
                'note'=>($note?($note.' — '):'').'Converted '.$from.' '.number_format($fromAmt,2).' → '.$to.' '.number_format($toAmt,2).' @ '.$rate,
                'status'=>$status,'created_by'=>$u->name ?? '',
            ]);
            $in = OrderCost::create([
                'tenant_id'=>$t->id,'stage'=>'cash_in','currency'=>$to,
                'native_amount'=>$toAmt,'rate_to_usd'=>$rateToToUsd,'usd_amount'=>$usdValue,
                'holder'=>$holderName,'holder_user_id'=>$holderUid,'pay_status'=>'cash','paid_native'=>$toAmt,
                'category'=>'Conversion',
                'note'=>($note?($note.' — '):'').'From '.$from.' '.number_format($fromAmt,2).' @ '.$rate,
                'status'=>$status,'created_by'=>$u->name ?? '',
            ]);
            if ($createdAt) { $out->created_at=$createdAt; $out->save(); $in->created_at=$createdAt; $in->save(); }
            return ['out_id'=>$out->id,'in_id'=>$in->id];
        });

        return response()->json(['ok'=>true,'from'=>$from,'to'=>$to,'from_amount'=>$fromAmt,'to_amount'=>$toAmt,
            'rate'=>$rate,'holder'=>$holderName,'usd_value'=>$usdValue] + $res);
    }

    /** POST /papi/trade-cashbook/{id}/delete — owner/manager only. */
    public function delete(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        DB::transaction(function () use ($c, $t) {
            // if it's a receipt, remove the linked customer-payment record too
            if ($c->stage === 'customer_payment') {
                try { CustomerPayment::where('tenant_id',$t->id)->where('order_cost_id',$c->id)->delete(); }
                catch (\Throwable $e) { /* customer_payments optional */ }
            }
            $c->delete();
        });
        return response()->json(['ok'=>true]);
    }

    /** POST /papi/trade-cashbook/{id}/update — owner/manager edits any cash entry.
     *  If it's a customer_payment, the linked customer-payment record is kept in sync. */
    public function update(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);

        $cur = strtoupper((string)$request->input('currency', $c->currency));
        $amount = round((float)$request->input('amount', (float)$c->native_amount), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);
        $category = trim((string)$request->input('category', (string)$c->category)) ?: $c->category;
        $note = trim((string)$request->input('note',''));
        $orderNo = $request->has('order_no') ? (trim((string)$request->input('order_no','')) ?: null) : $c->order_no;

        // person / receiver (holder)
        $holderName = (string)$c->holder; $holderUid = $c->holder_user_id;
        $recv = trim((string)$request->input('holder',''));
        if ($recv !== '' && mb_strtolower($recv) !== mb_strtolower($holderName)) {
            $holderName = $recv;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$recv)->value('id'); } catch (\Throwable $e) { $holderUid = null; }
        }
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        DB::transaction(function () use ($c,$cur,$amount,$rate,$usd,$category,$note,$orderNo,$holderName,$holderUid,$createdAt,$t) {
            $isCredit = ($c->pay_status === 'credit');
            $c->currency = $cur; $c->native_amount = $amount; $c->rate_to_usd = $rate; $c->usd_amount = $usd;
            $c->paid_native = $isCredit ? 0 : $amount;
            $c->category = $category; $c->order_no = $orderNo;
            $c->holder = $holderName; $c->holder_user_id = $holderUid;
            if ($note !== '') $c->note = $note;
            if ($createdAt) $c->created_at = $createdAt;
            $c->save();

            // keep the customer-payment record in sync when editing a receipt
            if ($c->stage === 'customer_payment') {
                try {
                    $pay = CustomerPayment::where('tenant_id',$t->id)->where('order_cost_id',$c->id)->first();
                    if ($pay) {
                        $pay->currency = $cur; $pay->amount = $amount; $pay->rate_to_usd = $rate; $pay->usd_amount = $usd;
                        $pay->order_no = $orderNo; $pay->holder = $holderName; $pay->holder_user_id = $holderUid;
                        if ($createdAt) $pay->created_at = $createdAt;
                        $pay->save();
                    }
                } catch (\Throwable $e) { /* customer_payments optional */ }
            }
        });

        return response()->json(['ok'=>true,'id'=>$c->id,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd,'holder'=>$holderName]);
    }
}
