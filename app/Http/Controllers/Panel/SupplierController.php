<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Suppliers — who we buy from, what we still owe them, and recording payments. */
class SupplierController extends Controller
{
    /** GET /panel/supplier-debt — the "suppliers we owe" screen. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/supplier_debt.html');
        if (! is_file($path)) abort(500, 'Supplier debt asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/suppliers — this tenant's suppliers. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $suppliers = Supplier::where('tenant_id',$t->id)->orderBy('name')
            ->get(['id','name','phone','pay_term_days','opening_balance','opening_currency'])
            ->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'phone'=>$s->phone,
                'pay_term_days'=>$s->pay_term_days,'opening_balance'=>$s->opening_balance===null?null:(float)$s->opening_balance,'opening_currency'=>$s->opening_currency]);
        return response()->json(['ok'=>true,'suppliers'=>$suppliers]);
    }

    /** POST /papi/suppliers — add (or return existing) a supplier by name. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $name = trim((string) $request->input('name',''));
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_name'],422);

        $s = Supplier::firstOrCreate(
            ['tenant_id'=>$t->id, 'name'=>$name],
            ['phone'=>trim((string)$request->input('phone','')) ?: null,
             'note' =>trim((string)$request->input('note','')) ?: null,
             'pay_term_days'=>$request->filled('pay_term_days') ? max(0,(int)$request->input('pay_term_days')) : null,
             'opening_balance'=>$request->filled('opening_balance') ? (float)$request->input('opening_balance') : null,
             'opening_currency'=>trim((string)$request->input('opening_currency','')) ?: null]
        );
        return response()->json(['ok'=>true,'id'=>$s->id,'name'=>$s->name]);
    }

    /** GET /papi/suppliers/debt — outstanding per supplier per currency (credit − payments). */
    public function debt(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $tid = $t->id;

        $credit = OrderCost::where('tenant_id',$tid)->whereNotNull('supplier_id')
            ->where('pay_status','credit')->where('status','!=','rejected')
            ->select('supplier_id','supplier_name','currency', DB::raw('SUM(native_amount) as owed'))
            ->groupBy('supplier_id','supplier_name','currency')->get();

        $paidRows = OrderCost::where('tenant_id',$tid)->whereNotNull('supplier_id')
            ->where('stage','supplier_payment')->where('status','!=','rejected')
            ->select('supplier_id','currency', DB::raw('SUM(native_amount) as paid'))
            ->groupBy('supplier_id','currency')->get();
        $paid = [];
        foreach ($paidRows as $p) { $paid[$p->supplier_id.'|'.$p->currency] = (float)$p->paid; }

        $out = [];
        // Fold in any opening balances (what you already owed the supplier before this system).
        $openings = Supplier::where('tenant_id',$tid)->whereNotNull('opening_balance')->where('opening_balance','>',0)
            ->get(['id','name','opening_balance','opening_currency']);
        foreach ($openings as $s) {
            $ocur = strtoupper((string)($s->opening_currency ?: 'USD'));
            $key  = $s->id.'|'.$ocur;
            $owed = (float)$s->opening_balance - ($paid[$key] ?? 0);
            if ($owed > 0.005) $out[] = ['supplier_id'=>$s->id,'name'=>$s->name,'currency'=>$ocur,'owed'=>round($owed,2),'opening'=>true];
        }
        foreach ($credit as $c) {
            $owed = (float)$c->owed - ($paid[$c->supplier_id.'|'.$c->currency] ?? 0);
            if ($owed <= 0.005) continue;
            $out[] = ['supplier_id'=>$c->supplier_id,'name'=>$c->supplier_name,
                      'currency'=>$c->currency,'owed'=>round($owed,2)];
        }
        usort($out, fn($a,$b)=>$b['owed'] <=> $a['owed']);
        return response()->json(['ok'=>true,'items'=>$out,'count'=>count($out)]);
    }

    /** POST /papi/suppliers/{id}/pay — record a cash payment to a supplier (reduces cash, clears debt). */
    public function pay(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $s = Supplier::where('tenant_id',$t->id)->find($id);
        if (! $s) return response()->json(['ok'=>false,'error'=>'bad_supplier'],404);

        $cur    = strtoupper((string) $request->input('currency','UGX'));
        $amount = round((float) $request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float) $request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float) $t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3700:5180);
        if ($rate <= 0) $rate = 1;

        $user = $request->user();
        $holderUserId = $user->id; $holderName = (string)($user->name ?? '');
        if (($user->isOwnerLike() || in_array((string)$user->role,['manager'],true)) && $request->filled('holder_user_id')) {
            $hu = \App\Models\User::where('tenant_id',$t->id)->find($request->input('holder_user_id'));
            if ($hu) { $holderUserId = $hu->id; $holderName = (string)$hu->name; }
        }

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'order_id'=>null, 'order_no'=>null,
            'supplier_id'=>$s->id, 'supplier_name'=>$s->name,
            'stage'=>'supplier_payment', 'currency'=>$cur,
            'native_amount'=>$amount, 'rate_to_usd'=>$rate, 'usd_amount'=>OrderCost::toUsd($amount,$rate),
            'holder'=>$holderName, 'holder_user_id'=>$holderUserId,
            'pay_status'=>'cash', 'paid_native'=>$amount,
            'note'=>'Paid '.$s->name, 'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'=>$user->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }
}
