<?php

namespace App\Services\Logistics;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentException;

/**
 * Shipment Platform v1 — orchestration. Ties the pure ShipmentStateMachine + CustodyReconciler to
 * persistence. Every state-advancing action also appends an append-only custody event and re-derives
 * box-count exceptions, so the ledger and the discrepancy list are always consistent with reality.
 *
 * Transitions never throw: recordAction() returns ['ok'=>false,'error'=>..] on an illegal move so a
 * controller/token page can show a clean message.
 *
 * Tenant context is expected to be set by the caller (Phase 1 runs inside the seller panel). Child
 * rows are stamped with the shipment's tenant_id explicitly so this stays correct under any context.
 */
class ShipmentService
{
    /** Create a shipment for an order that is being shipped long-distance. Seeds the 'packed' event. */
    public function createFromOrder(Order $order, array $data = []): Shipment
    {
        return $this->create(array_merge([
            'tenant_id' => $order->tenant_id,
            'order_id'  => $order->id,
        ], $data));
    }

    /** Create a (possibly standalone) shipment in `packed` state and seed the custody ledger. */
    public function create(array $data): Shipment
    {
        $tenantId = (int) ($data['tenant_id'] ?? 0);
        $boxes    = isset($data['boxes_sent']) ? (int) $data['boxes_sent'] : null;

        $shipment = Shipment::create([
            'tenant_id'              => $tenantId ?: null,
            'order_id'               => $data['order_id'] ?? null,
            'shipment_number'        => $data['shipment_number'] ?? $this->nextNumber($tenantId),
            'status'                 => ShipmentStateMachine::PACKED,
            'token'                  => $this->newToken(),
            'boxes_sent'             => $boxes,
            'boxes_received'         => null,
            'weight_kg'              => $data['weight_kg'] ?? null,
            'transport_company'      => $data['transport_company'] ?? null,
            'bus_number'             => $data['bus_number'] ?? null,
            'driver_phone'           => $data['driver_phone'] ?? null,
            'origin_city'            => $data['origin_city'] ?? null,
            'destination_city'       => $data['destination_city'] ?? null,
            'destination_agent_name' => $data['destination_agent_name'] ?? null,
            'destination_agent_phone'=> $data['destination_agent_phone'] ?? null,
            'notes'                  => $data['notes'] ?? null,
        ]);

        $this->appendEvent($shipment, [
            'event'      => 'packed',
            'actor'      => ShipmentStateMachine::ACTOR_SHOP,
            'actor_name' => $data['actor_name'] ?? null,
            'box_count'  => $boxes,
            'photo_url'  => $data['photo_url'] ?? null,
            'note'       => $data['note'] ?? null,
        ]);

        return $shipment;
    }

    /**
     * Advance the transport leg. $action ∈ dispatch|transport_confirm|depart|arrive|cancel.
     * $payload may carry: box_count, photo_url, actor_name, note, and (on dispatch) transport_company,
     * bus_number, driver_phone.
     *
     * @return array{ok:bool,status?:string,error?:string,exceptions?:array}
     */
    public function recordAction(Shipment $shipment, string $action, array $payload = []): array
    {
        $res = ShipmentStateMachine::apply($shipment->status, $action);
        if (! $res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }

        // Dispatch records who's carrying it + the shop's outbound box count.
        if ($action === 'dispatch') {
            foreach (['transport_company', 'bus_number', 'driver_phone'] as $k) {
                if (array_key_exists($k, $payload)) $shipment->{$k} = $payload[$k] !== '' ? $payload[$k] : null;
            }
            if (isset($payload['box_count'])) $shipment->boxes_sent = (int) $payload['box_count'];
        }

        $count = ($res['counts'] && isset($payload['box_count']) && $payload['box_count'] !== '')
            ? (int) $payload['box_count'] : null;
        if ($count !== null) $shipment->boxes_received = $count;

        $shipment->status = $res['to'];
        $this->stampStage($shipment, $action);
        $shipment->save();

        $this->appendEvent($shipment, [
            'event'      => $res['event'],
            'actor'      => $res['actor'],
            'actor_name' => $payload['actor_name'] ?? null,
            'box_count'  => $count,
            'photo_url'  => $payload['photo_url'] ?? null,
            'note'       => $payload['note'] ?? null,
        ]);

        $exceptions = $this->reconcile($shipment);

        return ['ok' => true, 'status' => $shipment->status, 'exceptions' => $exceptions];
    }

    /**
     * Record a last-mile / out-of-band custody handoff (e.g. rider collected, delivered) WITHOUT
     * touching the transport state machine — the last mile is Delivery's machine. Keeps the ledger
     * complete end-to-end and re-runs reconciliation. (Wired from the Delivery flow in Phase 3.)
     */
    public function recordCustody(Shipment $shipment, string $event, string $actor, ?int $boxCount, array $payload = []): array
    {
        $this->appendEvent($shipment, [
            'event'      => $event,
            'actor'      => $actor,
            'actor_name' => $payload['actor_name'] ?? null,
            'box_count'  => $boxCount,
            'photo_url'  => $payload['photo_url'] ?? null,
            'note'       => $payload['note'] ?? null,
        ]);
        return ['ok' => true, 'exceptions' => $this->reconcile($shipment)];
    }

    /** Explicit damage report — a manual exception, never inferred by reconciliation. */
    public function reportDamage(Shipment $shipment, int $boxes, string $actor, array $payload = []): ShipmentException
    {
        $this->appendEvent($shipment, [
            'event'      => 'damaged',
            'actor'      => $actor,
            'actor_name' => $payload['actor_name'] ?? null,
            'box_count'  => null,           // damage doesn't change the count, it annotates condition
            'photo_url'  => $payload['photo_url'] ?? null,
            'note'       => $payload['note'] ?? null,
        ]);

        return ShipmentException::create([
            'tenant_id'   => $shipment->tenant_id,
            'shipment_id' => $shipment->id,
            'type'        => CustodyReconciler::DAMAGED,
            'delta'       => $boxes,
            'detail'      => (string) ($payload['note'] ?? "{$boxes} box(es) reported damaged"),
            'resolved'    => false,
            'created_at'  => now(),
        ]);
    }

    /**
     * Re-derive box-count exceptions from the ledger. Auto types (missing/extra) are replaced from
     * the current ledger so they never duplicate or go stale; resolved rows and manual `damaged_boxes`
     * are preserved.
     *
     * @return array open exceptions after reconciliation (as arrays)
     */
    public function reconcile(Shipment $shipment): array
    {
        $events = $shipment->events()->get()
            ->map(fn ($e) => ['stage' => (string) $e->event, 'count' => $e->box_count])
            ->all();

        $derived = CustodyReconciler::reconcile($events);

        // Clear only the auto-generated, still-open discrepancy rows for this shipment.
        ShipmentException::where('shipment_id', $shipment->id)
            ->whereIn('type', [CustodyReconciler::MISSING, CustodyReconciler::EXTRA])
            ->where('resolved', false)
            ->delete();

        foreach ($derived as $d) {
            ShipmentException::create([
                'tenant_id'   => $shipment->tenant_id,
                'shipment_id' => $shipment->id,
                'type'        => $d['type'],
                'from_stage'  => $d['from_stage'],
                'to_stage'    => $d['to_stage'],
                'expected'    => $d['expected'],
                'got'         => $d['got'],
                'delta'       => $d['delta'],
                'detail'      => "{$d['delta']} box(es) {$d['type']} between {$d['from_stage']} and {$d['to_stage']}",
                'resolved'    => false,
                'created_at'  => now(),
            ]);
        }

        return $shipment->openExceptions()->get()->toArray();
    }

    private function appendEvent(Shipment $shipment, array $data): ShipmentEvent
    {
        return ShipmentEvent::create(array_merge([
            'tenant_id'   => $shipment->tenant_id,
            'shipment_id' => $shipment->id,
            'occurred_at' => now(),
            'created_at'  => now(),
        ], $data));
    }

    private function stampStage(Shipment $shipment, string $action): void
    {
        $map = [
            'dispatch'          => 'sent_at',
            'transport_confirm' => 'transport_confirmed_at',
            'depart'            => 'departed_at',
            'arrive'            => 'arrived_at',
            'cancel'            => 'cancelled_at',
        ];
        if (isset($map[$action])) $shipment->{$map[$action]} = now();
    }

    private function nextNumber(int $tenantId): string
    {
        $n = Shipment::where('tenant_id', $tenantId)->count() + 1;
        return 'SH-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));   // 32 hex chars, fits token(40), URL-safe
    }
}
