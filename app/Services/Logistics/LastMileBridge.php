<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * Bridges an ARRIVED transport shipment to the last-mile Delivery machine.
 *
 * When goods reach the destination city (e.g. Juba), the transport FSM is finished and
 * the doorstep leg is owned by the existing Delivery model. This creates (or links) an
 * "awaiting rider" Delivery for the shipment's order so it surfaces on the Delivery Board,
 * ready for a rider to be assigned. It is IDEMPOTENT — calling it again (manual button and
 * the auto-handoff-on-arrival both call it) never creates a second delivery and never
 * disturbs a delivery that already has a rider.
 */
class LastMileBridge
{
    /** Synthetic pre-assignment status shown on the board as "Awaiting rider". */
    public const AWAITING = 'awaiting';

    public function handoff(Shipment $shipment): array
    {
        if (! $shipment->order_id) {
            return ['ok' => false, 'error' => 'no_order', 'message' => 'This shipment is not linked to an order.'];
        }
        // Only hand off once the goods have actually arrived at the destination.
        if ((string) $shipment->status !== ShipmentStateMachine::ARRIVED) {
            return ['ok' => false, 'error' => 'not_arrived', 'message' => 'Shipment must be marked arrived before handing off to delivery.'];
        }

        $order = Order::find($shipment->order_id);
        if (! $order) {
            return ['ok' => false, 'error' => 'order_not_found'];
        }

        $delivery = Delivery::firstOrNew(['order_id' => $order->id]);

        // Already assigned to a rider (or further along)? Leave it exactly as-is — idempotent.
        if ($delivery->exists && $delivery->rider_id) {
            return ['ok' => true, 'delivery_id' => $delivery->id, 'status' => $delivery->status, 'already' => true];
        }

        $delivery->tenant_id  = $order->tenant_id;
        $delivery->zone_id    = $order->delivery_zone_id ?: $delivery->zone_id;
        $delivery->status     = self::AWAITING;                 // shows as "Awaiting rider" on the board
        $delivery->fee        = (int) ($order->delivery_fee ?? 0);
        $delivery->eta_at     = $order->eta_at ?: $delivery->eta_at;
        $delivery->cod_amount = (int) round((float) $order->total);
        if (empty($delivery->rider_token)) $delivery->rider_token = Str::lower(Str::random(16));
        $delivery->save();

        return ['ok' => true, 'delivery_id' => $delivery->id, 'status' => $delivery->status, 'created' => $delivery->wasRecentlyCreated];
    }
}
