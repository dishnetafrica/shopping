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
            'status'=>($user->isOwnerLike() || in_array((string)$user->role,['manager'],true)) ? 'approved' : (((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved'),
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

        $status = ($user->isOwnerLike() || in_array((string)$user->role,['manager'],true)) ? 'approved' : (((bool) $t->setting('cost_approval', true)) ? 'pending' : 'approved');
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

    /** GET /panel/purchases — the multi-line Purchase screen. */
    public function purchasePage(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/purchase.html');
        if (! is_file($path)) abort(500, 'Purchase asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** POST /papi/purchases — a full product-line purchase from a supplier (cash or credit).
     *  Each line becomes an OrderCost (stage 'purchase') sharing one purchase_group_id, so it flows
     *  into Cash Position, P&L, ledger and supplier debt. Also updates each product's cost_price. */
    public function purchaseStore(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $u = $request->user();

        $cur  = strtoupper((string) $request->input('currency','UGX'));
        $rate = (float) $request->input('rate_to_usd', $cur==='USD'?1:0);
        if ($rate <= 0) return response()->json(['ok'=>false,'error'=>'bad_rate'],422);

        $raw   = $request->input('lines');
        $lines = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($lines) || ! count($lines)) return response()->json(['ok'=>false,'error'=>'no_lines'],422);

        $payStatus = strtolower((string) $request->input('pay_status','cash')) === 'credit' ? 'credit' : 'cash';
        [$supId,$supName] = $this->resolveSupplier($request, $t);
        if ($payStatus === 'credit' && ! $supId) return response()->json(['ok'=>false,'error'=>'supplier_required_for_credit'],422);

        // Holder (whose cash) — owner/manager may attribute; else self. Only meaningful for cash.
        $isPriv = $u->isOwnerLike() || in_array((string)$u->role,['manager'],true);
        $holderName = (string)($u->name ?? ''); $holderUid = $u->id;
        $reqHolder = trim((string)$request->input('holder',''));
        if ($isPriv && $reqHolder !== '') {
            $holderName = $reqHolder;
            try { $holderUid = \App\Models\User::where('tenant_id',$t->id)->where('name',$reqHolder)->value('id'); } catch (\Throwable $e) { $holderUid = null; }
        }
        $status = $isPriv ? 'approved' : (((bool)$t->setting('cost_approval', true)) ? 'pending' : 'approved');
        $group  = (string) \Illuminate\Support\Str::uuid();

        // Rate to convert a unit cost in the purchase currency to base UGX for the product's cost_price.
        $ugxPerUsd = (float) $t->setting('usdUgx', 3700) ?: 3700;
        $sspPerUsd = (float) $t->setting('rate_ssp_per_usd', 5180) ?: 5180;
        $toUgx = function($amtNative) use ($cur,$ugxPerUsd,$sspPerUsd) {
            if ($cur === 'UGX') return (float)$amtNative;
            if ($cur === 'USD') return (float)$amtNative * $ugxPerUsd;
            if ($cur === 'SSP') return $sspPerUsd>0 ? (float)$amtNative * ($ugxPerUsd/$sspPerUsd) : (float)$amtNative;
            return (float)$amtNative;
        };

        $updateCost = (bool) $request->input('update_cost', true);
        $out = []; $totalNative = 0.0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($lines,$t,$cur,$rate,$holderName,$holderUid,$status,$group,$u,$supId,$supName,$payStatus,$request,$toUgx,$updateCost,&$out,&$totalNative) {
            foreach ($lines as $ln) {
                $qty  = (float) ($ln['qty'] ?? 0);
                $unit = (float) ($ln['unit_cost'] ?? 0);
                $pid  = (int) ($ln['product_id'] ?? 0) ?: null;
                $name = trim((string) ($ln['name'] ?? ''));
                if ($qty <= 0 || $unit < 0) continue;
                $native = round($qty * $unit, 2);
                if ($native <= 0) continue;
                $totalNative += $native;

                OrderCost::create([
                    'tenant_id'=>$t->id, 'stage'=>'purchase',
                    'product_id'=>$pid, 'qty'=>$qty, 'unit_cost'=>$unit,
                    'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
                    'usd_amount'=>OrderCost::toUsd($native,$rate),
                    'holder'=>$payStatus==='cash' ? $holderName : '', 'holder_user_id'=>$payStatus==='cash' ? $holderUid : null,
                    'note'=>$name !== '' ? $name : (string)$request->input('note',''),
                    'category'=>'Purchase',
                    'purchase_group_id'=>$group, 'created_by'=>$u->name ?? '',
                    'supplier_id'=>$supId, 'supplier_name'=>$supName,
                    'pay_status'=>$payStatus, 'paid_native'=>($payStatus==='credit' ? 0 : $native),
                    'status'=>$status,
                ]);

                if ($updateCost && $pid) {
                    try {
                        $p = \App\Models\Product::where('tenant_id',$t->id)->find($pid);
                        if ($p) { $p->cost_price = round($toUgx($unit), 2); $p->save(); }
                    } catch (\Throwable $e) {}
                }
                $out[] = ['product_id'=>$pid,'name'=>$name,'qty'=>$qty,'unit_cost'=>$unit,'native'=>$native];
            }
        });

        if (! count($out)) return response()->json(['ok'=>false,'error'=>'no_valid_lines'],422);
        return response()->json(['ok'=>true,'group_id'=>$group,'count'=>count($out),
            'total_native'=>round($totalNative,2),'currency'=>$cur,'pay_status'=>$payStatus,'lines'=>$out]);
    }

    /** GET /papi/purchases — recent purchases grouped by purchase_group_id. */
    public function purchaseList(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $rows = OrderCost::where('tenant_id',$t->id)->where('stage','purchase')
            ->whereNotNull('purchase_group_id')
            ->orderByDesc('id')->limit(400)->get();
        $groups = [];
        foreach ($rows as $r) {
            $g = $r->purchase_group_id;
            if (! isset($groups[$g])) $groups[$g] = [
                'group_id'=>$g,'supplier'=>$r->supplier_name,'currency'=>$r->currency,
                'pay_status'=>$r->pay_status,'holder'=>$r->holder,'status'=>$r->status,
                'date'=>optional($r->created_at)->format('d M Y'),'total'=>0.0,'items'=>[]];
            $voided = strtolower((string)$r->status) === 'void';
            if (! $voided) $groups[$g]['total'] += (float)$r->native_amount;   // voided lines don't count toward the total
            $groups[$g]['items'][] = ['id'=>$r->id,'name'=>$r->note,
                'qty'=>$r->qty===null?null:(float)$r->qty,'unit_cost'=>$r->unit_cost===null?null:(float)$r->unit_cost,
                'native'=>(float)$r->native_amount,'currency'=>$r->currency,'pay_status'=>$r->pay_status,'status'=>$r->status];
        }
        return response()->json(['ok'=>true,'purchases'=>array_values($groups)]);
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
        $prev = $c->status;
        if ($action==='approve') $c->status='approved';
        elseif ($action==='reject') $c->status='rejected';
        else return response()->json(['ok'=>false,'error'=>'bad_action']);
        $c->save();
        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,$action,$u,['status'=>$prev],['status'=>$c->status],trim((string)$request->input('reason','')) ?: null);
        return response()->json(['ok'=>true,'status'=>$c->status]);
    }

    /** Only owner/manager may delete (destructive). */
    private function canDelete($u): bool
    {
        return $u->isOwnerLike() || in_array((string)$u->role, ['manager'], true);
    }

    /** VOID a single order cost (soft — never physically deleted). POST /papi/order-costs/{id}/delete */
    public function deleteCost(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->canDelete($u)) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at))
            return response()->json(['ok'=>false,'error'=>'period_locked','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);
        $before = ['status'=>$c->status,'currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'stage'=>$c->stage,'note'=>$c->note];
        $c->status = 'void'; $c->voided_at = now(); $c->voided_by = $u->name ?? '';
        $c->void_reason = trim((string)$request->input('reason','')) ?: null;
        $c->save();
        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'void',$u,$before,['status'=>'void'],$c->void_reason);
        return response()->json(['ok'=>true,'voided'=>true]);
    }

    /**
     * EDIT a single purchase line — correct a data-entry mistake. POST /papi/order-costs/{id}/update
     * Owner/manager only; blocked in locked periods; every change is audit-logged.
     * Accepts any of: qty, unit_cost, native_amount, note, pay_status, currency, rate_to_usd.
     * If qty AND unit_cost are supplied, native_amount is recomputed (qty × unit_cost);
     * otherwise a supplied native_amount is used directly. usd_amount and paid_native are kept consistent.
     */
    public function purchaseUpdate(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->canDelete($u)) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $c = OrderCost::where('tenant_id',$t->id)->find($id);
        if (! $c) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if ((string)$c->stage === 'supplier_payment')
            return response()->json(['ok'=>false,'error'=>'not_a_purchase'],422);
        if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at))
            return response()->json(['ok'=>false,'error'=>'period_locked','detail'=>\App\Services\AccountingGuard::lockDate($t->id)],422);

        $before = ['qty'=>$c->qty===null?null:(float)$c->qty,'unit_cost'=>$c->unit_cost===null?null:(float)$c->unit_cost,
            'currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'rate_to_usd'=>(float)$c->rate_to_usd,
            'usd_amount'=>(float)$c->usd_amount,'pay_status'=>$c->pay_status,'note'=>$c->note];

        // Scalar fields
        if ($request->has('note'))     $c->note = trim((string)$request->input('note')) ?: null;
        if ($request->has('currency')) $c->currency = strtoupper((string)$request->input('currency')) ?: $c->currency;
        if ($request->filled('rate_to_usd')) {
            $r = (float)$request->input('rate_to_usd');
            if ($r > 0) $c->rate_to_usd = $r;
        }
        if ($request->has('pay_status')) {
            $ps = strtolower((string)$request->input('pay_status')) === 'credit' ? 'credit' : 'cash';
            $c->pay_status = $ps;
        }

        // Quantity / unit cost / amount
        $hasQty  = $request->has('qty');
        $hasUnit = $request->has('unit_cost');
        if ($hasQty)  $c->qty       = $request->filled('qty') ? max(0,(float)$request->input('qty')) : null;
        if ($hasUnit) $c->unit_cost = $request->filled('unit_cost') ? max(0,(float)$request->input('unit_cost')) : null;

        if ($hasQty && $hasUnit && $c->qty !== null && $c->unit_cost !== null) {
            $native = round((float)$c->qty * (float)$c->unit_cost, 2);
            $c->native_amount = $native;
        } elseif ($request->filled('native_amount')) {
            $c->native_amount = round(max(0,(float)$request->input('native_amount')), 2);
        }

        $native = (float)$c->native_amount;
        $rate   = (float)$c->rate_to_usd;
        $c->usd_amount   = OrderCost::toUsd($native, $rate);
        // Cash lines are paid in full; credit lines carry 0 paid on the purchase row (payments are separate).
        $c->paid_native  = ((string)$c->pay_status === 'credit') ? 0 : $native;
        $c->save();

        $after = ['qty'=>$c->qty===null?null:(float)$c->qty,'unit_cost'=>$c->unit_cost===null?null:(float)$c->unit_cost,
            'currency'=>$c->currency,'native_amount'=>(float)$c->native_amount,'rate_to_usd'=>(float)$c->rate_to_usd,
            'usd_amount'=>(float)$c->usd_amount,'pay_status'=>$c->pay_status,'note'=>$c->note];
        \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'edit',$u,$before,$after,trim((string)$request->input('reason','')) ?: null);
        return response()->json(['ok'=>true,'updated'=>true,'id'=>$c->id,
            'native_amount'=>(float)$c->native_amount,'usd_amount'=>(float)$c->usd_amount,'pay_status'=>$c->pay_status]);
    }

    /** VOID an order and its costs (soft). POST /papi/orders/{id}/delete */
    public function deleteOrder(Request $request, int $id)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->canDelete($u)) return response()->json(['ok'=>false,'error'=>'not_allowed'],403);
        $o = Order::where('tenant_id',$t->id)->find($id);
        if (! $o) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $reason = trim((string)$request->input('reason','')) ?: null;
        $costs = OrderCost::where('tenant_id',$t->id)->where('order_id',$id)->whereNull('voided_at')->get();
        foreach ($costs as $c) {
            if (\App\Services\AccountingGuard::isLocked($t->id, $c->created_at)) continue;
            $c->status='void'; $c->voided_at=now(); $c->voided_by=$u->name ?? ''; $c->void_reason=$reason; $c->save();
            \App\Services\AuditLogger::log($t->id,'order_cost',$c->id,'void',$u,null,['status'=>'void'],$reason);
        }
        \App\Services\AuditLogger::log($t->id,'order',$o->id,'void',$u,['order_no'=>$o->order_no],null,$reason);
        $o->delete();
        return response()->json(['ok'=>true,'voided_costs'=>$costs->count()]);
    }
}
