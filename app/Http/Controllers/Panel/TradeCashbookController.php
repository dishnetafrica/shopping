<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
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
        $rows = $listQ->orderByDesc('id')->forPage($page,$per)->get()->map(function($c){
            $isIn = in_array($c->stage,['funding','cash_in','customer_payment'],true);
            return ['id'=>$c->id,'stage'=>$c->stage,'dir'=>$isIn?'in':'out',
                'currency'=>$c->currency,'native'=>(float)$c->native_amount,'usd'=>(float)$c->usd_amount,
                'holder'=>$c->holder,'note'=>$c->note ?: ($c->expense_category ?: ($c->order_no ?: '')),
                'status'=>$c->status,'when'=>$c->created_at?->format('d M H:i')];
        });

        return response()->json(['ok'=>true,'currency'=>$cur,'balances'=>$bal,
            'view'=>$isOwner?'everyone':'mine','whoami'=>$u->name,
            'entries'=>$rows,'total'=>$total,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per),
            'default_rates'=>['UGX'=>(float)$t->setting('rate_ugx_per_usd',3700),
                              'SSP'=>(float)$t->setting('rate_ssp_per_usd',5180),'USD'=>1]]);
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
            'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'=>$u->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }

    /** POST /papi/trade-cashbook/{id}/delete — owner/manager only. */
    public function delete(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->whereIn('stage',['cash_in','cash_out'])->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $c->delete();
        return response()->json(['ok'=>true]);
    }
}
