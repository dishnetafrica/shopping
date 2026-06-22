<?php

namespace App\Http\Controllers\Panel;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentException;
use App\Services\Logistics\ShipmentService;
use App\Services\Logistics\ShipmentStateMachine as SM;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Shipment Platform v2A — seller-panel management. Internal workflow only: create a shipment from
 * an order, advance it through the transport leg, see the custody timeline + exceptions. No external
 * token pages, no notifications yet. Every method is tenant-scoped via SetTenantFromUser + guarded.
 */
class ShipmentController extends Controller
{
    public function __construct(protected ShipmentService $shipments) {}

    /** Dashboard list. ?filter = all|packed|sent_to_transporter|in_transit|arrived|exception */
    public function index(Request $r)
    {
        try {
            $filter = (string) $r->query('filter', 'all');
            $q = Shipment::query()->orderByDesc('id');

            if ($filter === 'exception') {
                $q->whereHas('openExceptions');
            } elseif ($filter !== 'all' && $filter !== '') {
                $q->where('status', $filter);
            }

            $rows = $q->limit(300)->get();
            $orderNos = Order::whereIn('id', $rows->pluck('order_id')->filter()->all())
                ->pluck('order_no', 'id');

            $items = $rows->map(fn (Shipment $s) => [
                'id'        => $s->id,
                'number'    => $s->shipment_number,
                'order_no'  => $orderNos[$s->order_id] ?? null,
                'status'    => $s->status,
                'route'     => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
                'transport' => $s->transport_company,
                'boxes'     => ['sent' => $s->boxes_sent, 'received' => $s->boxes_received],
                'exceptions'=> $s->openExceptions()->count(),
                'updated'   => optional($s->updated_at)->toDateTimeString(),
            ])->all();

            return response()->json(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipments index failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'items' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Detail: shipment + custody timeline + exceptions + the next available action. */
    public function show(Request $r)
    {
        try {
            $s = Shipment::find((int) $r->query('id'));
            if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

            $order = $s->order_id ? Order::find($s->order_id) : null;

            $events = $s->events()->get()->map(fn ($e) => [
                'event'  => $e->event,
                'actor'  => $e->actor,
                'name'   => $e->actor_name,
                'boxes'  => $e->box_count,
                'photo'  => $e->photo_url,
                'note'   => $e->note,
                'at'     => optional($e->occurred_at)->toDateTimeString(),
            ])->all();

            $exceptions = $s->exceptions()->orderByDesc('id')->get()->map(fn ($x) => [
                'id'        => $x->id,
                'type'      => $x->type,
                'from_stage'=> $x->from_stage,
                'to_stage'  => $x->to_stage,
                'expected'  => $x->expected,
                'got'       => $x->got,
                'delta'     => $x->delta,
                'detail'    => $x->detail,
                'resolved'  => (bool) $x->resolved,
            ])->all();

            $action = SM::actionFrom($s->status);   // null when terminal

            return response()->json(['ok' => true, 'shipment' => [
                'id'        => $s->id,
                'number'    => $s->shipment_number,
                'token'     => $s->token,
                'order_id'  => $s->order_id,
                'order_no'  => $order->order_no ?? null,
                'status'    => $s->status,
                'flow'      => SM::FLOW,
                'next_action' => $action['action'] ?? null,
                'is_terminal' => SM::isTerminal($s->status),
                'transport_company' => $s->transport_company,
                'bus_number'  => $s->bus_number,
                'driver_phone'=> $s->driver_phone,
                'origin_city' => $s->origin_city,
                'destination_city' => $s->destination_city,
                'boxes_sent'  => $s->boxes_sent,
                'boxes_received' => $s->boxes_received,
                'notes'       => $s->notes,
                'events'      => $events,
                'exceptions'  => $exceptions,
            ]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipment show failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Create a shipment from an order (status starts at packed). Idempotent per order. */
    public function store(Request $r)
    {
        try {
            $order = Order::find((int) $r->query('order_id'));
            if (! $order) return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);

            $existing = Shipment::where('order_id', $order->id)->first();
            if ($existing) {
                return response()->json(['ok' => true, 'id' => $existing->id, 'noop' => true]);
            }

            $s = $this->shipments->createFromOrder($order, [
                'transport_company'      => $this->str($r, 'transport_company'),
                'bus_number'             => $this->str($r, 'bus_number'),
                'driver_phone'           => $this->str($r, 'driver_phone'),
                'origin_city'            => $this->str($r, 'origin_city'),
                'destination_city'       => $this->str($r, 'destination_city'),
                'destination_agent_name' => $this->str($r, 'destination_agent_name'),
                'destination_agent_phone'=> $this->str($r, 'destination_agent_phone'),
                'boxes_sent'             => $r->filled('boxes_sent') ? (int) $r->query('boxes_sent') : null,
                'notes'                  => $this->str($r, 'notes'),
                'actor_name'             => (string) ($r->user()->name ?? 'owner'),
            ]);

            return response()->json(['ok' => true, 'id' => $s->id, 'number' => $s->shipment_number]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipment store failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Advance the transport leg. ?id=&action=dispatch|transport_confirm|depart|arrive|cancel */
    public function action(Request $r)
    {
        try {
            $s = Shipment::find((int) $r->query('id'));
            if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

            $payload = [
                'actor_name'        => (string) ($r->user()->name ?? 'owner'),
                'note'              => $this->str($r, 'note'),
                'photo_url'         => $this->str($r, 'photo_url'),
            ];
            if ($r->filled('box_count')) $payload['box_count'] = (int) $r->query('box_count');
            foreach (['transport_company', 'bus_number', 'driver_phone'] as $k) {
                if ($r->has($k)) $payload[$k] = $this->str($r, $k);
            }

            $res = $this->shipments->recordAction($s, (string) $r->query('action'), $payload);
            return response()->json($res);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipment action failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Mark a discrepancy resolved (owner has chased it down). */
    public function resolveException(Request $r)
    {
        try {
            $x = ShipmentException::find((int) $r->query('id'));
            if (! $x) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            $x->resolved = true;
            $x->resolved_by = (string) ($r->user()->name ?? 'owner');
            $x->resolved_at = now();
            $x->save();
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Does this order already have a shipment? Powers the order-page button gating. */
    public function forOrder(Request $r)
    {
        try {
            $s = Shipment::where('order_id', (int) $r->query('order_id'))->first();
            return response()->json(['ok' => true, 'shipment' => $s ? [
                'id' => $s->id, 'number' => $s->shipment_number, 'status' => $s->status,
            ] : null]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'shipment' => null, 'error' => $e->getMessage()]);
        }
    }

    private function str(Request $r, string $key): ?string
    {
        $v = trim((string) $r->query($key, ''));
        return $v !== '' ? $v : null;
    }
}
