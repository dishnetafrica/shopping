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

    /** GET /panel/suppliers — full supplier management screen. */
    public function manage(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/suppliers.html');
        if (! is_file($path)) abort(500, 'Suppliers asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/suppliers — this tenant's suppliers.
     *  ?full=1 → paginated management list (page/per/q/search + computed owed). Otherwise the
     *  lightweight name list other screens use. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);

        if (! $request->boolean('full')) {
            $suppliers = Supplier::where('tenant_id',$t->id)->orderBy('name')
                ->get(['id','name','phone','pay_term_days','opening_balance','opening_currency'])
                ->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'phone'=>$s->phone,
                    'pay_term_days'=>$s->pay_term_days,'opening_balance'=>$s->opening_balance===null?null:(float)$s->opening_balance,'opening_currency'=>$s->opening_currency]);
            return response()->json(['ok'=>true,'suppliers'=>$suppliers]);
        }

        // Full management list — paginated + searchable + owed balance.
        $q    = trim((string) $request->input('q',''));
        $per  = min(100, max(5, (int) $request->input('per', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $base = Supplier::where('tenant_id',$t->id);
        if ($q !== '') {
            $base->where(function($w) use ($q){
                foreach (['name','business_name','contact_name','phone','email','tax_number','contact_id'] as $col)
                    $w->orWhere($col,'like','%'.$q.'%');
            });
        }
        $total = (clone $base)->count();
        $rows  = $base->orderBy('name')->forPage($page,$per)->get();

        // owed per supplier (credit purchases + opening − supplier payments), summed to USD for a single figure
        $owedUsd = $this->owedBySupplierUsd($t->id);

        $items = $rows->map(function($s) use ($owedUsd) {
            return [
                'id'=>$s->id,
                'contact_id'=>$s->contact_id,
                'business_name'=>$s->business_name ?: $s->name,
                'contact_name'=>$s->contact_name,
                'name'=>$s->name,
                'phone'=>$s->phone,
                'email'=>$s->email,
                'tax_number'=>$s->tax_number,
                'address'=>$s->address,
                'pay_term_days'=>$s->pay_term_days,
                'opening_balance'=>$s->opening_balance===null?null:(float)$s->opening_balance,
                'opening_currency'=>$s->opening_currency,
                'active'=>(bool)($s->active ?? true),
                'added_on'=>optional($s->created_at)->format('d M Y'),
                'owed_usd'=>round($owedUsd[$s->id] ?? 0, 2),
            ];
        });
        return response()->json(['ok'=>true,'suppliers'=>$items,'total'=>$total,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per)]);
    }

    /** Helper: net owed (USD) per supplier = credit purchases + opening − supplier payments. */
    protected function owedBySupplierUsd(int $tid): array
    {
        // Single source of truth, shared with the supplier LIST screen so detail and list can never
        // disagree (the list previously summed gross credit bills and ignored the separate payment rows).
        return \App\Services\Finance\PayablesCalculator::bySupplierUsd($tid);
    }

    /** GET /papi/suppliers/{id} — one supplier + recent purchases & payments (the view page). */
    public function show(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $s = Supplier::where('tenant_id',$t->id)->find($id);
        if (! $s) return response()->json(['ok'=>false,'error'=>'not_found'],404);

        $purchases = OrderCost::where('tenant_id',$t->id)->where('supplier_id',$id)
            ->where('stage','!=','supplier_payment')->orderByDesc('id')->limit(100)
            ->get(['id','order_no','note','stage','currency','native_amount','qty','unit_cost','rate_to_usd','pay_status','status','created_at'])
            ->map(fn($c)=>['id'=>$c->id,'ref'=>$c->order_no ?: ('#'.$c->id),'note'=>$c->note,'stage'=>$c->stage,
                'currency'=>$c->currency,'amount'=>(float)$c->native_amount,
                'qty'=>$c->qty===null?null:(float)$c->qty,'unit_cost'=>$c->unit_cost===null?null:(float)$c->unit_cost,
                'rate_to_usd'=>$c->rate_to_usd===null?null:(float)$c->rate_to_usd,'pay_status'=>$c->pay_status,
                'status'=>$c->status,'date'=>optional($c->created_at)->format('d M Y')]);
        $payments = OrderCost::where('tenant_id',$t->id)->where('supplier_id',$id)
            ->where('stage','supplier_payment')->orderByDesc('id')->limit(100)
            ->get(['id','currency','native_amount','holder','status','created_at'])
            ->map(fn($c)=>['currency'=>$c->currency,'amount'=>(float)$c->native_amount,'by'=>$c->holder,
                'status'=>$c->status,'date'=>optional($c->created_at)->format('d M Y')]);

        return response()->json(['ok'=>true,'supplier'=>[
            'id'=>$s->id,'contact_id'=>$s->contact_id,'business_name'=>$s->business_name ?: $s->name,
            'contact_name'=>$s->contact_name,'name'=>$s->name,'phone'=>$s->phone,'email'=>$s->email,
            'tax_number'=>$s->tax_number,'address'=>$s->address,'pay_term_days'=>$s->pay_term_days,
            'opening_balance'=>$s->opening_balance===null?null:(float)$s->opening_balance,'opening_currency'=>$s->opening_currency,
            'active'=>(bool)($s->active ?? true),
        ],'purchases'=>$purchases,'payments'=>$payments,'owed_usd'=>round($this->owedBySupplierUsd($t->id)[$s->id] ?? 0,2)]);
    }

    /** POST /papi/suppliers/{id} — update a supplier. */
    public function update(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $s = Supplier::where('tenant_id',$t->id)->find($id);
        if (! $s) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $name = trim((string)$request->input('name', $request->input('business_name','')));
        if ($name !== '') $s->name = $name;
        foreach (['business_name'=>'string','contact_name'=>'string','phone'=>'string','email'=>'string',
                  'tax_number'=>'string','address'=>'string','note'=>'string','contact_id'=>'string','opening_currency'=>'string'] as $f=>$_) {
            if ($request->has($f)) $s->$f = trim((string)$request->input($f)) ?: null;
        }
        if ($request->has('pay_term_days'))   $s->pay_term_days = $request->filled('pay_term_days') ? max(0,(int)$request->input('pay_term_days')) : null;
        if ($request->has('opening_balance')) $s->opening_balance = $request->filled('opening_balance') ? (float)$request->input('opening_balance') : null;
        if ($request->has('active'))          $s->active = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
        $s->save();
        return response()->json(['ok'=>true,'id'=>$s->id]);
    }

    /** POST /papi/suppliers/{id}/delete — remove a supplier (blocked if it has purchases/payments). */
    public function destroy(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $u = $request->user();
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true))) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        $s = Supplier::where('tenant_id',$t->id)->find($id);
        if (! $s) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $used = OrderCost::where('tenant_id',$t->id)->where('supplier_id',$id)->exists();
        if ($used) {
            // Don't destroy financial history — deactivate instead.
            $s->active = false; $s->save();
            return response()->json(['ok'=>true,'deactivated'=>true,'note'=>'has_history']);
        }
        $s->delete();
        return response()->json(['ok'=>true,'deleted'=>true]);
    }

    /** POST /papi/suppliers — add (or return existing) a supplier by name; enriches on create. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $name = trim((string) $request->input('name', $request->input('business_name','')));
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_name'],422);

        $existing = Supplier::where('tenant_id',$t->id)->where('name',$name)->first();
        $wasNew = ! $existing;
        $s = $existing ?: new Supplier(['tenant_id'=>$t->id,'name'=>$name]);

        // set/enrich fields (create OR fill blanks on an existing one)
        foreach (['business_name','contact_name','phone','email','tax_number','address','note','opening_currency'] as $f) {
            $v = trim((string)$request->input($f,''));
            if ($v !== '') $s->$f = $v;
        }
        if ($request->filled('pay_term_days'))   $s->pay_term_days   = max(0,(int)$request->input('pay_term_days'));
        if ($request->filled('opening_balance')) $s->opening_balance = (float)$request->input('opening_balance');
        if ($s->active === null) $s->active = true;
        if (! $s->contact_id) {
            $n = Supplier::where('tenant_id',$t->id)->count() + 1;
            $s->contact_id = 'SUP-'.str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        }
        $s->save();
        return response()->json(['ok'=>true,'id'=>$s->id,'name'=>$s->name,'contact_id'=>$s->contact_id,'created'=>$wasNew]);
    }

    /** GET /papi/suppliers/debt — outstanding per supplier per currency (credit − payments). */
    public function debt(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $tid = $t->id;

        $credit = OrderCost::where('tenant_id',$tid)->whereNotNull('supplier_id')
            ->where('pay_status','credit')->whereNotIn('status',['rejected','void'])
            ->select('supplier_id','supplier_name','currency', DB::raw('SUM(native_amount) as owed'))
            ->groupBy('supplier_id','supplier_name','currency')->get();

        $paidRows = OrderCost::where('tenant_id',$tid)->whereNotNull('supplier_id')
            ->where('stage','supplier_payment')->whereNotIn('status',['rejected','void'])
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
        if (\App\Support\Access::blocks($request->user(), 'supplier.pay')) return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $s = Supplier::where('tenant_id',$t->id)->find($id);
        if (! $s) return response()->json(['ok'=>false,'error'=>'bad_supplier'],404);

        $cur    = strtoupper((string) $request->input('currency','UGX'));
        $amount = round((float) $request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float) $request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($cur); // E3.4 — one FX source
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
