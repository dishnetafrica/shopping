<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
use App\Models\CustomerPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff Cash — "cash in hand" per person, built from order_costs (which already carries a
 * holder on every entry). Money in = funding / cash_in / customer_payment; everything else
 * (purchases, expenses, cash_out, drawings) is money out. Balance per person per currency =
 * signed sum of cash actually moved (paid_native). Slice 1 is READ-ONLY: a per-person view of
 * data you already record — nothing new is written.
 */
class StaffCashController extends Controller
{
    /** stages that ADD to a person's cash in hand */
    private array $inStages = ['funding','cash_in','customer_payment'];

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
        $path = resource_path('panel/staff_cash.html');
        if (! is_file($path)) abort(500, 'Staff Cash asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /panel/my-cash — the mobile "my cash" field app (a person's own cashbook). */
    public function myPage(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/my_cash.html');
        if (! is_file($path)) abort(500, 'My Cash asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/staff-cash — people with cash in hand + company total.
     *  Owner/manager see everyone; a staff member sees only themselves. */
    public function people(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $q = OrderCost::where('tenant_id',$t->id)->whereNotNull('holder')->where('holder','!=','');
        if (! $isOwner) {
            $q->where(function($w) use ($u){
                $w->where('holder_user_id',$u->id)->orWhere(function($w2) use ($u){ $w2->whereNull('holder_user_id')->where('holder',$u->name); });
            });
        }
        $rows = $q->select('holder','holder_user_id','currency','stage',
                DB::raw('SUM(paid_native) as native'),
                DB::raw('SUM(paid_native / NULLIF(rate_to_usd,0)) as usd'))
            ->groupBy('holder','holder_user_id','currency','stage')->get();

        $people = []; // holder => [name, uid, cur=>native, usd_total]
        foreach ($rows as $r) {
            $sign = in_array($r->stage,$this->inStages,true) ? 1 : -1;
            $k = $r->holder;
            if (! isset($people[$k])) $people[$k] = ['name'=>$r->holder,'uid'=>$r->holder_user_id,'cur'=>['USD'=>0.0,'UGX'=>0.0,'SSP'=>0.0],'usd'=>0.0];
            if (isset($people[$k]['cur'][$r->currency])) $people[$k]['cur'][$r->currency] += $sign * (float)$r->native;
            $people[$k]['usd'] += $sign * (float)$r->usd;
        }

        // open shortages per person (category='Shortage') — shown as a ⚠ flag
        $shq = OrderCost::where('tenant_id',$t->id)->where('category','Shortage');
        if (! $isOwner) $shq->where(function($w) use ($u){ $w->where('holder_user_id',$u->id)->orWhere(function($w2) use ($u){ $w2->whereNull('holder_user_id')->where('holder',$u->name); }); });
        $shRows = $shq->select('holder','currency',DB::raw('SUM(paid_native) as amt'))->groupBy('holder','currency')->get();
        $shorts = [];
        foreach ($shRows as $r) { $shorts[$r->holder][$r->currency] = round((float)$r->amt,2); }

        $list = [];
        $companyUsd = 0.0;
        foreach ($people as $p) {
            $companyUsd += $p['usd'];
            $list[] = [
                'name'=>$p['name'],'uid'=>$p['uid'],
                'usd'=>round($p['usd'],2),
                'balances'=>['USD'=>round($p['cur']['USD'],2),'UGX'=>round($p['cur']['UGX'],2),'SSP'=>round($p['cur']['SSP'],2)],
                'shortage'=>$shorts[$p['name']] ?? null,
            ];
        }
        usort($list, fn($a,$b)=>$b['usd'] <=> $a['usd']);

        return response()->json([
            'ok'=>true,'view'=>$isOwner?'everyone':'mine','whoami'=>$u->name,
            'company_usd'=>round($companyUsd,2),'people'=>$list,'as_of'=>date('d M Y · H:i'),
        ]);
    }

    /** GET /papi/staff-cash/ledger?person=<holder name> — one person's entries + running balance. */
    public function ledger(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $person = trim((string)$request->query('person',''));
        if ($person === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        // staff can only view their own ledger
        if (! $isOwner && mb_strtolower($person) !== mb_strtolower((string)$u->name)) {
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        }

        $rows = OrderCost::where('tenant_id',$t->id)->where('holder',$person)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id','stage','currency','native_amount','paid_native','rate_to_usd','order_no','category','note','status','created_at']);

        $run = ['USD'=>0.0,'UGX'=>0.0,'SSP'=>0.0]; $usdRun = 0.0;
        $stageLabels = ['cash_in'=>'Cash given','cash_out'=>'Cash out','funding'=>'Cash given','purchase'=>'Bought goods',
            'customer_payment'=>'Customer paid','drawing'=>'Drawing','supplier_payment'=>'Paid supplier','expense'=>'Expense'];
        $out = [];
        foreach ($rows as $c) {
            $isIn = in_array($c->stage,$this->inStages,true);
            $pn = (float)$c->paid_native;
            if (isset($run[$c->currency])) $run[$c->currency] += ($isIn?1:-1) * $pn;
            $rate = (float)$c->rate_to_usd; $usdRun += ($isIn?1:-1) * ($rate>0 ? $pn/$rate : 0);
            $label = $stageLabels[$c->stage] ?? ucfirst(str_replace('_',' ',$c->stage));
            // "one entry, two places" link text
            $link = null;
            if ($c->order_no) $link = 'also on '.$c->order_no.($c->stage==='customer_payment'?' (customer paid)':' cost');
            elseif ($c->category) $link = $c->category;
            $out[] = [
                'id'=>$c->id,'dir'=>$isIn?'in':'out','label'=>$label,'stage'=>$c->stage,
                'currency'=>$c->currency,'native'=>(float)$c->native_amount,'paid'=>$pn,
                'run'=>round($run[$c->currency] ?? 0,2),
                'order_no'=>$c->order_no,'category'=>$c->category,'note'=>$c->note,'link'=>$link,
                'status'=>$c->status,'when'=>$c->created_at?->format('d M H:i'),
            ];
        }
        $out = array_reverse($out); // newest first for display

        $bal = ['USD'=>round($run['USD'],2),'UGX'=>round($run['UGX'],2),'SSP'=>round($run['SSP'],2)];
        return response()->json(['ok'=>true,'person'=>$person,'balances'=>$bal,'usd'=>round($usdRun,2),'entries'=>$out]);
    }

    /** POST /papi/staff-cash/give — owner/manager gives cash to a person (adds to their hand). */
    public function give(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);
        if (! $isOwner) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $t = $u->tenant;

        $person = trim((string)$request->input('person',''));
        if ($person === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);
        $method = trim((string)$request->input('method','Cash')) ?: 'Cash';
        if (mb_strlen($method) > 24) $method = mb_substr($method,0,24);
        $note = trim((string)$request->input('note','')) ?: null;

        // best-effort: link to a staff user with this exact name (so "my cash" works for them)
        $uid = null;
        try { $uid = \App\Models\User::where('tenant_id',$t->id)->where('name',$person)->value('id'); } catch (\Throwable $e) { $uid = null; }

        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        $c = OrderCost::create([
            'tenant_id'=>$t->id,'stage'=>'funding','holder'=>$person,'holder_user_id'=>$uid,
            'currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
            'pay_status'=>'cash','paid_native'=>$amount,'category'=>'Funding / Float',
            'note'=>'Cash given ('.$method.')'.($note?(' — '.$note):''),
            'status'=>'approved','created_by'=>$u->name ?? '',
        ]);
        if ($createdAt) { $c->created_at = $createdAt; $c->save(); }

        return response()->json(['ok'=>true,'id'=>$c->id,'person'=>$person,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd]);
    }

    /** POST /papi/staff-cash/spend — a person spends cash: on an ORDER (purchase) OR an EXPENSE. */
    public function spend(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $person = trim((string)$request->input('person',''));
        if ($person === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        if (! $isOwner && mb_strtolower($person) !== mb_strtolower((string)$u->name))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);

        $cur = strtoupper((string)$request->input('currency','UGX'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);
        $mode = $request->input('mode','order') === 'expense' ? 'expense' : 'order';
        $note = trim((string)$request->input('note','')) ?: null;

        $uid = null;
        try { $uid = \App\Models\User::where('tenant_id',$t->id)->where('name',$person)->value('id'); } catch (\Throwable $e) { $uid = null; }
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        if ($mode === 'order') {
            $orderNo = trim((string)$request->input('order_no','')) ?: null;
            $orderId = $request->input('order_id'); $orderId = is_numeric($orderId) ? (int)$orderId : null;
            $stage = 'purchase'; $category = null;
            if (! $orderNo) return response()->json(['ok'=>false,'error'=>'no_order'],422);
        } else {
            $orderNo = null; $orderId = null; $stage = 'expense';
            $category = trim((string)$request->input('category','')) ?: 'Misc Expense';
        }

        $c = OrderCost::create([
            'tenant_id'=>$t->id,'order_id'=>$orderId,'order_no'=>$orderNo,'stage'=>$stage,
            'currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
            'holder'=>$person,'holder_user_id'=>$uid,'pay_status'=>'cash','paid_native'=>$amount,
            'category'=>$category,'note'=>$note,'status'=>(($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)) ? 'approved' : (((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved')),'created_by'=>$u->name ?? '',
        ]);
        if ($createdAt) { $c->created_at = $createdAt; $c->save(); }

        return response()->json(['ok'=>true,'id'=>$c->id,'person'=>$person,'mode'=>$mode,
            'currency'=>$cur,'amount'=>$amount,'usd'=>$usd,'order_no'=>$orderNo,'category'=>$category]);
    }

    /** POST /papi/staff-cash/receive — a person receives a customer payment (cash up + lands on customer). */
    public function receive(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $person = trim((string)$request->input('person',''));
        if ($person === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        if (! $isOwner && mb_strtolower($person) !== mb_strtolower((string)$u->name))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);

        $customer = trim((string)$request->input('customer','')) ?: 'Customer';
        $phone = trim((string)$request->input('customer_phone','')) ?: null;
        $orderNo = trim((string)$request->input('order_no','')) ?: null;
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $usd = OrderCost::toUsd($amount, $rate);
        $kind = $request->input('kind','settlement') === 'advance' ? 'advance' : 'settlement';
        $method = trim((string)$request->input('method','Cash')) ?: 'Cash';
        if (mb_strlen($method) > 24) $method = mb_substr($method,0,24);

        $uid = null;
        try { $uid = \App\Models\User::where('tenant_id',$t->id)->where('name',$person)->value('id'); } catch (\Throwable $e) { $uid = null; }
        $createdAt = null;
        $cd = trim((string)$request->input('created_date',''));
        if ($cd !== '') { try { $createdAt = \Illuminate\Support\Carbon::parse($cd); } catch (\Throwable $e) { $createdAt = null; } }

        $result = DB::transaction(function () use ($t,$u,$uid,$person,$customer,$phone,$orderNo,$kind,$cur,$amount,$rate,$usd,$method,$createdAt) {
            $cost = OrderCost::create([
                'tenant_id'=>$t->id,'order_id'=>null,'order_no'=>$orderNo,'stage'=>'customer_payment',
                'currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'holder'=>$person,'holder_user_id'=>$uid,'pay_status'=>'cash','paid_native'=>$amount,
                'category'=>'Receipt','note'=>'Payment from '.$customer.($orderNo?(' ('.$orderNo.')'):''),
                'status'=>'approved','created_by'=>$u->name ?? '',
            ]);
            $pay = CustomerPayment::create([
                'tenant_id'=>$t->id,'receipt_no'=>'TMP','customer_name'=>$customer,'customer_phone'=>$phone,
                'order_no'=>$orderNo,'invoice_no'=>null,'kind'=>$kind,'currency'=>$cur,'amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'method'=>$method,'reference'=>null,'receipt_path'=>null,'applied'=>($kind==='settlement'),
                'order_cost_id'=>$cost->id,'holder'=>$person,'holder_user_id'=>$uid,'created_by'=>$u->name ?? '',
            ]);
            $pay->receipt_no = 'RCP-'.str_pad((string)$pay->id, 5, '0', STR_PAD_LEFT);
            if ($createdAt) { $pay->created_at = $createdAt; $cost->created_at = $createdAt; $cost->save(); }
            $pay->save();
            return ['pay_id'=>$pay->id,'receipt_no'=>$pay->receipt_no,'cost_id'=>$cost->id];
        });

        return response()->json(['ok'=>true,'person'=>$person,'customer'=>$customer,'currency'=>$cur,'amount'=>$amount,'usd'=>$usd] + $result);
    }

    /** POST /papi/staff-cash/settle — count a person's cash and record any gap (shortage/overage). */
    public function settle(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $isOwner = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);

        $person = trim((string)$request->input('person',''));
        if ($person === '') return response()->json(['ok'=>false,'error'=>'no_person'],422);
        if (! $isOwner && mb_strtolower($person) !== mb_strtolower((string)$u->name))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);

        $cur = strtoupper((string)$request->input('currency','UGX'));
        $counted = round((float)$request->input('counted',-1), 2);
        if ($counted < 0) return response()->json(['ok'=>false,'error'=>'bad_count'],422);

        // system balance for this person + currency (signed cash actually moved)
        $rows = OrderCost::where('tenant_id',$t->id)->where('holder',$person)->where('currency',$cur)
            ->get(['stage','paid_native']);
        $system = 0.0;
        foreach ($rows as $r) $system += (in_array($r->stage,$this->inStages,true)?1:-1) * (float)$r->paid_native;
        $system = round($system, 2);

        $gap = round($counted - $system, 2); // negative = shortage, positive = overage
        if (abs($gap) < 0.005) {
            return response()->json(['ok'=>true,'settled'=>true,'gap'=>0,'system'=>$system,'counted'=>$counted,'currency'=>$cur]);
        }

        $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3655:5180);
        if ($rate <= 0) $rate = 1;
        $mag = abs($gap);
        $usd = OrderCost::toUsd($mag, $rate);
        $isShort = $gap < 0;
        $note = ($isShort?'Shortage':'Overage').': counted '.$cur.' '.number_format($counted,2)
              .' vs system '.$cur.' '.number_format($system,2).' — gap '.$cur.' '.number_format($mag,2)
              .($isShort?' owed by '.$person.' until explained':'');
        $extra = trim((string)$request->input('note','')); if ($extra !== '') $note .= ' · '.$extra;

        $uid = null;
        try { $uid = \App\Models\User::where('tenant_id',$t->id)->where('name',$person)->value('id'); } catch (\Throwable $e) { $uid = null; }

        $c = OrderCost::create([
            'tenant_id'=>$t->id,'stage'=>$isShort?'cash_out':'cash_in',
            'currency'=>$cur,'native_amount'=>$mag,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
            'holder'=>$person,'holder_user_id'=>$uid,'pay_status'=>'cash','paid_native'=>$mag,
            'category'=>$isShort?'Shortage':'Overage','note'=>$note,'status'=>'approved','created_by'=>$u->name ?? '',
        ]);

        return response()->json(['ok'=>true,'settled'=>false,'kind'=>$isShort?'shortage':'overage',
            'gap'=>$mag,'system'=>$system,'counted'=>$counted,'currency'=>$cur,'id'=>$c->id]);
    }
}
