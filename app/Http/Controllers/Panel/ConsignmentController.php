<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentStock;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use App\Services\Logistics\ShipmentService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consignment = one shipment/AWB that carries MANY customer orders (Kampala -> Juba).
 * Jayesh builds the daily consignment from ready orders and dispatches under one AWB
 * (custody handled by the existing ShipmentController::action). On arrival, Asish ticks
 * each order delivered from the manifest. Loose stock / Juba order creation is Phase 2.
 */
class ConsignmentController extends Controller
{
    public function __construct(protected ShipmentService $shipments) {}

    /** Statuses that still need shipping (candidates for a consignment). */
    private const TERMINAL = ['Delivered', 'Cancelled', 'Returned', 'Rejected'];

    private function priv($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /panel/consignment — the mobile-first management screen. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/consignment.html');
        if (! is_file($path)) abort(500, 'Consignment asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** GET /papi/consignments — list consignments (shipments) with manifest counts. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $rows = Shipment::where('tenant_id', $t->id)->orderByDesc('id')->limit(200)->get();
        $counts = ShipmentOrder::where('tenant_id', $t->id)
            ->select('shipment_id', DB::raw('COUNT(*) as n'),
                     DB::raw("SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as done"))
            ->groupBy('shipment_id')->get()->keyBy('shipment_id');

        $out = $rows->map(function ($s) use ($counts) {
            $c = $counts->get($s->id);
            $n = $c ? (int) $c->n : 0;
            // legacy single-order shipments still count as 1 order on the manifest view
            if ($n === 0 && $s->order_id) $n = 1;
            return [
                'id' => $s->id, 'number' => $s->shipment_number, 'status' => $s->status,
                'route' => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
                'transport' => $s->transport_company, 'bus' => $s->bus_number,
                'orders' => $n, 'delivered' => $c ? (int) $c->done : 0,
                'boxes_sent' => $s->boxes_sent, 'agent' => $s->destination_agent_name,
                'when' => optional($s->created_at)->format('d M H:i'),
            ];
        });
        return response()->json(['ok' => true, 'consignments' => $out]);
    }

    /** GET|POST /papi/consignment-create — a standalone Kampala→Juba consignment (no single order). */
    public function create(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $s = $this->shipments->create([
            'tenant_id'               => $t->id,
            'origin_city'             => trim((string) $request->input('origin_city', 'Kampala')) ?: 'Kampala',
            'destination_city'        => trim((string) $request->input('destination_city', 'Juba')) ?: 'Juba',
            'transport_company'       => trim((string) $request->input('transport_company', '')) ?: null,
            'bus_number'              => trim((string) $request->input('bus_number', '')) ?: null,
            'driver_phone'            => trim((string) $request->input('driver_phone', '')) ?: null,
            'destination_agent_name'  => trim((string) $request->input('destination_agent_name', '')) ?: null,
            'destination_agent_phone' => trim((string) $request->input('destination_agent_phone', '')) ?: null,
            'notes'                   => trim((string) $request->input('notes', '')) ?: null,
            'actor_name'              => (string) ($u->name ?? 'owner'),
        ]);

        // Carry forward: pull the UNSENT part of a previous consignment's stock requests
        // (requested - sent) into this new one as fresh requests. This is how "what we didn't
        // send Asish yesterday" automatically shows up on the next load.
        $carried = 0;
        $fromId = (int) $request->input('carry_from', 0);
        if ($fromId) {
            $src = Shipment::where('tenant_id', $t->id)->find($fromId);
            if ($src) {
                foreach (ConsignmentStock::where('tenant_id', $t->id)->where('shipment_id', $src->id)->get() as $k) {
                    $short = $k->shortfall();
                    if ($short > 0.0001) {
                        ConsignmentStock::create([
                            'tenant_id' => $t->id, 'shipment_id' => $s->id,
                            'product_id' => $k->product_id, 'name' => $k->name,
                            'qty_requested' => $short, 'qty_sent' => 0, 'qty_sold' => 0,
                            'unit_price' => $k->unit_price, 'unit_cost' => $k->unit_cost, 'currency' => $k->currency,
                            'note' => 'Carried over (unsent) from ' . ($src->shipment_number ?: ('#' . $src->id)),
                        ]);
                        $carried++;
                    }
                }
            }
        }
        return response()->json(['ok' => true, 'id' => $s->id, 'number' => $s->shipment_number, 'carried' => $carried]);
    }

    /** GET /papi/consignment-carry-source — the most recent consignment with unsent requests (for the carry-forward prompt). */
    public function carrySource(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $rows = ConsignmentStock::where('tenant_id', $t->id)
            ->whereColumn('qty_requested', '>', 'qty_sent')
            ->orderByDesc('shipment_id')->get()->groupBy('shipment_id');
        if ($rows->isEmpty()) return response()->json(['ok' => true, 'source' => null]);
        $sid = (int) $rows->keys()->first();
        $s = Shipment::where('tenant_id', $t->id)->find($sid);
        $lines = $rows->get($sid);
        $items = $lines->sum(fn ($k) => $k->shortfall() > 0 ? 1 : 0);
        return response()->json(['ok' => true, 'source' => [
            'id' => $sid, 'number' => $s->shipment_number ?? ('#' . $sid), 'items' => $items,
        ]]);
    }

    /** GET /papi/consignment?id= — one consignment + its manifest. */
    public function show(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $lines = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get();
        $orderIds = $lines->pluck('order_id')->all();
        $orders = $orderIds ? Order::whereIn('id', $orderIds)->get()->keyBy('id') : collect();

        $manifest = $lines->map(function ($ln) use ($orders) {
            $o = $orders->get($ln->order_id);
            return [
                'line_id' => $ln->id, 'order_id' => $ln->order_id,
                'order_no' => $o->order_no ?? ('#' . $ln->order_id),
                'customer' => $o->customer_name ?? '', 'phone' => $o->customer_phone ?? '',
                'items' => $o ? ($o->items_text ?: '') : '', 'total' => $o ? (float) $o->total : 0,
                'location' => $o->location ?? '', 'order_status' => $o->status ?? '',
                'line_status' => $ln->status, 'delivered_at' => optional($ln->delivered_at)->format('d M H:i'),
                'note' => $ln->note,
            ];
        });

        $stockRows = ConsignmentStock::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get();
        $stock = $stockRows->map(fn ($k) => [
            'id' => $k->id, 'name' => $k->name,
            'requested' => (float) $k->qty_requested, 'sent' => (float) $k->qty_sent,
            'received' => $k->qty_received === null ? null : (float) $k->qty_received,
            'sold' => (float) $k->qty_sold, 'short' => $k->shortfall(), 'remaining' => $k->remaining(),
            'unit_price' => $k->unit_price === null ? null : (float) $k->unit_price,
            'unit_cost' => $k->unit_cost === null ? null : (float) $k->unit_cost,
            'revenue' => (float) $k->sold_amount, 'cost' => $k->costOfSold(), 'margin' => $k->margin(),
            'currency' => $k->currency,
        ]);
        $market = [
            'revenue' => round($stockRows->sum(fn ($k) => (float) $k->sold_amount), 2),
            'cost' => round($stockRows->sum(fn ($k) => $k->costOfSold()), 2),
            'margin' => round($stockRows->sum(fn ($k) => $k->margin()), 2),
        ];

        return response()->json(['ok' => true, 'consignment' => [
            'id' => $s->id, 'number' => $s->shipment_number, 'status' => $s->status, 'token' => $s->token,
            'route' => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
            'origin' => $s->origin_city, 'destination' => $s->destination_city,
            'transport' => $s->transport_company, 'bus' => $s->bus_number, 'driver_phone' => $s->driver_phone,
            'agent' => $s->destination_agent_name, 'agent_phone' => $s->destination_agent_phone,
            'boxes_sent' => $s->boxes_sent, 'notes' => $s->notes,
        ], 'manifest' => $manifest, 'stock' => $stock, 'market' => $market]);
    }

    /** GET /papi/consignment-candidates?id= — ready orders not already on this consignment. */
    public function candidates(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $sid = (int) $request->query('id');
        $already = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $sid)->pluck('order_id')->all();
        $q = trim((string) $request->query('q', ''));

        $orders = Order::where('tenant_id', $t->id)
            ->whereNotIn('status', self::TERMINAL)
            ->when($already, fn ($w) => $w->whereNotIn('id', $already))
            ->when($q !== '', function ($w) use ($q) {
                $w->where(fn ($x) => $x->where('order_no', 'like', "%$q%")
                    ->orWhere('customer_name', 'like', "%$q%")
                    ->orWhere('customer_phone', 'like', "%$q%"));
            })
            ->orderByDesc('id')->limit(100)
            ->get(['id', 'order_no', 'customer_name', 'customer_phone', 'items_text', 'total', 'status', 'location']);

        return response()->json(['ok' => true, 'orders' => $orders->map(fn ($o) => [
            'id' => $o->id, 'order_no' => $o->order_no, 'customer' => $o->customer_name,
            'phone' => $o->customer_phone, 'items' => $o->items_text, 'total' => (float) $o->total,
            'status' => $o->status, 'location' => $o->location,
        ])]);
    }

    /** POST /papi/consignment-add-order — attach an order to the consignment. */
    public function addOrder(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'consignment_not_found'], 404);

        $oid = (int) $request->input('order_id');
        $order = $oid
            ? Order::where('tenant_id', $t->id)->find($oid)
            : Order::where('tenant_id', $t->id)->where('order_no', trim((string) $request->input('order_no', '')))->first();
        if (! $order) return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);

        $line = ShipmentOrder::firstOrCreate(
            ['shipment_id' => $s->id, 'order_id' => $order->id],
            ['tenant_id' => $t->id, 'status' => ShipmentOrder::ON_BOARD]
        );
        return response()->json(['ok' => true, 'line_id' => $line->id, 'already' => ! $line->wasRecentlyCreated]);
    }

    /** POST /papi/consignment-add-all — add EVERY ready order to the consignment in one tap. */
    public function addAll(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'consignment_not_found'], 404);

        $already = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $s->id)->pluck('order_id')->all();
        $ids = Order::where('tenant_id', $t->id)->whereNotIn('status', self::TERMINAL)
            ->when($already, fn ($w) => $w->whereNotIn('id', $already))
            ->orderByDesc('id')->limit(300)->pluck('id');
        $n = 0;
        foreach ($ids as $oid) {
            $line = ShipmentOrder::firstOrCreate(
                ['shipment_id' => $s->id, 'order_id' => $oid],
                ['tenant_id' => $t->id, 'status' => ShipmentOrder::ON_BOARD]
            );
            if ($line->wasRecentlyCreated) $n++;
        }
        return response()->json(['ok' => true, 'added' => $n]);
    }

    /** POST /papi/consignment-deliver-all — mark every still-on-board order delivered (Asish). */
    public function deliverAll(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $lines = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $s->id)
            ->where('status', ShipmentOrder::ON_BOARD)->get();
        $n = 0;
        DB::transaction(function () use ($lines, $u, $t, &$n) {
            foreach ($lines as $line) {
                $line->status = ShipmentOrder::DELIVERED;
                $line->delivered_at = now();
                $line->delivered_by = (string) ($u->name ?? '');
                $line->save();
                $order = Order::where('tenant_id', $t->id)->find($line->order_id);
                if ($order && $order->status !== 'Delivered') { $order->status = 'Delivered'; $order->save(); }
                $n++;
            }
        });
        return response()->json(['ok' => true, 'delivered' => $n]);
    }

    /** POST /papi/consignment-remove-order — detach a manifest line (only if not delivered). */
    public function removeOrder(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $line = ShipmentOrder::where('tenant_id', $t->id)->find((int) $request->input('line_id'));
        if (! $line) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if ($line->status === ShipmentOrder::DELIVERED)
            return response()->json(['ok' => false, 'error' => 'already_delivered'], 422);
        $line->delete();
        return response()->json(['ok' => true, 'removed' => true]);
    }

    /** POST /papi/consignment-line-status — Asish marks a manifest line delivered / returned / on_board. */
    public function setLineStatus(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);

        $line = ShipmentOrder::where('tenant_id', $t->id)->find((int) $request->input('line_id'));
        if (! $line) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $to = strtolower((string) $request->input('status', ''));
        if (! in_array($to, [ShipmentOrder::ON_BOARD, ShipmentOrder::DELIVERED, ShipmentOrder::RETURNED], true))
            return response()->json(['ok' => false, 'error' => 'bad_status'], 422);

        DB::transaction(function () use ($line, $to, $u, $t, $request) {
            $line->status = $to;
            if ($request->has('note')) $line->note = trim((string) $request->input('note')) ?: null;
            $line->delivered_at = $to === ShipmentOrder::DELIVERED ? now() : null;
            $line->delivered_by = $to === ShipmentOrder::DELIVERED ? (string) ($u->name ?? '') : null;
            $line->save();

            // Reflect on the underlying customer order so the customer + reports stay in sync.
            $order = Order::where('tenant_id', $t->id)->find($line->order_id);
            if ($order) {
                if ($to === ShipmentOrder::DELIVERED && $order->status !== 'Delivered') {
                    $order->status = 'Delivered'; $order->save();     // OrderObserver -> customer WhatsApp
                } elseif ($to === ShipmentOrder::RETURNED && $order->status !== 'Returned') {
                    $order->status = 'Returned'; $order->save();
                }
            }
        });

        return response()->json(['ok' => true, 'status' => $line->status]);
    }

    /** POST /papi/consignment-stock-add — add a loose-stock line (a request or direct stock). */
    public function addStock(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'consignment_not_found'], 404);

        // Prefer a catalogue product (the usual case): links product_id and fills name/cost/price
        // from the product. Free-text name still works for the rare true "loose" item.
        $pid  = (int) $request->input('product_id', 0) ?: null;
        $prod = $pid ? \App\Models\Product::where('tenant_id', $t->id)->find($pid) : null;
        if (! $prod) $pid = null;

        $name = trim((string) $request->input('name', ''));
        if ($name === '' && $prod) $name = (string) $prod->name;
        if ($name === '') return response()->json(['ok' => false, 'error' => 'name_required'], 422);

        $unitCost = $request->filled('unit_cost')
            ? (float) $request->input('unit_cost')
            : ($prod && $prod->cost_price !== null ? (float) $prod->cost_price : null);
        $unitPrice = $request->filled('unit_price')
            ? (float) $request->input('unit_price')
            : ($prod ? (float) $prod->price : null);

        $k = ConsignmentStock::create([
            'tenant_id' => $t->id, 'shipment_id' => $s->id, 'product_id' => $pid, 'name' => $name,
            'qty_requested' => max(0, (float) $request->input('qty_requested', 0)),
            'qty_sent'      => max(0, (float) $request->input('qty_sent', 0)),
            'unit_price'    => $unitPrice,
            'unit_cost'     => $unitCost,
            'currency'      => strtoupper((string) $request->input('currency', '')) ?: null,
        ]);
        return response()->json(['ok' => true, 'id' => $k->id]);
    }

    /** POST /papi/consignment-stock-update — set any of requested/sent/received/unit_price/name on a line. */
    public function updateStock(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $k = ConsignmentStock::where('tenant_id', $t->id)->find((int) $request->input('line_id'));
        if (! $k) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $receivedBefore = (float) ($k->qty_received ?? 0);   // E6.2 — track the received delta
        if ($request->has('name') && trim((string) $request->input('name')) !== '') $k->name = trim((string) $request->input('name'));
        foreach (['qty_requested', 'qty_sent', 'qty_received', 'unit_price', 'unit_cost'] as $f) {
            if ($request->has($f)) {
                $nullable = in_array($f, ['qty_received', 'unit_price', 'unit_cost'], true);
                $k->$f = $request->input($f) === '' ? ($nullable ? null : 0) : max(0, (float) $request->input($f));
            }
        }
        if ($request->has('currency')) $k->currency = strtoupper((string) $request->input('currency')) ?: null;
        $k->save();

        // E6.2 — dual-write the ONE stock ledger when received qty changes: goods arriving in Juba are
        // stock IN (a later downward correction is an ADJUST). Gated on stock_ledger, best-effort, so
        // the existing edit path is untouched and nothing reads the ledger yet (E6.4).
        if ((bool) $t->setting('stock_ledger', false)) {
            $receivedAfter = (float) ($k->qty_received ?? 0);
            $delta = round($receivedAfter - $receivedBefore, 3);
            if (abs($delta) > 0.0001) {
                try {
                    $sl = \App\Services\Inventory\StockLedger::forTenant($t);
                    $mv = [
                        'product_id' => $k->product_id,
                        'sku'        => null,
                        'location'   => \App\Services\Inventory\StockLedger::LOC_JUBA,
                        'qty'        => $delta,
                        'ref_type'   => 'consignment_receive',
                        'ref_id'     => $k->id,
                        'note'       => 'Received: ' . $k->name,
                        'created_by' => (string) ($request->user()->name ?? ''),
                    ];
                    // IN for a positive arrival; a negative correction goes through ADJUST (signed).
                    $delta > 0 ? $sl->receive($mv) : $sl->adjust($mv);
                } catch (\Throwable $e) { /* never break the stock edit path */ }
            }
        }
        return response()->json(['ok' => true, 'short' => $k->shortfall(), 'remaining' => $k->remaining()]);
    }

    /** POST /papi/consignment-stock-remove — delete a loose-stock line (owner/manager). */
    public function removeStock(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $k = ConsignmentStock::where('tenant_id', $t->id)->find((int) $request->input('line_id'));
        if (! $k) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $k->delete();
        return response()->json(['ok' => true, 'removed' => true]);
    }

    /** POST /papi/consignment-stock-sell — Asish sells loose stock in Juba: draws it down AND
     *  creates a market Order (Delivered) so the sale is revenue in the books. */
    public function sellStock(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        $k = ConsignmentStock::where('tenant_id', $t->id)->find((int) $request->input('line_id'));
        if (! $k) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $qty = (float) $request->input('qty', 0);
        if ($qty <= 0) return response()->json(['ok' => false, 'error' => 'bad_qty'], 422);
        if ($qty > $k->remaining() + 0.0001)
            return response()->json(['ok' => false, 'error' => 'not_enough', 'message' => 'Only ' . rtrim(rtrim(number_format($k->remaining(), 2, '.', ''), '0'), '.') . ' left of ' . $k->name . '.'], 422);
        $price = $request->filled('price') ? (float) $request->input('price') : ($k->unit_price !== null ? (float) $k->unit_price : 0);
        $buyer = trim((string) $request->input('buyer', '')) ?: 'Market sale';

        $order = DB::transaction(function () use ($t, $k, $qty, $price, $buyer, $u, $request) {
            $s = Shipment::where('tenant_id', $t->id)->find($k->shipment_id);
            $o = new Order();
            $o->tenant_id     = $t->id;
            $o->status        = 'Delivered';
            $o->channel       = 'market';
            $o->customer_name = $buyer;
            $o->customer_phone= preg_replace('/[^0-9+]/', '', (string) $request->input('phone', ''));
            $o->location      = $s->destination_city ?: 'Juba';
            $o->total         = round($qty * $price, 2);
            $qtyStr           = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $o->items_text    = $qtyStr . ' x ' . $k->name;
            $o->delivered_at  = now();
            $o->delivered_by  = (string) ($u->name ?? '');
            $o->save();   // OrderObserver assigns order_no

            $k->qty_sold = (float) $k->qty_sold + $qty;
            $k->sold_amount = (float) $k->sold_amount + round($qty * $price, 2);   // actual revenue
            $k->save();
            return $o;
        });

        // E6.2 — dual-write the ONE stock ledger: a consignment sale is stock OUT of the Juba position.
        // Gated on stock_ledger (default off) and best-effort, so the existing sale path is untouched;
        // nothing reads the ledger yet (E6.4). Reversible: turn stock_ledger off.
        if ((bool) $t->setting('stock_ledger', false)) {
            try {
                \App\Services\Inventory\StockLedger::forTenant($t)->issue([
                    'product_id' => $k->product_id,
                    'sku'        => null,
                    'location'   => \App\Services\Inventory\StockLedger::LOC_JUBA,
                    'qty'        => $qty,
                    'ref_type'   => 'consignment_sale',
                    'ref_id'     => $order->id,
                    'note'       => 'Market sale: ' . $k->name,
                    'created_by' => (string) ($u->name ?? ''),
                ]);
            } catch (\Throwable $e) { /* never break the sale path */ }
        }

        $saleMargin = $k->unit_cost !== null ? round($qty * ($price - (float) $k->unit_cost), 2) : null;
        return response()->json(['ok' => true, 'order_no' => $order->order_no, 'sold' => $qty,
            'remaining' => $k->remaining(), 'total' => (float) $order->total, 'margin' => $saleMargin]);
    }

    /** POST /papi/consignment-notify — tell every customer still on board their order is on the way
     *  to Juba. Reuses the "In transit" order status (fires the existing customer WhatsApp). */
    public function notify(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $lines = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $s->id)
            ->where('status', ShipmentOrder::ON_BOARD)->pluck('order_id')->all();
        if (! $lines) return response()->json(['ok' => true, 'notified' => 0]);

        $n = 0;
        foreach (Order::where('tenant_id', $t->id)->whereIn('id', $lines)->get() as $o) {
            if (in_array($o->status, self::TERMINAL, true)) continue;
            if ($o->status === 'In transit') continue;
            $o->status = 'In transit';           // OrderObserver -> "left Kampala, on its way to Juba"
            $o->save();
            $n++;
        }
        return response()->json(['ok' => true, 'notified' => $n]);
    }

    /**
     * POST /papi/consignment-request-notify — WhatsApp the "please pack & send" list to Kampala.
     * Asish (Juba) has entered what he needs as loose-stock REQUESTS on this consignment; this sends
     * everything still short (requested > sent) to the Kampala dispatcher (Jayesh) so he packs it.
     * Target number = setting consignment_dispatch_phone, else sourcing_buyer_phone (Jayesh).
     */
    public function requestNotify(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->input('id'));
        if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $res = $this->pushRequestList($t, $s,
            (string) $request->input('send_to_phone', ''),
            trim((string) $request->input('requested_by', '')));
        return response()->json($res, $res['ok'] ? 200 : ($res['code'] ?? 422));
    }

    /** The Kampala dispatcher's WhatsApp: dedicated number, else the sourcing buyer (Jayesh). */
    private function dispatchPhone($t): string
    {
        return preg_replace('/[^0-9]/', '', (string) ($t->setting('consignment_dispatch_phone', '')
            ?: $t->setting('sourcing_buyer_phone', '')));
    }

    /**
     * GET /papi/consignment-contacts — who can request / receive a request.
     * `me` = signed-in user (default "Requested by"); `team` = tenant staff (name + phone);
     * `dispatch` = the default Kampala recipient (Jayesh), so "Send to" defaults sensibly.
     */
    public function contacts(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);

        $team = \App\Models\User::where('tenant_id', $t->id)->orderBy('name')->get()
            ->map(fn ($x) => [
                'name'  => trim((string) $x->name),
                'phone' => preg_replace('/[^0-9]/', '', (string) ($x->phone ?? '')),
                'role'  => (string) $x->role,
            ])
            ->filter(fn ($x) => $x['name'] !== '')->values();

        $dispatch = [
            'name'  => trim((string) $t->setting('sourcing_buyer_name', '')) ?: 'Kampala (Jayesh)',
            'phone' => $this->dispatchPhone($t),
        ];

        return response()->json(['ok' => true, 'me' => (string) ($u->name ?? ''), 'team' => $team, 'dispatch' => $dispatch]);
    }

    /** WhatsApp the still-short (requested > sent) list of a consignment to Kampala. Shared by
     *  requestNotify() and the quick-request flow. Returns a JSON-ready array. */
    private function pushRequestList($t, Shipment $s, string $toOverride = '', string $requestedBy = ''): array
    {
        $to = preg_replace('/[^0-9]/', '', $toOverride) ?: $this->dispatchPhone($t);
        if ($to === '') return ['ok' => false, 'error' => 'no_dispatch_phone', 'code' => 422];

        $rows = ConsignmentStock::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get()
            ->filter(fn ($k) => $k->shortfall() > 0)->values();
        if ($rows->isEmpty()) return ['ok' => false, 'error' => 'nothing_to_send', 'code' => 422];

        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
        $lines = $rows->map(fn ($k) => '• ' . $num($k->shortfall()) . ' × ' . $k->name)->all();

        $route  = trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →');
        $byLine = $requestedBy !== '' ? "\nRequested by: {$requestedBy}" : '';
        $msg = "📋 *Stock request* — {$s->shipment_number}" . ($route !== '' ? " ({$route})" : '') . $byLine . "\n"
             . "Please pack & send:\n" . implode("\n", $lines)
             . "\n\nMark each as *Sent* on the consignment once loaded. — " . $t->name;

        try {
            $gw   = app(WhatsAppManager::class)->forTenant($t);
            $inst = (string) ($t->whatsapp_instance ?? '');
            $gw->sendText($inst, $to, $msg);
        } catch (\Throwable $e) {
            \Log::warning('consignment request notify failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'send_failed', 'code' => 500];
        }
        return ['ok' => true, 'sent_to' => $to, 'items' => count($lines)];
    }

    /** Consignment statuses that can still accept new stock requests (not yet departed). */
    private const OPEN = ['packed', 'sent_to_transporter'];

    /**
     * POST /papi/consignment-request-quick — the one-tap "Asish asks for stock" flow.
     * Adds the pasted request straight onto TODAY's open consignment (reusing the latest one that
     * hasn't left yet, or creating a fresh Kampala→Juba consignment if there is none) — so Asish
     * never has to create/pick a consignment first. Optionally WhatsApps the list to Kampala.
     * Any signed-in staff can request (it only creates a draft request, not a dispatch).
     *
     * Body: items = JSON [{name, product_id?, qty}], notify = 0|1.
     */
    public function requestQuick(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);

        $items = $request->input('items');
        if (is_string($items)) $items = json_decode($items, true);
        if (! is_array($items) || ! $items) return response()->json(['ok' => false, 'error' => 'no_items'], 422);

        // Reuse the latest not-yet-departed consignment, else make one.
        $s = Shipment::where('tenant_id', $t->id)->whereIn('status', self::OPEN)->orderByDesc('id')->first();
        $created = false;
        if (! $s) {
            $s = $this->shipments->create([
                'tenant_id'        => $t->id,
                'origin_city'      => trim((string) $request->input('origin_city', 'Kampala')) ?: 'Kampala',
                'destination_city' => trim((string) $request->input('destination_city', 'Juba')) ?: 'Juba',
                'actor_name'       => (string) ($u->name ?? 'staff'),
            ]);
            $created = true;
        }

        $requestedBy = trim((string) $request->input('requested_by', '')) ?: (string) ($u->name ?? '');
        $noteBy = $requestedBy !== '' ? ('Requested by ' . $requestedBy) : null;

        $added = 0;
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            $qty  = max(0, (float) ($it['qty'] ?? 0));
            if ($name === '' || $qty <= 0) continue;
            $pid  = (int) ($it['product_id'] ?? 0) ?: null;
            $prod = $pid ? \App\Models\Product::where('tenant_id', $t->id)->find($pid) : null;
            if (! $prod) $pid = null;
            if ($name === '' && $prod) $name = (string) $prod->name;

            ConsignmentStock::create([
                'tenant_id' => $t->id, 'shipment_id' => $s->id, 'product_id' => $pid, 'name' => $name,
                'qty_requested' => $qty, 'qty_sent' => 0, 'qty_sold' => 0,
                'unit_cost'  => $prod && $prod->cost_price !== null ? (float) $prod->cost_price : null,
                'unit_price' => $prod ? (float) $prod->price : null,
                'note'       => $noteBy,
            ]);
            $added++;
        }
        if ($added === 0) return response()->json(['ok' => false, 'error' => 'no_valid_items'], 422);

        $notified = null;
        if (filter_var($request->input('notify', 0), FILTER_VALIDATE_BOOLEAN)) {
            $push = $this->pushRequestList($t, $s, (string) $request->input('send_to_phone', ''), $requestedBy);
            $notified = $push['ok'] ? ($push['items'] ?? 0) : ('err:' . ($push['error'] ?? 'x'));
        }

        return response()->json([
            'ok' => true, 'id' => $s->id, 'number' => $s->shipment_number,
            'created' => $created, 'added' => $added, 'notified' => $notified,
        ]);
    }

    /** GET /papi/consignment-sheet?id= — printable manifest / AWB sheet for the bus. */
    public function sheet(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response('Not allowed', 401);
        $s = Shipment::where('tenant_id', $t->id)->find((int) $request->query('id'));
        if (! $s) return response('Consignment not found', 404);

        $lines = ShipmentOrder::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get();
        $orders = $lines->count() ? Order::whereIn('id', $lines->pluck('order_id'))->get()->keyBy('id') : collect();
        $stock = ConsignmentStock::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get();

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $shop = $s->tenant ? (string) $s->tenant->name : 'Consignment';
        $route = trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →');

        $rows = '';
        $i = 0;
        foreach ($lines as $ln) {
            $o = $orders->get($ln->order_id);
            $i++;
            $rows .= '<tr><td>' . $i . '</td><td>' . $e($o->order_no ?? ('#' . $ln->order_id)) . '</td>'
                . '<td>' . $e($o->customer_name ?? '') . '<br><span class="s">' . $e($o->customer_phone ?? '') . '</span></td>'
                . '<td>' . $e($o ? ($o->items_text ?: '') : '') . '</td>'
                . '<td class="r">' . ($o ? number_format((float) $o->total) : '') . '</td>'
                . '<td class="c">☐</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="6" class="s" style="text-align:center;padding:16px">No orders on this consignment.</td></tr>';

        $stockRows = '';
        foreach ($stock as $k) {
            $stockRows .= '<tr><td>' . $e($k->name) . '</td><td class="r">' . rtrim(rtrim(number_format((float) $k->qty_sent, 2), '0'), '.') . '</td><td class="c">☐</td></tr>';
        }
        $stockBlock = $stockRows === '' ? '' :
            '<h3>Loose stock (market)</h3><table><thead><tr><th>Item</th><th class="r">Sent</th><th class="c">✓</th></tr></thead><tbody>' . $stockRows . '</tbody></table>';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Manifest ' . $e($s->shipment_number) . '</title>'
            . '<style>body{font-family:system-ui,Arial,sans-serif;color:#111;margin:24px;font-size:13px}'
            . 'h1{margin:0 0 2px;font-size:20px}h3{margin:18px 0 6px}.s{color:#666;font-size:11px}'
            . '.head{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px}'
            . '.meta div{margin:2px 0}table{width:100%;border-collapse:collapse;margin-top:6px}'
            . 'th,td{border:1px solid #bbb;padding:6px 8px;text-align:left;vertical-align:top}'
            . 'th{background:#f0f0f0;font-size:11px;text-transform:uppercase;letter-spacing:.3px}'
            . '.r{text-align:right;white-space:nowrap}.c{text-align:center;font-size:16px}'
            . '.sign{margin-top:26px;display:flex;justify-content:space-between;gap:20px}.sign div{flex:1;border-top:1px solid #111;padding-top:5px;font-size:12px;color:#333}'
            . '@media print{button{display:none}}</style></head><body>'
            . '<div class="head"><div><h1>' . $e($shop) . '</h1><div class="s">Consignment manifest · ' . $e($route) . '</div></div>'
            . '<div class="meta s"><div><b>' . $e($s->shipment_number) . '</b></div>'
            . '<div>Transporter: ' . $e($s->transport_company ?: '—') . '</div>'
            . '<div>Bus: ' . $e($s->bus_number ?: '—') . '</div>'
            . '<div>Agent (Juba): ' . $e($s->destination_agent_name ?: '—') . '</div>'
            . '<div>Date: ' . $e(optional($s->created_at)->format('d M Y H:i')) . '</div></div></div>'
            . '<h3>Customer orders (' . $lines->count() . ')</h3>'
            . '<table><thead><tr><th>#</th><th>Order</th><th>Customer</th><th>Items</th><th class="r">Value</th><th class="c">Rcvd</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . $stockBlock
            . '<div class="sign"><div>Dispatched by (Kampala)</div><div>Received by (Juba)</div></div>'
            . '<button onclick="window.print()" style="margin-top:18px;padding:10px 16px;font-weight:700;cursor:pointer">🖨 Print</button>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
