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

        // running per-currency balance (approved only) across ALL cash movements.
        // Credit purchases are payables (no cash moved) → excluded from the cashbook.
        $balQ = OrderCost::where('tenant_id',$t->id)->where('status','approved')
            ->where(fn($w)=>$w->where('pay_status','!=','credit')->orWhereNull('pay_status'));
        if (! $isOwner) $balQ->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        $person = trim((string)$request->query('person',''));
        if ($isOwner && $person !== '') $balQ->where('holder',$person);   // owner: view one person's cashbook
        $balRows = (clone $balQ)->select('currency','stage',
                DB::raw('SUM(paid_native) as native'))->groupBy('currency','stage')->get();
        $bal = ['USD'=>0,'UGX'=>0,'SSP'=>0];
        foreach ($balRows as $r) {
            $sign = in_array($r->stage,['funding','cash_in','customer_payment'],true) ? 1 : -1;   // in adds, everything else reduces
            if (isset($bal[$r->currency])) $bal[$r->currency] += $sign * (float)$r->native;
        }

        // the transaction list (cash-affecting entries only), filterable by currency.
        // Credit purchases don't move cash → they show in "Suppliers We Owe", not here.
        $listQ = OrderCost::where('tenant_id',$t->id)
            ->where(fn($w)=>$w->where('pay_status','!=','credit')->orWhereNull('pay_status'));
        if (! $isOwner) $listQ->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        if ($isOwner && $person !== '') $listQ->where('holder',$person);
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
            $noteText = $c->note ?: ($c->order_no ?: '');
            if ($c->qty !== null && (float)$c->qty > 0) {
                $qtyStr = rtrim(rtrim(number_format((float)$c->qty, 2, '.', ''), '0'), '.');
                $ucStr  = $c->unit_cost !== null ? (' x '.$c->currency.' '.number_format((float)$c->unit_cost, 0)) : '';
                $noteText = trim($noteText.'  (qty '.$qtyStr.$ucStr.')');
            }
            return ['id'=>$c->id,'stage'=>$c->stage,'dir'=>$isIn?'in':'out','ref'=>$ref,
                'currency'=>$c->currency,'native'=>(float)$c->native_amount,'usd'=>(float)$c->usd_amount,
                'rate'=>(float)$c->rate_to_usd,'order_no'=>$c->order_no,'pay_status'=>$c->pay_status,'editable'=>$editable,
                'qty'=>$c->qty!==null?(float)$c->qty:null,'unit_cost'=>$c->unit_cost!==null?(float)$c->unit_cost:null,
                'run'=>$runById[$c->id]['run'] ?? 0, 'run_usd'=>$runById[$c->id]['run_usd'] ?? 0,
                'category'=>$c->category ?: $stageLabel,
                'holder'=>$c->holder,'note'=>$noteText,
                'supplier'=>$c->supplier_name,'supplier_id'=>$c->supplier_id,
                'status'=>$c->status,'when'=>$c->created_at?->format('d M H:i'),
                'created_date'=>$c->created_at?->format('Y-m-d\TH:i')];
        });

        return response()->json(['ok'=>true,'currency'=>$cur,'balances'=>$bal,
            'view'=>$isOwner?'everyone':'mine','whoami'=>$u->name,'is_owner'=>$isOwner,
            'entries'=>$rows,'total'=>$total,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per),
            // E3.4 — one FX source (App\Services\Money\Fx). Unifies the old inconsistent 3700 UGX
            // default with the accounting rate the rest of the app uses.
            'default_rates'=>['UGX'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX'),
                              'SSP'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP'),'USD'=>1]]);
    }

    /** GET /papi/trade-cashbook/all — every filtered entry (no pagination) for export. */
    public function all(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->query('currency','ALL'));
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $q = OrderCost::where('tenant_id',$t->id)
            ->where(fn($w)=>$w->where('pay_status','!=','credit')->orWhereNull('pay_status'));
        if (! $isOwner) $q->where(fn($w)=>$w->where('holder_user_id',$u->id)->orWhere('holder',$u->name));
        $person = trim((string)$request->query('person',''));
        if ($isOwner && $person !== '') $q->where('holder',$person);
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
            $noteText = $c->note ?: ($c->order_no ?: '');
            if ($c->qty !== null && (float)$c->qty > 0) {
                $qtyStr = rtrim(rtrim(number_format((float)$c->qty, 2, '.', ''), '0'), '.');
                $ucStr  = $c->unit_cost !== null ? (' x '.$c->currency.' '.number_format((float)$c->unit_cost, 0)) : '';
                $noteText = trim($noteText.'  (qty '.$qtyStr.$ucStr.')');
            }
            $ref = in_array($c->stage,['cash_in','cash_out'],true)
                ? 'CB-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT)
                : ($c->order_no ?: strtoupper(substr($c->stage,0,3)).'-'.str_pad((string)$c->id,4,'0',STR_PAD_LEFT));
            $out[] = [
                'ref'=>$ref,'date'=>$c->created_at?->format('Y-m-d H:i'),
                'category'=>$c->category ?: $label,'note'=>$noteText,
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
        // The "Pay supplier" form posts the amount as `amount`; the guided tiles post it as `native_amount`.
        // Accept either so a supplier payment (e.g. UGX from Jayesh) is not silently rejected as bad_amount.
        $native = (float)$request->input('native_amount', $request->input('amount', 0));
        $rate = (float)$request->input('rate_to_usd', $cur==='USD'?1:0);
        if ($native<=0 || $rate<=0) return response()->json(['ok'=>false,'error'=>'bad_amount','message'=>'Enter an amount and a rate.']);

        $isPriv = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);
        $category = trim((string)$request->input('category','')) ?: null;

        // "Whose cash" — owner/manager may attribute the entry to another person; staff record under themselves.
        $holderName = (string)($u->name ?? ''); $holderUid = $u->id;
        $reqHolder = trim((string)$request->input('holder',''));
        if ($isPriv && $reqHolder !== '') {
            $holderName = $reqHolder;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$reqHolder)->value('id'); }
            catch (\Throwable $e) { $holderUid = null; }
        }

        // Optional supplier link. A cash-out can be tagged to a supplier (informational for a
        // purchase, required for an on-account payment).
        $supId = (int) $request->input('supplier_id', 0) ?: null;
        $supName = null;
        if ($supId) {
            $sup = \App\Models\Supplier::where('tenant_id',$t->id)->find($supId);
            if ($sup) { $supName = $sup->name; } else { $supId = null; }
        }

        // A cash-out tagged as a purchase is booked as stage 'purchase' so it lands as a purchase/COGS,
        // not a generic expense. (Cash still leaves the chosen person's hand exactly the same way.)
        $stage = $dir;
        $purchaseCats = ['local purchase','purchase','goods','stock purchase'];
        if ($dir==='cash_out' && $category!==null && in_array(mb_strtolower($category),$purchaseCats,true)) {
            $stage = 'purchase';
        }

        // "Supplier payment (on account)" — money paid to a supplier to reduce what we owe them.
        // Booked as stage 'supplier_payment' so it nets against their credit purchases automatically
        // (it does NOT book goods again — that avoids double-counting the items).
        $payCats = ['supplier payment','supplier payment (on account)','on account','account payment'];
        $isOnAccount = ($dir==='cash_out' && $category!==null && in_array(mb_strtolower($category),$payCats,true));
        if ($isOnAccount) {
            if (! $supId) return response()->json(['ok'=>false,'error'=>'supplier_required','message'=>'Choose the supplier this payment is for.'],422);
            $stage = 'supplier_payment';
        }

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'stage'=>$stage,
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>$holderName, 'holder_user_id'=>$holderUid,
            'pay_status'=>'cash',
            'supplier_id'=>$supId, 'supplier_name'=>$supName,
            'note'=>(string)$request->input('note',''),
            'category'=>$category,
            'order_no'=>trim((string)$request->input('order_no','')) ?: null,
            'status'=>$isPriv ? 'approved' : (((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved'),
            'created_by'=>$u->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount,'holder'=>$holderName,'stage'=>$stage,'supplier_id'=>$supId]);
    }

    /** GET /papi/holder-balance?holder=&holder_user_id=&currency= — current native cash for one
     *  person+currency. Read-only helper for the negative-cash warning; touches no write path.
     *  (Restores the method the route references.) */
    public function balanceFor(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $holder = trim((string)$request->input('holder',''));
        $uid    = (int)$request->input('holder_user_id', 0);
        $cur    = strtoupper((string)$request->input('currency','UGX'));
        if (! in_array($cur,['USD','UGX','SSP'],true)) return response()->json(['ok'=>false,'error'=>'bad_currency'],422);
        if ($holder === '' && $uid <= 0) return response()->json(['ok'=>false,'error'=>'no_holder'],422);
        $inStages = ['funding','cash_in','customer_payment'];
        $q = OrderCost::where('tenant_id',$t->id)->where('currency',$cur)->whereIn('status',['approved','pending']);
        if ($uid > 0) $q->where(function($w) use ($uid,$holder){ $w->where('holder_user_id',$uid); if($holder!=='') $w->orWhere('holder',$holder); });
        else $q->where('holder',$holder);
        $bal = 0.0;
        foreach ($q->get(['stage','paid_native']) as $r) {
            $bal += (in_array($r->stage,$inStages,true) ? 1 : -1) * (float)$r->paid_native;
        }
        return response()->json(['ok'=>true,'holder'=>$holder,'currency'=>$cur,'balance'=>round($bal,2)]);
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
        $usdPerFrom = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($from); // E3.4 — one FX source
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
        $status = 'approved'; // owner/manager-gated action -> auto-approved

        // Each leg's rate_to_usd MUST reconcile with the dollars that actually moved
        // (native / rate_to_usd === usd_amount), otherwise the cashbook — which sums by
        // rate_to_usd — mis-values the currency held. Deriving both rates from $usdValue
        // captures the REAL deal rate the user typed (e.g. 720,000 SSP for $100 -> 7,200),
        // instead of silently stamping the system default rate on the target leg. This is
        // the permanent fix for the "SSP valued at the wrong rate" bug.
        $rateFromToUsd = $usdValue > 0 ? round($fromAmt / $usdValue, 6) : ($from==='USD' ? 1.0 : $usdPerFrom);
        $rateToToUsd   = $usdValue > 0 ? round($toAmt   / $usdValue, 6) : \App\Services\Money\Fx::forTenant($t)->ratePerUsd($to);

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

    /** POST /papi/trade-cashbook/transfer — move cash from one person to another (same currency):
     *  OUT of the giver's hand + IN to the receiver's hand, in one transaction. */
    public function transfer(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);

        $giver = trim((string)$request->input('from_person',''));
        $receiver = trim((string)$request->input('to_person',''));
        if ($giver === '' || $receiver === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        if (mb_strtolower($giver) === mb_strtolower($receiver)) return response()->json(['ok'=>false,'error'=>'same_person'],422);

        $cur = strtoupper((string)$request->input('currency','UGX'));
        if (! in_array($cur,['USD','UGX','SSP'],true)) return response()->json(['ok'=>false,'error'=>'bad_currency'],422);
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // E3.4 — one FX source
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);

        $resolve = function($name) use ($t) {
            try { return \App\Models\User::where('tenant_id',$t->id)->where('name',$name)->value('id'); } catch (\Throwable $e) { return null; }
        };
        $giverUid = $resolve($giver); $recvUid = $resolve($receiver);
        $note = trim((string)$request->input('note','')) ?: null;
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }
        $status = 'approved'; // owner/manager-gated action -> auto-approved

        $res = DB::transaction(function () use ($t,$u,$giver,$giverUid,$receiver,$recvUid,$cur,$amount,$rate,$usd,$note,$createdAt,$status) {
            $out = OrderCost::create([
                'tenant_id'=>$t->id,'stage'=>'cash_out','currency'=>$cur,
                'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'holder'=>$giver,'holder_user_id'=>$giverUid,'pay_status'=>'cash','paid_native'=>$amount,
                'category'=>'Transfer',
                'note'=>($note?($note.' — '):'').'Handed to '.$receiver,
                'status'=>$status,'created_by'=>$u->name ?? '',
            ]);
            $in = OrderCost::create([
                'tenant_id'=>$t->id,'stage'=>'cash_in','currency'=>$cur,
                'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'holder'=>$receiver,'holder_user_id'=>$recvUid,'pay_status'=>'cash','paid_native'=>$amount,
                'category'=>'Transfer',
                'note'=>($note?($note.' — '):'').'Received from '.$giver,
                'status'=>$status,'created_by'=>$u->name ?? '',
            ]);
            if ($createdAt) { $out->created_at=$createdAt; $out->save(); $in->created_at=$createdAt; $in->save(); }
            return ['out_id'=>$out->id,'in_id'=>$in->id];
        });

        return response()->json(['ok'=>true,'from'=>$giver,'to'=>$receiver,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd] + $res);
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
        if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at))
            return response()->json(['ok'=>false,'error'=>'period_locked','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);
        $before = ['status'=>$c->status,'currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'stage'=>$c->stage,'note'=>$c->note];
        $reason = trim((string)$request->input('reason','')) ?: null;
        DB::transaction(function () use ($c, $t, $u, $reason) {
            $c->status = 'void'; $c->voided_at = now(); $c->voided_by = $u->name ?? ''; $c->void_reason = $reason;
            $c->save();
            if ($c->stage === 'customer_payment') {
                try { CustomerPayment::where('tenant_id',$t->id)->where('order_cost_id',$c->id)->delete(); }
                catch (\Throwable $e) { /* customer_payments optional */ }
            }
        });
        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'void',$u,$before,['status'=>'void'],$reason);
        return response()->json(['ok'=>true,'voided'=>true]);
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
        // Period lock: can't edit an entry already in a locked period.
        if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at))
            return response()->json(['ok'=>false,'error'=>'period_locked','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);
        $auditBefore = ['currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'rate_to_usd'=>(float)$c->rate_to_usd,
            'category'=>$c->category,'order_no'=>$c->order_no,'holder'=>$c->holder,'note'=>$c->note,
            'created_at'=>optional($c->created_at)->format('Y-m-d H:i')];
        // Currency: accept a change, else keep the entry's existing currency.
        // (Previously this variable was never defined, so every edit tried to save
        //  currency = null and the NOT NULL column rejected it -> 500 "went wrong on our side".)
        $cur = strtoupper((string)$request->input('currency', (string)$c->currency)) ?: (string)$c->currency;
        if (! in_array($cur, ['USD','UGX','SSP'], true)) $cur = (string)$c->currency;
        $amount = round((float)$request->input('amount', (float)$c->native_amount), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // E3.4 — one FX source
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
        if ($createdAt && \App\Services\AccountingGuard::isLocked($t->id, $createdAt))
            return response()->json(['ok'=>false,'error'=>'period_locked_target','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);

        // Supplier link. false = field not sent (leave as-is); null = sent empty (clear); int = set.
        $supId = false; $supName = null;
        if ($request->has('supplier_id')) {
            $supId = (int)$request->input('supplier_id') ?: null;
            if ($supId) {
                $sup = \App\Models\Supplier::where('tenant_id',$t->id)->find($supId);
                $supName = $sup ? $sup->name : null;
                if (! $sup) $supId = null;
            }
        }
        // on_account: true -> reclassify as a supplier payment (reduces what we owe);
        //             false -> if it currently is a payment, put it back to a purchase/cash-out.
        $onAcct = $request->has('on_account') ? filter_var($request->input('on_account'), FILTER_VALIDATE_BOOLEAN) : null;
        $effectiveSup = ($supId !== false) ? $supId : $c->supplier_id;
        if ($onAcct === true && ! $effectiveSup)
            return response()->json(['ok'=>false,'error'=>'supplier_required','message'=>'Choose the supplier this payment is for.'],422);

        DB::transaction(function () use ($c,$cur,$amount,$rate,$usd,$category,$note,$orderNo,$holderName,$holderUid,$createdAt,$t,$supId,$supName,$onAcct) {
            $isCredit = ($c->pay_status === 'credit');
            $c->currency = $cur; $c->native_amount = $amount; $c->rate_to_usd = $rate; $c->usd_amount = $usd;
            $c->paid_native = $isCredit ? 0 : $amount;
            $c->category = $category; $c->order_no = $orderNo;
            $c->holder = $holderName; $c->holder_user_id = $holderUid;
            if ($note !== '') $c->note = $note;
            if ($supId !== false) { $c->supplier_id = $supId; $c->supplier_name = $supName; }
            if ($onAcct === true) {
                $c->stage = 'supplier_payment';
            } elseif ($onAcct === false && $c->stage === 'supplier_payment') {
                $c->stage = in_array(mb_strtolower((string)$category), ['purchase','local purchase','goods','stock purchase'], true) ? 'purchase' : 'cash_out';
            }
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

        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'update',$u,$auditBefore,
            ['currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,'category'=>$category,
             'order_no'=>$orderNo,'holder'=>$holderName,'note'=>($note!==''?$note:$c->note),
             'supplier_id'=>$c->supplier_id,'stage'=>$c->stage,
             'created_at'=>optional($createdAt ?: $c->created_at)->format('Y-m-d H:i')],
            trim((string)$request->input('reason','')) ?: null);

        return response()->json(['ok'=>true,'id'=>$c->id,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd,'holder'=>$holderName,'stage'=>$c->stage,'supplier_id'=>$c->supplier_id]);
    }
}
