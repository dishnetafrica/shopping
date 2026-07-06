<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\{Order, OrderCost};
use Illuminate\Http\Request;

/** Book a cost (purchase/freight/delivery) or funding against an order, in native currency. */
class OrderCostController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/book_cost.html');
        if (! is_file($path)) abort(500, 'Book-cost asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    public function ordersPage(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/orders_costs.html');
        if (! is_file($path)) abort(500, 'Orders asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/me/nav — role-aware nav: which Order-Trading tabs this user may see. Read-only. */
    public function nav(Request $request)
    {
        $u = $request->user();
        if (! $u) return response()->json(['ok' => false], 401);
        return response()->json([
            'ok'       => true,
            'name'     => (string) ($u->name ?? ''),
            'is_owner' => method_exists($u, 'isOwnerLike') ? $u->isOwnerLike() : false,
            'role'     => (string) $u->role,
            'menus'    => method_exists($u, 'effectiveMenus') ? $u->effectiveMenus() : [],
        ]);
    }

    /** Resolve a supplier from supplier_id (tenant-verified) or supplier_name (find-or-create). */
    protected function resolveSupplier(Request $request, $t): array
    {
        if ($request->filled('supplier_id')) {
            $s = \App\Models\Supplier::where('tenant_id',$t->id)->find($request->input('supplier_id'));
            if ($s) return [$s->id, $s->name];
        }
        $name = trim((string) $request->input('supplier_name',''));
        if ($name !== '') {
            $s = \App\Models\Supplier::firstOrCreate(['tenant_id'=>$t->id,'name'=>$name]);
            return [$s->id, $s->name];
        }
        return [null, null];
    }

    /** GET /papi/order-costs/orders — open orders to pick from. */
    public function orders(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $per = min(50, max(5, (int)$request->query('per',25)));
        $page = max(1, (int)$request->query('page',1));
        $ordersQ = Order::where('tenant_id',$t->id)->orderByDesc('id');
        $ordersTotal = (clone $ordersQ)->count();
        $orders = $ordersQ->forPage($page,$per)->get(['id','order_no','customer_name','total','location']);
        $me = $request->user();
        $staff = [];
        if ($me->isOwnerLike() || in_array((string)$me->role,['manager'],true)) {
            $staff = \App\Models\User::where('tenant_id',$t->id)
                ->whereIn('role',['owner','manager','purchaser','field','sales','staff'])
                ->get(['id','name','role'])->map(fn($u)=>['id'=>$u->id,'name'=>$u->name,'role'=>$u->role]);
        }
        return response()->json(['ok'=>true,'orders'=>$orders,
            'orders_total'=>$ordersTotal ?? count($orders), 'page'=>$page ?? 1, 'per'=>$per ?? 25,
            'me'=>['id'=>$me->id,'name'=>$me->name,'is_owner'=>$me->isOwnerLike()],
            'staff'=>$staff,
            'default_rates'=>['UGX'=>(float)$t->setting('rate_ugx_per_usd',3700),
                              'SSP'=>(float)$t->setting('rate_ssp_per_usd',5180),'USD'=>1]]);
    }

    /** POST /papi/order-costs — book one cost line. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $cur = strtoupper((string)$request->input('currency','UGX'));
        $native = (float)$request->input('native_amount',0);
        $rate = (float)$request->input('rate_to_usd', $cur==='USD'?1:0);
        if ($native<=0 || $rate<=0) return response()->json(['ok'=>false,'error'=>'bad_amount']);

        $order = null;
        if ($request->filled('order_id')) $order = Order::where('tenant_id',$t->id)->find($request->input('order_id'));

        $path = null;
        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store("receipts/t{$t->id}", 'public');
        }

        $user = $request->user();
        // Holder defaults to the logged-in user (their own cashbook). Owner/manager may name
        // a different holder by passing holder_user_id; otherwise it is the current user.
        $holderUserId = $user->id;
        $holderName   = (string) ($user->name ?? '');
        if (($user->isOwnerLike() || in_array((string)$user->role,['manager'],true)) && $request->filled('holder_user_id')) {
            $hu = \App\Models\User::where('tenant_id',$t->id)->find($request->input('holder_user_id'));
            if ($hu) { $holderUserId = $hu->id; $holderName = (string)$hu->name; }
        } elseif ($request->filled('holder')) {
            // free-text holder still allowed (e.g. "office safe") — no user link
            $holderName = (string)$request->input('holder');
            $holderUserId = null;
        }

        [$supId,$supName] = $this->resolveSupplier($request, $t);
        $stage = (string) $request->input('stage','purchase');
        $payStatus = strtolower((string) $request->input('pay_status','cash')) === 'credit' ? 'credit' : 'cash';
        if (in_array($stage, ['funding','cash_in'], true)) $payStatus = 'cash'; // cash movements are always paid
        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'order_id'=>$order?->id, 'order_no'=>$order?->order_no,
            'supplier_id'=>$supId, 'supplier_name'=>$supName,
            'stage'=>$stage,
            'pay_status'=>$payStatus, 'paid_native'=>($payStatus==='credit' ? 0 : $native),
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>$holderName, 'holder_user_id'=>$holderUserId,
            'receipt_path'=>$path, 'note'=>(string)$request->input('note',''),
            'status'=>((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'=>$user->name ?? '',
        ]);
        return response()->json(['ok'=>true,'id'=>$c->id,'usd'=>$c->usd_amount]);
    }

    /**
     * POST /papi/order-costs/bulk — ONE physical purchase (paid once, one receipt) split
     * across several orders. Writes N order_costs rows sharing rate/holder/receipt/group.
     * The split ALWAYS sums to total_native to the cent (last row absorbs the remainder),
     * so cash reduces by exactly what was paid and no order is over/under-charged.
     * method: qty (weights = typed qty) | value (weights = each order's USD revenue) | even.
     */
    public function bulkStore(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! (bool) $t->setting('order_system_enabled', false)) return response()->json(['ok'=>false,'error'=>'disabled'],404);

        $cur    = strtoupper((string) $request->input('currency','UGX'));
        $rate   = (float) $request->input('rate_to_usd', 0);
        $total  = round((float) $request->input('total_native', 0), 2);
        $method = (string) $request->input('method','qty');
        $stage  = (string) $request->input('stage','purchase');
        $note   = (string) $request->input('note','');

        if ($rate  <= 0) return response()->json(['ok'=>false,'error'=>'bad_rate'],422);
        if ($total <= 0) return response()->json(['ok'=>false,'error'=>'bad_total'],422);
        if (! in_array($method, ['qty','value','even'], true)) $method = 'qty';

        $raw   = $request->input('orders');
        $items = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($items) || ! count($items)) return response()->json(['ok'=>false,'error'=>'no_orders'],422);

        // Load + tenant-validate each order.
        $rows = [];
        foreach ($items as $it) {
            $oid = (int) ($it['order_id'] ?? 0);
            $o = Order::where('tenant_id',$t->id)->find($oid);
            if (! $o) return response()->json(['ok'=>false,'error'=>'bad_order','order_id'=>$oid],422);
            $rows[] = ['order'=>$o, 'qty'=>(float) ($it['qty'] ?? 0)];
        }

        // Weights per method.
        $weights = [];
        foreach ($rows as $r) {
            if ($method === 'even') { $weights[] = 1.0; continue; }
            if ($method === 'qty')  { $weights[] = max(0.0, $r['qty']); continue; }
            $o  = $r['order'];
            $sc = (strpos((string)$o->payment,'sell:')===0) ? substr($o->payment,5) : 'USD';
            $or = $sc==='USD' ? 1.0 : (float) $t->setting('rate_'.strtolower($sc).'_per_usd', $sc==='UGX'?3700:5180);
            $weights[] = $or > 0 ? max(0.0, (float)$o->total / $or) : 0.0;
        }
        $sumW = array_sum($weights);
        if ($sumW <= 0) return response()->json(['ok'=>false,'error'=>'bad_weights'],422);

        // Native share per row; remainder to last row so the sum is EXACT.
        $n = count($rows); $shares = []; $acc = 0.0;
        for ($i=0; $i<$n; $i++) {
            if ($i === $n-1) { $shares[$i] = round($total - $acc, 2); }
            else { $s = round($total * $weights[$i] / $sumW, 2); $shares[$i] = $s; $acc += $s; }
        }

        // One shared receipt.
        $path = null;
        if ($request->hasFile('receipt')) $path = $request->file('receipt')->store("receipts/t{$t->id}", 'public');

        // Holder — identical rules to store().
        $user = $request->user();
        $holderUserId = $user->id; $holderName = (string) ($user->name ?? '');
        if (($user->isOwnerLike() || in_array((string)$user->role,['manager'],true)) && $request->filled('holder_user_id')) {
            $hu = \App\Models\User::where('tenant_id',$t->id)->find($request->input('holder_user_id'));
            if ($hu) { $holderUserId = $hu->id; $holderName = (string)$hu->name; }
        } elseif ($request->filled('holder')) {
            $holderName = (string) $request->input('holder'); $holderUserId = null;
        }

        $status = ((bool) $t->setting('cost_approval', true)) ? 'pending' : 'approved';
        $group  = (string) \Illuminate\Support\Str::uuid();
        [$supId,$supName] = $this->resolveSupplier($request, $t);
        $payStatus = strtolower((string) $request->input('pay_status','cash')) === 'credit' ? 'credit' : 'cash';
        $out = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows,$shares,$t,$stage,$cur,$rate,$holderName,$holderUserId,$path,$note,$status,$group,$user,$supId,$supName,$payStatus,&$out) {
            foreach ($rows as $i => $r) {
                $o = $r['order']; $native = $shares[$i];
                $c = OrderCost::create([
                    'tenant_id'=>$t->id,'order_id'=>$o->id,'order_no'=>$o->order_no,
                    'stage'=>$stage,'currency'=>$cur,'native_amount'=>$native,'rate_to_usd'=>$rate,
                    'usd_amount'=>OrderCost::toUsd($native,$rate),
                    'holder'=>$holderName,'holder_user_id'=>$holderUserId,
                    'receipt_path'=>$path,'note'=>$note,'status'=>$status,
                    'purchase_group_id'=>$group,'created_by'=>$user->name ?? '',
                    'supplier_id'=>$supId,'supplier_name'=>$supName,
                    'pay_status'=>$payStatus,'paid_native'=>($payStatus==='credit' ? 0 : $native),
                ]);
                $out[] = ['order_no'=>$o->order_no,'native'=>$native,'usd'=>(float)$c->usd_amount];
            }
        });

        $totalUsd = round(array_sum(array_map(fn($x)=>$x['usd'],$out)),2);
        return response()->json(['ok'=>true,'group_id'=>$group,'count'=>count($out),
            'rows'=>$out,'total_native'=>$total,'total_usd'=>$totalUsd,'currency'=>$cur]);
    }

    /** POST /papi/orders/quick — create a Juba order with its customer-pays currency. */
    public function quickOrder(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $o = new Order();
        $o->tenant_id     = $t->id;
        $o->status        = 'New';
        $o->channel       = 'pos';
        $o->customer_name = (string)$request->input('customer','');
        $o->customer_phone= preg_replace('/[^0-9]/','',(string)$request->input('phone',''));
        $o->location      = (string)$request->input('location','Juba');
        $o->total         = (float)$request->input('total',0);      // revenue in the sell currency
        $o->save();   // OrderObserver assigns order_no
        // Structured line items (name + qty) power the Buy List. Accept a JSON array in
        // `items`; if it isn't a JSON array, treat `items` as legacy free text.
        $raw  = $request->input('items');
        $list = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (is_array($list) && count($list)) {
            $summary = [];
            foreach ($list as $it) {
                $nm = trim((string)($it['name'] ?? '')); if ($nm === '') continue;
                $qy = (float)($it['qty'] ?? 1); if ($qy <= 0) $qy = 1;
                $un = trim((string)($it['unit'] ?? '')) ?: null;
                \App\Models\TradeOrderItem::create([
                    'tenant_id'=>$t->id,'order_id'=>$o->id,'order_no'=>$o->order_no,
                    'name'=>$nm,'qty'=>$qy,'unit'=>$un,
                ]);
                $summary[] = rtrim(rtrim(number_format($qy,2,'.',''),'0'),'.').'x '.$nm;
            }
            $o->items_text = implode(', ', $summary);
        } else {
            $o->items_text = (string) $raw;   // legacy free text
        }
        // record the sell currency as an order note-setting (kept on the order's payment field)
        $sellCur = strtoupper((string)$request->input('sell_currency','USD'));
        $o->payment = 'sell:'.$sellCur;
        $o->save();
        return response()->json(['ok'=>true,'id'=>$o->id,'order_no'=>$o->order_no,'sell_currency'=>$sellCur]);
    }

    /** GET /papi/orders/{id}/costs — per-order costs + landed cost + margin (all in USD base). */
    public function orderDetail(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $o = Order::where('tenant_id',$t->id)->find($id);
        if (! $o) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $costs = OrderCost::where('tenant_id',$t->id)->where('order_id',$id)
            ->whereNotIn('stage',['funding','supplier_payment'])->orderBy('id')->get();
        $landedUsd = 0.0; $lines = [];
        foreach ($costs as $c) {
            $landedUsd += (float)$c->usd_amount;
            $lines[] = ['stage'=>$c->stage,'currency'=>$c->currency,
                'native'=>(float)$c->native_amount,'rate'=>(float)$c->rate_to_usd,
                'usd'=>(float)$c->usd_amount,'holder'=>$c->holder,'note'=>$c->note,
                'receipt'=>$c->receipt_path ? '/storage/'.$c->receipt_path : null];
        }
        $sellCur = 'USD';
        if (strpos((string)$o->payment,'sell:')===0) $sellCur = substr($o->payment,5);
        // revenue: order total is in sell currency; convert to USD for margin using tenant rate
        $rate = $sellCur==='USD' ? 1 : (float)$t->setting('rate_'.strtolower($sellCur).'_per_usd', $sellCur==='UGX'?3700:5180);
        $revenueUsd = $rate>0 ? round((float)$o->total / $rate, 2) : (float)$o->total;
        return response()->json([
            'ok'=>true,
            'order'=>['order_no'=>$o->order_no,'customer'=>$o->customer_name,'location'=>$o->location,
                'total'=>(float)$o->total,'sell_currency'=>$sellCur,'revenue_usd'=>$revenueUsd],
            'costs'=>$lines,
            'landed_usd'=>round($landedUsd,2),
            'margin_usd'=>round($revenueUsd - $landedUsd,2),
            'margin_pct'=>$revenueUsd>0 ? round(($revenueUsd-$landedUsd)/$revenueUsd*100,1) : 0,
        ]);
    }

    /** GET /panel/approvals — the receipt-approval screen. */
    public function approvalsPage(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        if (! (bool) $user->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/cost_approvals.html');
        if (! is_file($path)) abort(500, 'Approvals asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/order-costs/pending — costs awaiting approval (with receipt). */
    public function pending(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $status = in_array($request->query('status'), ['pending','approved','rejected'], true) ? $request->query('status') : 'pending';
        $rows = OrderCost::where('tenant_id',$t->id)->where('status',$status)
            ->orderByDesc('id')->limit(200)->get()->map(function($c){
                return ['id'=>$c->id,'order_no'=>$c->order_no,'stage'=>$c->stage,
                    'currency'=>$c->currency,'native'=>(float)$c->native_amount,'usd'=>(float)$c->usd_amount,
                    'holder'=>$c->holder,'note'=>$c->note,'by'=>$c->created_by,
                    'receipt'=>$c->receipt_path ? '/storage/'.$c->receipt_path : null,
                    'when'=>$c->created_at?->format('d M H:i')];
            });
        return response()->json(['ok'=>true,'status'=>$status,'costs'=>$rows]);
    }

    /** POST /papi/order-costs/{id}/decide  action=approve|reject */
    public function decide(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        // only owner-like or manager may approve
        $u = $request->user();
        if (! ($u->isOwnerLike() || in_array((string)$u->role, ['manager','accounts'], true))) {
            return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        }
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $action = $request->input('action');
        if ($action==='approve') $c->status='approved';
        elseif ($action==='reject') $c->status='rejected';
        else return response()->json(['ok'=>false,'error'=>'bad_action']);
        $c->save();
        return response()->json(['ok'=>true,'status'=>$c->status]);
    }

    /** Only owner/manager may delete (destructive). */
    private function canDelete($u): bool
    {
        return $u->isOwnerLike() || in_array((string)$u->role, ['manager'], true);
    }

    /** DELETE a single order cost. POST /papi/order-costs/{id}/delete */
    public function deleteCost(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->canDelete($request->user())) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if ($c->receipt_path) { try { \Storage::disk('public')->delete($c->receipt_path); } catch (\Throwable $e) {} }
        $c->delete();
        return response()->json(['ok'=>true]);
    }

    /** DELETE an order and all its costs. POST /papi/orders/{id}/delete */
    public function deleteOrder(Request $request, int $id)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->canDelete($request->user())) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $o = Order::where('tenant_id',$t->id)->find($id);
        if (! $o) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        OrderCost::where('tenant_id',$t->id)->where('order_id',$id)->delete();
        $o->delete();
        return response()->json(['ok'=>true]);
    }
}
