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

            // last-mile delivery (Phase 3 bridge) — same screen, one chain
            $delivery = null;
            $dv = $s->delivery_id
                ? \App\Models\Delivery::find($s->delivery_id)
                : ($s->order_id ? \App\Models\Delivery::where('order_id', $s->order_id)->first() : null);
            if ($dv) {
                $delivery = [
                    'id'     => $dv->id,
                    'status' => $dv->status,
                    'rider'  => optional($dv->rider)->name,
                    'rider_phone' => optional($dv->rider)->phone,
                    'token'  => $dv->rider_token,
                    'delivered_at' => optional($dv->delivered_at)->toDateTimeString(),
                ];
            }
            $canHandoff = $s->status === SM::ARRIVED && ! $dv;

            // box-level custody (v6)
            $boxSvc = app(\App\Services\Logistics\BoxCustodyService::class);
            $boxTotal = $boxSvc->total($s);
            $boxes = $boxTotal > 0 ? [
                'total'   => $boxTotal,
                'scanned' => [
                    'received_by_transport' => $boxSvc->scannedCount($s, 'received_by_transport'),
                    'arrived'               => $boxSvc->scannedCount($s, 'arrived'),
                    'collected_by_rider'    => $boxSvc->scannedCount($s, 'collected_by_rider'),
                    'delivered'             => $boxSvc->scannedCount($s, 'delivered'),
                ],
            ] : null;

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
                'delivery'    => $delivery,
                'can_handoff' => $canHandoff,
                'boxes'       => $boxes,
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

    /** Logistics dashboard: metrics + a filtered/searched view + facets for the exception filters. */
    public function dashboard(Request $r)
    {
        try {
            $view  = (string) $r->query('view', 'all');
            $q     = trim((string) $r->query('q', ''));
            $delayHours = 24;

            $ships = Shipment::query()->orderByDesc('id')->limit(500)->get();

            $orders = Order::whereIn('id', $ships->pluck('order_id')->filter()->all())
                ->get(['id', 'order_no', 'customer_name', 'customer_phone', 'status'])->keyBy('id');

            // last-mile delivery status per order (Phase 3 — show both statuses together)
            $deliveries = \App\Models\Delivery::whereIn('order_id', $ships->pluck('order_id')->filter()->all())
                ->get(['id', 'order_id', 'status'])->keyBy('order_id');

            // open exceptions grouped by shipment
            $openEx = ShipmentException::where('resolved', false)->get();
            $exByShip = $openEx->groupBy('shipment_id');

            $inTransit = ['sent_to_transporter', 'transport_confirmed', 'in_transit'];
            $now = now();

            // enrich each shipment with derived flags
            $rows = $ships->map(function (Shipment $s) use ($orders, $exByShip, $inTransit, $now, $delayHours, $deliveries) {
                $o = $s->order_id ? ($orders[$s->order_id] ?? null) : null;
                $delivered = $o && strcasecmp((string) $o->status, 'Delivered') === 0;
                $exCount = isset($exByShip[$s->id]) ? $exByShip[$s->id]->count() : 0;
                $delayed = in_array($s->status, $inTransit, true)
                    && $s->updated_at && $s->updated_at->diffInHours($now) >= $delayHours;
                $dlv = $s->order_id ? ($deliveries[$s->order_id] ?? null) : null;
                return [
                    'm'         => $s,
                    'id'        => $s->id,
                    'number'    => $s->shipment_number,
                    'order_no'  => $o->order_no ?? null,
                    'customer'  => $o->customer_name ?? null,
                    'phone'     => $o->customer_phone ?? null,
                    'status'    => $s->status,
                    'route'     => trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →'),
                    'transport' => $s->transport_company,
                    'origin'    => $s->origin_city,
                    'destination' => $s->destination_city,
                    'boxes'     => ['sent' => $s->boxes_sent, 'received' => $s->boxes_received],
                    'exceptions'=> $exCount,
                    'delivered' => $delivered,
                    'delayed'   => $delayed,
                    'delivery_status' => $dlv->status ?? null,
                    'updated'   => optional($s->updated_at)->toDateTimeString(),
                ];
            });

            // metrics over the whole set (not the filtered view)
            $metrics = [
                'active'         => $rows->whereIn('status', array_merge(['packed'], $inTransit))->count(),
                'in_transit'     => $rows->whereIn('status', $inTransit)->count(),
                'delayed'        => $rows->where('delayed', true)->count(),
                'exceptions'     => $rows->where('exceptions', '>', 0)->count(),
                'completed_today'=> $ships->filter(fn ($s) => $s->arrived_at && $s->arrived_at->isToday())->count(),
            ];

            // facets for the exception filters
            $facets = [
                'transport' => $rows->pluck('transport')->filter()->unique()->values()->all(),
                'origin'    => $rows->pluck('origin')->filter()->unique()->values()->all(),
                'destination' => $rows->pluck('destination')->filter()->unique()->values()->all(),
            ];

            // apply the view
            $items = $rows;
            if ($view === 'awaiting_dispatch') {
                $items = $items->where('status', 'packed');
            } elseif ($view === 'in_transit') {
                $items = $items->whereIn('status', $inTransit);
            } elseif ($view === 'arrived') {
                $items = $items->where('status', 'arrived')->where('delivered', false);
            } elseif ($view === 'delivered') {
                $items = $items->where('delivered', true);
            } elseif ($view === 'exception') {
                $items = $items->where('exceptions', '>', 0);
                foreach (['transport' => 'transport', 'origin' => 'origin', 'destination' => 'destination'] as $param => $col) {
                    $val = trim((string) $r->query($param, ''));
                    if ($val !== '') $items = $items->where($col, $val);
                }
            }

            // search
            if ($q !== '') {
                $needle = mb_strtolower($q);
                $items = $items->filter(function ($it) use ($needle) {
                    foreach (['number', 'order_no', 'customer', 'phone'] as $f) {
                        if ($it[$f] !== null && mb_strpos(mb_strtolower((string) $it[$f]), $needle) !== false) return true;
                    }
                    return false;
                });
            }

            // exception-type breakdown across the (filtered) exception set
            $exShipIds = $items->where('exceptions', '>', 0)->pluck('id')->all();
            $exRel = $openEx->whereIn('shipment_id', $exShipIds);
            $breakdown = [
                'missing_boxes' => $exRel->where('type', 'missing_boxes')->count(),
                'extra_boxes'   => $exRel->where('type', 'extra_boxes')->count(),
                'damaged_boxes' => $exRel->where('type', 'damaged_boxes')->count(),
            ];

            $out = $items->map(fn ($it) => collect($it)->except('m')->all())->values()->all();

            return response()->json([
                'ok' => true,
                'metrics' => $metrics,
                'facets' => $facets,
                'breakdown' => $breakdown,
                'items' => $out,
                'delay_hours' => $delayHours,
                'auto_handoff' => (bool) $r->user()->tenant->setting('logistics_auto_handoff', false),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipments dashboard failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'metrics' => [], 'items' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Hand an arrived shipment to the last mile (create/link an Awaiting-Rider delivery). */
    public function handoff(Request $r)
    {
        try {
            $s = Shipment::find((int) $r->query('id'));
            if (! $s) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            $res = app(\App\Services\Logistics\LastMileBridge::class)->handoff($s);
            return response()->json($res);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('shipment handoff failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Read/set the "auto hand off to the last mile on arrival" tenant toggle. */
    public function autoToggle(Request $r)
    {
        $t = $r->user()->tenant;
        if ($r->has('on')) {
            $t->putSetting('logistics_auto_handoff', $r->query('on') === '1');
        }
        return response()->json(['ok' => true, 'auto_handoff' => (bool) $t->setting('logistics_auto_handoff', false)]);
    }

    /** Printable QR label sheet — one label per box (DHL/FedEx style). Opens in a new tab to print. */
    public function labels(Request $r)
    {
        $s = Shipment::find((int) $r->query('id'));
        if (! $s) return response('Shipment not found', 404);

        $boxes = app(\App\Services\Logistics\BoxCustodyService::class)->syncBoxes($s);
        $order = $s->order_id ? Order::find($s->order_id) : null;
        $total = $boxes->count();

        $shop = $s->tenant ? (string) $s->tenant->name : 'Shipment';
        $cust = $order ? (string) $order->customer_name : '';
        $phone = $order ? (string) $order->customer_phone : '';
        $route = trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →');

        if ($total === 0) {
            return response('<p style="font-family:sans-serif;padding:24px">No boxes yet. Dispatch the shipment with a box count first.</p>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $cards = '';
        foreach ($boxes as $b) {
            $cards .= '<div class="lbl">'
                . '<div class="top"><div class="sh">' . e($s->shipment_number) . '</div>'
                . '<div class="bx">BOX ' . (int) $b->box_number . ' / ' . $total . '</div></div>'
                . '<div class="qr" data-code="' . e($b->code) . '"></div>'
                . '<div class="code">' . e($b->code) . '</div>'
                . '<div class="meta"><div><b>' . e($shop) . '</b></div>'
                . ($route !== '' ? '<div>' . e($route) . '</div>' : '')
                . ($cust !== '' ? '<div>' . e($cust) . ($phone !== '' ? ' · ' . e($phone) : '') . '</div>' : '')
                . '</div></div>';
        }

        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Labels ' . e($s->shipment_number) . '</title>'
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><style>'
            . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#eef2ee;color:#16231C;padding:14px}'
            . '.bar{max-width:860px;margin:0 auto 12px;display:flex;align-items:center;gap:12px}'
            . '.bar h1{font-size:17px;margin:0}.bar button{margin-left:auto;background:#15803D;color:#fff;border:0;border-radius:9px;padding:10px 18px;font-weight:800;font-size:14px;cursor:pointer}'
            . '.sheet{max-width:860px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:12px}'
            . '.lbl{background:#fff;border:1px solid #000;border-radius:8px;padding:12px;display:flex;flex-direction:column;align-items:center;break-inside:avoid}'
            . '.top{width:100%;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px}'
            . '.sh{font-weight:800;font-size:18px}.bx{font-weight:800;font-size:15px;background:#000;color:#fff;border-radius:6px;padding:3px 10px}'
            . '.qr{margin:4px 0}.qr img,.qr canvas{display:block}'
            . '.code{font-family:monospace;font-size:13px;margin-top:6px;letter-spacing:1px}'
            . '.meta{width:100%;font-size:12px;color:#333;margin-top:8px;border-top:1px dashed #999;padding-top:6px;text-align:center;line-height:1.5}'
            . '@media print{body{background:#fff;padding:0}.bar{display:none}.sheet{gap:0}.lbl{border-radius:0;margin:-0.5px}}'
            . '</style></head><body>'
            . '<div class="bar"><h1>🏷 ' . e($s->shipment_number) . ' — ' . $total . ' labels</h1><button onclick="window.print()">Print</button></div>'
            . '<div class="sheet">' . $cards . '</div>'
            . '<script>document.querySelectorAll(".qr").forEach(function(el){new QRCode(el,{text:el.getAttribute("data-code"),width:118,height:118,correctLevel:QRCode.CorrectLevel.M});});</script>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
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
