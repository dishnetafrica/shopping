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
            // E3.4 — one FX source (unifies the old 3700 UGX default with the accounting rate).
            'default_rates'=>['UGX'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX'),
                              'SSP'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP'),'USD'=>1]]);
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

        // Optional order attachment. If order(s) are given, book this as an ORDER cost (so it
        // counts in that order's margin and as a trading cost in P&L — not as flat overhead).
        // Comma-separated order numbers split the amount evenly across them.
        $orderRaw = trim((string)$request->input('order_no',''));
        if ($orderRaw !== '') {
            $nos = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $orderRaw))));
            $orders = [];
            foreach ($nos as $no) {
                $o = \App\Models\Order::where('tenant_id',$t->id)->where('order_no',$no)->first();
                if ($o) $orders[] = $o;
            }
            if (! count($orders)) return response()->json(['ok'=>false,'error'=>'order_not_found','detail'=>$orderRaw],422);

            // Map the logistics category to an order-cost stage; anything else → generic order_cost.
            $stageMap = ['bus freight'=>'freight_bus','boda / delivery'=>'delivery_boda','boda/delivery'=>'delivery_boda','transport / fuel'=>'transport','transport/fuel'=>'transport'];
            $stage = $stageMap[mb_strtolower($cat)] ?? 'order_cost';

            $n = count($orders); $acc = 0.0; $out = []; $grpId = $n>1 ? (string)\Illuminate\Support\Str::uuid() : null;
            foreach ($orders as $i => $o) {
                $share = ($i === $n-1) ? round($native - $acc, 2) : round($native / $n, 2);
                $acc += $share;
                $c = OrderCost::create([
                    'tenant_id'=>$t->id, 'order_id'=>$o->id, 'order_no'=>$o->order_no,
                    'stage'=>$stage, 'expense_category'=>$cat,
                    'currency'=>$cur, 'native_amount'=>$share, 'rate_to_usd'=>$rate,
                    'usd_amount'=>OrderCost::toUsd($share,$rate),
                    'holder'=>(string)($u->name ?? ''), 'holder_user_id'=>$u->id,
                    'receipt_path'=>$path, 'note'=>(string)$request->input('note',''),
                    'purchase_group_id'=>$grpId,
                    'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
                    'created_by'=>$u->name ?? '',
                ]);
                $out[] = ['order_no'=>$o->order_no,'native'=>$share,'usd'=>(float)$c->usd_amount];
            }
            $usd = round(array_sum(array_map(fn($x)=>$x['usd'],$out)),2);
            return response()->json(['ok'=>true,'attached'=>count($out),'orders'=>$out,'usd'=>$usd,'split'=>$n>1]);
        }

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
        $fx = \App\Services\Money\Fx::forTenant($t); // E3.4 — one FX source (hoisted out of the loop)
        foreach ($ordersQ->get(['total','payment','sell_currency','created_at']) as $o) {
            $sellCur = $o->sellCurrency(); // E4.5 — typed column, legacy sell: decode as fallback
            $r = $fx->ratePerUsd($sellCur);
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

    /** GET /papi/expenses/list — recent expenses (paginated). */
    public function list(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        $per = min(50, max(5, (int)$request->query('per',20)));
        $page = max(1, (int)$request->query('page',1));

        $q = OrderCost::where('tenant_id',$t->id)->where('stage','expense');
        // staff see only their own expenses; owner/manager see all
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true))) {
            $q->where(function($w) use ($u){
                $w->where('holder_user_id',$u->id)->orWhere('holder',$u->name);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page,$per)->get()->map(fn($c)=>[
            'id'=>$c->id,'category'=>$c->expense_category,'currency'=>$c->currency,
            'native'=>(float)$c->native_amount,'usd'=>(float)$c->usd_amount,
            'holder'=>$c->holder,'status'=>$c->status,'note'=>$c->note,
            'receipt'=>$c->receipt_path ? '/storage/'.$c->receipt_path : null,
            'when'=>$c->created_at?->format('d M H:i'),
        ]);
        return response()->json(['ok'=>true,'expenses'=>$rows,'total'=>$total,
            'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per)]);
    }

    /** POST /papi/expenses/{id}/delete — owner/manager only. */
    public function deleteExpense(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! ($u->isOwnerLike() || in_array((string)$u->role,['manager'],true)))
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->where('stage','expense')->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at))
            return response()->json(['ok'=>false,'error'=>'period_locked','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);
        $before = ['status'=>$c->status,'currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'note'=>$c->note];
        $c->status='void'; $c->voided_at=now(); $c->voided_by=$u->name ?? '';
        $c->void_reason=trim((string)$request->input('reason','')) ?: null; $c->save();
        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'void',$u,$before,['status'=>'void'],$c->void_reason);
        return response()->json(['ok'=>true,'voided'=>true]);
    }
}
