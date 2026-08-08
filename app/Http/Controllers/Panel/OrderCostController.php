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
            // E3.4 — one FX source (unifies the old 3700 UGX default with the accounting rate).
            'default_rates'=>['UGX'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX'),
                              'SSP'=>\App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP'),'USD'=>1]]);
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

        /* v4.41 — a purchase recorded here used to carry no item at all: one row whose only
           description was a free-text note the form auto-filled with "Purchase from <supplier>".
           The Purchases list reads that note, so the supplier's name showed where the item should
           be, and when the box was cleared the list fell back to printing the word "Purchase".
           A product may now be named or picked; quantity and unit cost ride along when given. */
        $itemName = trim((string) $request->input('product', ''));
        $prodId   = (int) $request->input('product_id', 0) ?: null;
        if (! $prodId && $itemName !== '') {
            $prodId = (int) (\App\Models\Product::where('tenant_id', $t->id)->where('name', $itemName)->value('id') ?? 0) ?: null;
        }
        if ($prodId && $itemName === '') {
            $itemName = (string) (\App\Models\Product::where('tenant_id', $t->id)->where('id', $prodId)->value('name') ?? '');
        }
        $note = trim((string) $request->input('note', ''));
        // An item beats the generic auto-note. Anything the user actually typed beats both.
        if ($itemName !== '' && ($note === '' || stripos($note, 'Purchase from') === 0)) {
            $note = $itemName;
        }
        $qty      = (float) $request->input('qty', 0);
        $unitCost = (float) $request->input('unit_cost', 0);

        $c = OrderCost::create([
            'tenant_id'=>$t->id, 'order_id'=>$order?->id, 'order_no'=>$order?->order_no,
            'supplier_id'=>$supId, 'supplier_name'=>$supName,
            'stage'=>$stage,
            'pay_status'=>$payStatus, 'paid_native'=>($payStatus==='credit' ? 0 : $native),
            'currency'=>$cur, 'native_amount'=>$native, 'rate_to_usd'=>$rate,
            'usd_amount'=>OrderCost::toUsd($native,$rate),
            'holder'=>$holderName, 'holder_user_id'=>$holderUserId,
            'receipt_path'=>$path, 'note'=>$note,
            'product_id'=>$prodId,
            'qty'=>$qty > 0 ? $qty : null, 'unit_cost'=>$unitCost > 0 ? $unitCost : null,
            'category'=>$request->filled('category') ? (string)$request->input('category')
                : ($stage === 'purchase' ? 'Purchase' : null),
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
            $sc = $o->sellCurrency(); // E4.5 — typed column, legacy sell: decode as fallback
            $or = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($sc); // E3.4 — one FX source
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
        // Flat-fields convenience (the supplier "Buy product" form): build the lines from up to four
        // product slots — product/qty/unit_cost and product2../product3../product4.. — so several
        // items can be bought in one bill. Slot 1 carries a mapped product_id; extra slots arrive as
        // product NAMES, resolved to their catalogue id here. Existing multi-line callers are untouched.
        if (! is_array($lines) || ! count($lines)) {
            $lines = [];
            foreach (['', '2', '3', '4'] as $sfx) {
                $qty  = (float) $request->input('qty' . $sfx, 0);
                $unit = (float) $request->input('unit_cost' . $sfx, 0);
                if ($qty <= 0 || $unit < 0) continue;
                $pname = trim((string) $request->input('product' . $sfx, ''));
                $pid   = $sfx === '' ? (int) $request->input('product_id', 0) : 0;
                if (! $pid && $pname !== '') {
                    $pid = (int) (\App\Models\Product::where('tenant_id', $t->id)->where('name', $pname)->value('id') ?? 0);
                }
                if (! $pid && $pname === '') continue;
                // v4.41 — slot 1's name never arrives: the form maps the picked product to an id and
                // then drops the display value, so line 1 was written with an empty note and the
                // Purchases list printed the fallback word "Purchase" while lines 2-4 kept their
                // names. Recover it from the id here, so it holds whatever the browser sends.
                if ($pname === '' && $pid) {
                    $pname = (string) (\App\Models\Product::where('tenant_id', $t->id)->where('id', $pid)->value('name') ?? '');
                }
                $lines[] = ['product_id' => $pid ?: null, 'qty' => $qty, 'unit_cost' => $unit, 'name' => $pname];
            }
        }
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
        // E3.4 — one FX source. Previously $ugxPerUsd read the CLIENT display rate (usdUgx); it now
        // uses the accounting rate so a product's cost_price is computed on the same rate as invoices,
        // the cashbook and the ledger. (For UGX purchases the rate is unused; zero-diff there.)
        $ugxPerUsd = \App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX');
        $sspPerUsd = \App\Services\Money\Fx::forTenant($t)->ratePerUsd('SSP');
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

                // v4.64.3 — resolve the NAME to a product when the browser could not.
                //
                // The Buy stock form maps a typed item to an id from a datalist and sends null when
                // the text does not match a key exactly. So "Golden Foil 45cm " with a trailing
                // space, or a name whose product was renamed since, recorded the quantity with no
                // product attached — and from v4.64 that means the goods never reach the warehouse
                // count. The purchase looks perfectly correct on every screen; the stock simply does
                // not move, and nothing says so.
                //
                // The flat-slot path below has always done this. The multi-line path never did.
                // EXACT match only, case and whitespace insensitive. No fuzzy matching: attaching a
                // carton of foil to the wrong product is worse than not attaching it at all.
                if (! $pid && $name !== '') {
                    try {
                        $pid = (int) (\App\Models\Product::withoutGlobalScopes()
                            ->where('tenant_id', $t->id)
                            // Normalised identically to purchase:relink — lowercase, trimmed, inner
                            // whitespace collapsed — so a name does not link on one path and fail on
                            // the other, which would be the most confusing possible outcome.
                            ->whereRaw("lower(regexp_replace(trim(name), '\\s+', ' ', 'g')) = ?",
                                [mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)))])
                            ->value('id') ?? 0) ?: null;
                    } catch (\Throwable $e) {
                        $pid = null;
                    }
                }
                if ($qty <= 0 || $unit < 0) continue;
                $native = round($qty * $unit, 2);
                if ($native <= 0) continue;
                $totalNative += $native;

                $created = OrderCost::create([
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

                // v4.64 — a purchase IS a goods receipt. Until now this recorded only the money, so
                // the shop could say what it spent and not what arrived. Inside the same transaction
                // as the money: a receipt whose stock movement failed to write is worse than no
                // receipt, because the books and the warehouse would disagree with nobody told.
                //
                // Not fatal on its own, though — the ledger is off by default for most tenants, and
                // an inventory error must never be the reason a bill fails to record.
                if (\App\Services\Inventory\PurchaseStock::receivable($pid, $qty)) {
                    try {
                        \App\Services\Inventory\StockReceiver::forPurchase($t, $created, (float) $qty, (string) ($u->name ?? ''));
                    } catch (\Throwable $e) {
                        \Log::warning('purchase stock not received: ' . $e->getMessage());
                    }
                }
                $out[] = ['id'=>(int)$created->id,'product_id'=>$pid,'name'=>$name,'qty'=>$qty,'unit_cost'=>$unit,'native'=>$native];
            }
        });

        if (! count($out)) return response()->json(['ok'=>false,'error'=>'no_valid_lines'],422);
        // v4.35 — every line id, so ONE supplier invoice can be attached to the whole purchase
        // in a single upload rather than photographed again for each line.
        // v4.64.3 — say out loud how many lines will actually move stock. A purchase whose item name
        // matched nothing is recorded correctly as money and moves no goods, and until now the only
        // way to discover that was to count the shelf.
        $linked = count(array_filter($out, fn ($l) => ! empty($l['product_id'])));
        $msg = $linked === count($out)
            ? 'Purchase recorded · ' . $linked . ' line(s), stock updated.'
            : 'Purchase recorded · ' . $linked . ' of ' . count($out) . ' line(s) linked to a product. '
              . (count($out) - $linked) . ' recorded as money only — check the item name matches a product exactly.';

        return response()->json(['ok'=>true,'group_id'=>$group,'count'=>count($out),
            'total_native'=>round($totalNative,2),'currency'=>$cur,'pay_status'=>$payStatus,'lines'=>$out,
            'linked'=>$linked,'message'=>$msg,
            'ids'=>array_values(array_map(fn($l)=>(int)$l['id'], $out)),'evidence_type'=>'purchase']);
    }

    /** GET /papi/purchases — recent purchases grouped by purchase_group_id. */
    public function purchaseList(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $rows = OrderCost::where('tenant_id',$t->id)->where('stage','purchase')
            ->whereNotNull('purchase_group_id')
            ->orderByDesc('id')->limit(400)->get();
        $groups = []; $seenIds = [];
        foreach ($rows as $r) {
            $g = $r->purchase_group_id;
            if (! isset($groups[$g])) $groups[$g] = [
                'group_id'=>$g,'supplier'=>$r->supplier_name,'currency'=>$r->currency,
                'pay_status'=>$r->pay_status,'holder'=>$r->holder,'status'=>$r->status,
                'date'=>optional($r->created_at)->format('d M Y'),'total'=>0.0,'items'=>[]];
            $voided = strtolower((string)$r->status) === 'void';
            if (! $voided) $groups[$g]['total'] += (float)$r->native_amount;   // voided lines don't count toward the total
            $groups[$g]['items'][] = ['id'=>$r->id,'name'=>$r->note,
                // v4.64.3 — the product link, so anything reconciling this can match on the id
                // rather than guessing from a name that carries voucher numbers and typos.
                'product_id'=>$r->product_id===null?null:(int)$r->product_id,
                'qty'=>$r->qty===null?null:(float)$r->qty,'unit_cost'=>$r->unit_cost===null?null:(float)$r->unit_cost,
                'native'=>(float)$r->native_amount,'currency'=>$r->currency,'pay_status'=>$r->pay_status,'status'=>$r->status];
            $seenIds[] = (int)$r->id;
        }

        // v4.35 — how many documents each line carries, so the paperclip can show a count.
        $docCounts = [];
        if (\App\Services\Evidence\EvidenceService::available() && $seenIds) {
            foreach (\App\Services\Evidence\MissingEvidence::COST_SLUGS as $slug) {
                foreach (\App\Services\Evidence\EvidenceService::countsFor((int)$t->id,$slug,$seenIds) as $id=>$n) {
                    $docCounts[(int)$id] = ($docCounts[(int)$id] ?? 0) + (int)$n;
                }
            }
        }
        foreach ($groups as $g2=>$grp) {
            foreach ($grp['items'] as $i=>$it) {
                $groups[$g2]['items'][$i]['docs'] = (int)($docCounts[(int)$it['id']] ?? 0);
            }
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
        // E4.5 — sell currency now lives in a typed column; the legacy `payment='sell:XXX'`
        // marker is kept in lock-step for backward compatibility until the later cleanup.
        $sellCur = strtoupper((string)$request->input('sell_currency','USD'));
        $o->sell_currency = $sellCur;
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
        $sellCur = $o->sellCurrency(); // E4.5 — typed column, legacy sell: decode as fallback
        // revenue: order total is in sell currency; convert to USD for margin using tenant rate
        $rate = \App\Services\Money\Fx::forTenant($t)->ratePerUsd($sellCur); // E3.4 — one FX source
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

        // v4.64 — a voided purchase never happened, so the goods it received come back out. Handled
        // as "the wanted quantity is now zero", which is the same arithmetic as an edit and needs no
        // separate reversal path: StockReceiver posts the difference, whatever direction that is.
        try {
            \App\Services\Inventory\StockReceiver::forPurchase($t, $c, 0.0, (string) ($u->name ?? ''));
        } catch (\Throwable $e) {
            \Log::warning('voided purchase stock not reversed: ' . $e->getMessage());
        }

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
        if (\App\Support\Access::blocks($request->user(), 'order.delete')) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
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
