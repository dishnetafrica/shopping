<?php

namespace App\Http\Controllers\Panel;

use App\Models\Delivery;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Tenant;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\RiderAssigner;
use App\Services\Delivery\ZoneResolver;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * D1/D2 delivery endpoints for the seller panel. Auth + tenant scope come from
 * the panel route group (web + auth + SetTenantFromUser).
 */
class DeliveryController
{
    /** D1: server-authoritative fee + ETA for a location. */
    public function quote(Request $r, ZoneResolver $resolver, TenantContext $ctx)
    {
        $tenant = Tenant::findOrFail($ctx->id());
        $loc = (string) $r->query('location', '');
        $lat = $r->filled('lat') ? (float) $r->query('lat') : null;
        $lng = $r->filled('lng') ? (float) $r->query('lng') : null;
        $subtotal = (int) round((float) $r->query('subtotal', 0));

        $q = $resolver->quote($loc, $lat, $lng, $subtotal, $this->fallbackRule($tenant),
            (float) $tenant->setting('lat', 0) ?: null, (float) $tenant->setting('lng', 0) ?: null);

        return response()->json([
            'ok'   => true,
            'zone' => $q['zone']['name'] ?? null,
            'fee'  => $q['fee'],
            'eta_minutes' => $q['zone']['eta_minutes'] ?? 45,
            'distance_km' => $q['distance_km'],
        ]);
    }

    /** D2: delivery board grouped by status. */
    public function board()
    {
        $rows = Delivery::with(['order', 'rider', 'zone'])->orderByDesc('id')->limit(500)->get()
            ->map(fn (Delivery $d) => [
                'id'        => $d->id,
                'order_id'  => (int) ($d->order->id ?? 0),
                'order_no'  => (string) ($d->order->order_no ?? ''),
                'customer'  => (string) ($d->order->customer_name ?? 'Customer'),
                'phone'     => (string) ($d->order->customer_phone ?? ''),
                'location'  => (string) ($d->order->location ?? ''),
                'zone'      => (string) ($d->zone->name ?? ''),
                'fee'       => (int) $d->fee,
                'cod'       => (int) $d->cod_amount,
                'eta_at'    => optional($d->eta_at)->toIso8601String(),
                'rider'     => (string) ($d->rider->name ?? ''),
                'status'    => $d->status,
            ]);
        $byStatus = [];
        foreach (\App\Services\Delivery\DeliveryStatus::all() as $s) $byStatus[$s] = [];
        foreach ($rows as $row) $byStatus[$row['status']][] = $row;

        return response()->json(['ok' => true, 'board' => $byStatus]);
    }

    /** Suggest the least-loaded active rider (and the zone's default rider). */
    public function suggestRider(Request $r)
    {
        $zoneDefault = null;
        if ($r->filled('zone_id')) {
            $zoneDefault = optional(DeliveryZone::find((int) $r->query('zone_id')))->default_rider_id;
        }
        $open = [];
        foreach (Rider::where('active', true)->pluck('id') as $rid) {
            $open[$rid] = Delivery::where('rider_id', $rid)
                ->whereIn('status', [Delivery::ASSIGNED, Delivery::PICKED, Delivery::OUT])->count();
        }
        $suggested = RiderAssigner::suggest($open, $zoneDefault);
        $rider = $suggested ? Rider::find($suggested) : null;
        return response()->json(['ok' => true, 'rider_id' => $suggested,
            'name' => $rider->name ?? null, 'phone' => $rider->phone ?? null]);
    }

    /** Assign / reassign a rider (creates the delivery, notifies the rider). */
    public function assign(Request $r, DeliveryService $svc, TenantContext $ctx)
    {
        $tenant = Tenant::findOrFail($ctx->id());
        $order = Order::find((int) $r->query('order_id'));
        if (! $order) return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        $name = trim((string) $r->query('rider', ''));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'rider_required'], 422);

        $d = $svc->assign($tenant, $order, $name, (string) $r->query('riderphone', ''));
        return response()->json(['ok' => true, 'delivery_id' => $d->id, 'status' => $d->status]);
    }

    /** Advance lifecycle: picked | out | delivered | failed. */
    public function status(Request $r, DeliveryService $svc, TenantContext $ctx)
    {
        $tenant = Tenant::findOrFail($ctx->id());
        $d = Delivery::find((int) $r->query('id'));
        if (! $d) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $to = (string) $r->query('status', '');
        $res = $svc->advance($tenant, $d, $to, [
            'reason'         => $r->query('reason'),
            'recipient_name' => $r->query('recipient_name'),
            'cod_collected'  => $r->filled('cod_collected') ? ($r->query('cod_collected') === 'true') : null,
        ]);
        return response()->json($res['ok'] ? ['ok' => true, 'status' => $d->status] : ['ok' => false, 'error' => $res['error']], $res['ok'] ? 200 : 422);
    }

    protected function fallbackRule(Tenant $tenant): array
    {
        $s = $tenant->settings ?? [];
        return [
            'base'      => (int) ($s['base'] ?? 0),
            'per_km'    => (int) ($s['perKm'] ?? 0),
            'min'       => (int) ($s['min'] ?? 0),
            'free_over' => (int) ($s['freeOver'] ?? 0),
        ];
    }
}
