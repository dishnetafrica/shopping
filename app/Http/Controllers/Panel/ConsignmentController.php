<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentStock;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use App\Services\Logistics\ShipmentService;
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
                            'unit_price' => $k->unit_price, 'currency' => $k->currency,
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
            ];
        });

        $stock = ConsignmentStock::where('tenant_id', $t->id)->where('shipment_id', $s->id)->orderBy('id')->get()
            ->map(fn ($k) => [
                'id' => $k->id, 'name' => $k->name,
                'requested' => (float) $k->qty_requested, 'sent' => (float) $k->qty_sent,
                'received' => $k->qty_received === null ? null : (float) $k->qty_received,
                'sold' => (float) $k->qty_sold, 'short' => $k->shortfall(), 'remaining' => $k->remaining(),
                'unit_price' => $k->unit_price === null ? null : (float) $k->unit_price, 'currency' => $k->currency,
            ]);

        return response()->json(['ok' => true, 'consignment' => [
            'id' => $s->id, 'number' => $s->shipment_number, 'status' => $s->status, 'token' => $s->token,
            'route' => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
            'origin' => $s->origin_city, 'destination' => $s->destination_city,
            'transport' => $s->transport_company, 'bus' => $s->bus_number, 'driver_phone' => $s->driver_phone,
            'agent' => $s->destination_agent_name, 'agent_phone' => $s->destination_agent_phone,
            'boxes_sent' => $s->boxes_sent, 'notes' => $s->notes,
        ], 'manifest' => $manifest, 'stock' => $stock]);
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

        DB::transaction(function () use ($line, $to, $u, $t) {
            $line->status = $to;
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
        $name = trim((string) $request->input('name', ''));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'name_required'], 422);

        $k = ConsignmentStock::create([
            'tenant_id' => $t->id, 'shipment_id' => $s->id, 'name' => $name,
            'qty_requested' => max(0, (float) $request->input('qty_requested', 0)),
            'qty_sent'      => max(0, (float) $request->input('qty_sent', 0)),
            'unit_price'    => $request->filled('unit_price') ? (float) $request->input('unit_price') : null,
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

        if ($request->has('name') && trim((string) $request->input('name')) !== '') $k->name = trim((string) $request->input('name'));
        foreach (['qty_requested', 'qty_sent', 'qty_received', 'unit_price'] as $f) {
            if ($request->has($f)) {
                $k->$f = $request->input($f) === '' ? ($f === 'qty_received' || $f === 'unit_price' ? null : 0) : max(0, (float) $request->input($f));
            }
        }
        if ($request->has('currency')) $k->currency = strtoupper((string) $request->input('currency')) ?: null;
        $k->save();
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
            $k->save();
            return $o;
        });

        return response()->json(['ok' => true, 'order_no' => $order->order_no, 'sold' => $qty,
            'remaining' => $k->remaining(), 'total' => (float) $order->total]);
    }
}
