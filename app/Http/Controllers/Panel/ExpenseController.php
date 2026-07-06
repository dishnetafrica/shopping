<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\{ExpenseCategory, OrderCost};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expenses = order_costs with stage='expense' + expense_category. Uses the same
 * per-person cashbook + approval flow, so an expense reduces the payer's cash and
 * flows into Cash Position and the live P&L. Preconfigured categories keep it tidy.
 */
class ExpenseController extends Controller
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
        $path = resource_path('panel/expenses.html');
        if (! is_file($path)) abort(500, 'Expenses asset missing.');
        return response(file_get_contents($path),200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    public function pnlPage(Request $request)
    {
        if ($g = $this->gate($request)) return $g;
        $path = resource_path('panel/pnl.html');
        if (! is_file($path)) abort(500, 'P&L asset missing.');
        return response(file_get_contents($path),200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/expenses/categories — the pick list (auto-seeds defaults on first use). */
    public function categories(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (ExpenseCategory::where('tenant_id',$t->id)->count() === 0) {
            ExpenseCategory::seedFor($t->id);
        }
        $cats = ExpenseCategory::where('tenant_id',$t->id)->where('active',true)
            ->orderBy('sort')->get(['id','name','icon','group']);
        return response()->json(['ok'=>true,'categories'=>$cats,
            'default_rates'=>['UGX'=>(float)$t->setting('rate_ugx_per_usd',3700),
                              'SSP'=>(float)$t->setting('rate_ssp_per_usd',5180),'USD'=>1]]);
    }

    /** POST /papi/expenses — book an expense (a cost with stage=expense). */
    public function store(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $native = (float)$request->input('native_amount',0);
        $rate = (float)$request->input('rate_to_usd', $cur==='USD'?1:0);
        $cat = (string)$request->input('expense_category','Miscellaneous');
        if ($native<=0 || $rate<=0) return response()->json(['ok'=>false,'error'=>'bad_amount']);

        $path = null;
        if ($request->hasFile('receipt')) $path = $request->file('receipt')->store("receipts/t{$t->id}",'public');

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'order_id'=>null, 'order_no'=>null,
            'stage'=>'expense', 'expense_category'=>$cat,
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>(string)($u->name ?? ''), 'holder_user_id'=>$u->id,
            'receipt_path'=>$path, 'note'=>(string)$request->input('note',''),
            'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'=>$u->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }

    /** GET /papi/pnl — live day/period P&L estimate (revenue − landed cost − expenses). */
    public function pnl(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $tid = $t->id;
        $period = $request->query('period','today'); // today|7d|30d|all
        $since = null;
        if ($period==='today') $since = now()->startOfDay();
        elseif ($period==='7d') $since = now()->subDays(7);
        elseif ($period==='30d') $since = now()->subDays(30);

        // Revenue: approved orders' revenue in USD (order.total in sell currency → USD)
        $ordersQ = \App\Models\Order::where('tenant_id',$tid);
        if ($since) $ordersQ->where('created_at','>=',$since);
        $revenueUsd = 0.0; $orderCount = 0;
        foreach ($ordersQ->get(['total','payment','created_at']) as $o) {
            $sellCur = 'USD';
            if (strpos((string)$o->payment,'sell:')===0) $sellCur = substr($o->payment,5);
            $r = $sellCur==='USD' ? 1 : (float)$t->setting('rate_'.strtolower($sellCur).'_per_usd', $sellCur==='UGX'?3700:5180);
            $revenueUsd += $r>0 ? (float)$o->total / $r : 0;
            $orderCount++;
        }

        // Costs: approved order costs (goods/freight/boda) + expenses, in USD
        $costsQ = OrderCost::where('tenant_id',$tid)->where('status','approved')
            ->where('stage','!=','funding');
        if ($since) $costsQ->where('created_at','>=',$since);
        $orderCostUsd = (float) (clone $costsQ)->where('stage','!=','expense')->sum('usd_amount');
        $expenseUsd   = (float) (clone $costsQ)->where('stage','expense')->sum('usd_amount');

        // expense breakdown by category
        $byCat = (clone $costsQ)->where('stage','expense')
            ->select('expense_category', DB::raw('SUM(usd_amount) as usd'))
            ->groupBy('expense_category')->orderByDesc('usd')->get();

        $grossProfit = $revenueUsd - $orderCostUsd;   // trading margin
        $netProfit   = $grossProfit - $expenseUsd;    // after running expenses

        return response()->json([
            'ok'=>true, 'period'=>$period, 'base'=>'USD',
            'revenue'=>round($revenueUsd,2),
            'order_costs'=>round($orderCostUsd,2),
            'gross_profit'=>round($grossProfit,2),
            'expenses'=>round($expenseUsd,2),
            'net_profit'=>round($netProfit,2),
            'margin_pct'=>$revenueUsd>0 ? round($netProfit/$revenueUsd*100,1) : 0,
            'orders'=>$orderCount,
            'expense_by_category'=>$byCat,
            'note'=>'Running estimate from approved orders & costs — reconcile against Books.',
        ]);
    }
}
