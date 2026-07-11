<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
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
        return response()->json(['ok' => true, 'id' => $s->id, 'number' => $s->shipment_number]);
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

        return response()->json(['ok' => true, 'consignment' => [
            'id' => $s->id, 'number' => $s->shipment_number, 'status' => $s->status, 'token' => $s->token,
            'route' => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
            'origin' => $s->origin_city, 'destination' => $s->destination_city,
            'transport' => $s->transport_company, 'bus' => $s->bus_number, 'driver_phone' => $s->driver_phone,
            'agent' => $s->destination_agent_name, 'agent_phone' => $s->destination_agent_phone,
            'boxes_sent' => $s->boxes_sent, 'notes' => $s->notes,
        ], 'manifest' => $manifest]);
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
}
