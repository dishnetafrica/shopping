<?php

namespace App\Services\Delivery;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Orchestrates assignment + lifecycle advance + rider WhatsApp. (DB/WhatsApp — staging-verified.) */
class DeliveryService
{
    public function __construct(protected WhatsAppManager $wa) {}

    /** Assign (or reassign) a rider and create/refresh the delivery. Notifies the rider. */
    public function assign(Tenant $tenant, Order $order, string $riderName, string $riderPhone): Delivery
    {
        $riderPhone = preg_replace('/\D+/', '', $riderPhone);
        $rider = Rider::where('name', $riderName)->first();
        if (! $rider) {
            $rider = Rider::create(['name' => $riderName, 'phone' => $riderPhone, 'active' => true]);
        } elseif ($riderPhone !== '' && $rider->phone !== $riderPhone) {
            $rider->phone = $riderPhone; $rider->save();
        }

        $delivery = Delivery::firstOrNew(['order_id' => $order->id]);
        $delivery->tenant_id    = $tenant->id;
        $delivery->rider_id     = $rider->id;
        $delivery->zone_id      = $order->delivery_zone_id ?: $delivery->zone_id;
        $delivery->status       = Delivery::ASSIGNED;
        $delivery->fee          = (int) ($order->delivery_fee ?? 0);
        $delivery->eta_at       = $order->eta_at ?: $delivery->eta_at;
        $delivery->cod_amount   = (int) round((float) $order->total);
        $delivery->assigned_at  = now();
        if (empty($delivery->rider_token)) $delivery->rider_token = Str::lower(Str::random(16));
        $delivery->save();

        // customer-facing order status
        if ($s = DeliveryStatus::orderStatusFor(Delivery::ASSIGNED)) {
            if ($order->status !== $s) { $order->status = $s; $order->save(); }
        }

        $this->notifyRider($tenant, $order, $delivery, $rider);
        return $delivery;
    }

    /** Advance the lifecycle. $to in {picked,out,delivered,failed}. */
    public function advance(Tenant $tenant, Delivery $delivery, string $to, array $extras = []): array
    {
        if (! DeliveryStatus::canTransition($delivery->status, $to)) {
            return ['ok' => false, 'error' => "Can't move from {$delivery->status} to {$to}."];
        }
        $delivery->status = $to;
        $field = ['picked' => 'picked_at', 'out' => 'out_at', 'delivered' => 'delivered_at', 'failed' => 'failed_at'][$to] ?? null;
        if ($field) $delivery->{$field} = now();
        if ($to === Delivery::FAILED && isset($extras['reason']))   $delivery->failed_reason = (string) $extras['reason'];
        if ($to === Delivery::DELIVERED) {
            if (isset($extras['recipient_name'])) $delivery->recipient_name = (string) $extras['recipient_name'];
            if (array_key_exists('cod_collected', $extras)) $delivery->cod_collected = (bool) $extras['cod_collected'];
        }
        $delivery->save();

        // map to the order's customer-facing status (fires existing customer notifications + delivered_at)
        if ($s = DeliveryStatus::orderStatusFor($to)) {
            $order = $delivery->order;
            if ($order && $order->status !== $s) { $order->status = $s; $order->save(); }
        }
        return ['ok' => true, 'delivery' => $delivery];
    }

    protected function notifyRider(Tenant $tenant, Order $order, Delivery $delivery, Rider $rider): void
    {
        if (! $tenant->whatsapp_instance || ! $rider->phone) return;
        $items = $order->items_text ?: collect($order->items_json ?? [])
            ->map(fn ($l) => (($l['qty'] ?? 1) . ' x ' . ($l['name'] ?? '')))->implode(', ');
        $cur = (string) $tenant->setting('currency', 'UGX');
        $msg = "\u{1F6F5} New delivery — {$order->order_no}\n"
            . 'Drop: ' . ($order->location ?: 'see customer') . "\n"
            . 'Customer: ' . ($order->customer_name ?: 'Customer') . ' (+' . $order->customer_phone . ")\n"
            . "Items: {$items}\n"
            . 'Collect (COD): ' . $cur . ' ' . number_format((float) $delivery->cod_amount);
        try {
            $this->wa->forTenant($tenant)->sendText($tenant->whatsapp_instance, $rider->phone, $msg);
            MessageLog::record($tenant->id, $rider->phone, $tenant->whatsapp_instance, 'out', 'system', $msg);
        } catch (\Throwable $e) {
            Log::warning('delivery.rider_notify_failed', ['order' => $order->id, 'err' => $e->getMessage()]);
        }
    }
}
