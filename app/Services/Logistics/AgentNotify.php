<?php

namespace App\Services\Logistics;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentBox;
use App\Models\ShipmentOrder;
use App\Services\WhatsApp\WhatsAppManager;

/**
 * WhatsApps the destination agent (Asish) their consignment briefing + no-login receive link
 * (/a/{token}). One composer, two callers: the automatic dispatch hook (ShipmentController) and
 * the manual "📨 Send the receive link" button on the consignment screen (ConsignmentController),
 * so the wording never forks. Wording adapts to the stage — "being loaded" before dispatch,
 * "dispatched" after.
 */
final class AgentNotify
{
    /** @return array{ok:bool,error?:string,phone?:string} */
    public function send(Shipment $s, ?string $phoneOverride = null): array
    {
        $tenant = $s->tenant;
        if (! $tenant || ! $s->token) {
            return ['ok' => false, 'error' => 'no_tenant_or_token'];
        }

        $phone = preg_replace('/[^0-9]/', '', (string) ($phoneOverride
            ?: ($s->destination_agent_phone ?: $tenant->setting('consignment_agent_phone', ''))));
        if ($phone === '') {
            return ['ok' => false, 'error' => 'no_phone'];
        }

        // A number given at send time becomes the shipment's agent number — the automatic
        // dispatch message then reaches the same person without retyping.
        if ($phoneOverride && $phone !== preg_replace('/[^0-9]/', '', (string) $s->destination_agent_phone)) {
            try { $s->destination_agent_phone = $phone; $s->save(); } catch (\Throwable $e) { /* best-effort */ }
        }

        $lines  = ShipmentOrder::where('shipment_id', $s->id)->get();
        $orders = $lines->count() ? Order::whereIn('id', $lines->pluck('order_id'))->get()->keyBy('id') : collect();
        $boxes  = ShipmentBox::where('shipment_id', $s->id)->orderBy('box_number')->get();

        $dispatched = ! in_array((string) $s->status, [ShipmentStateMachine::PACKED], true);
        $head = $dispatched
            ? "🚌 Consignment {$s->shipment_number} dispatched"
            : "📦 Consignment {$s->shipment_number} is being loaded for you";

        $msg = $head
            . ($s->bus_number ? " — bus {$s->bus_number}" : '')
            . ($s->transport_company ? " ({$s->transport_company})" : '') . "\n"
            . trim((string) $s->origin_city . ' → ' . (string) $s->destination_city, ' →') . "\n"
            . 'Boxes: ' . ((int) ($s->boxes_sent ?: $boxes->count())) . ' · Orders: ' . $lines->count();

        $shown = 0;
        foreach ($boxes as $b) {
            if ($shown >= 10) { $msg .= "\n…and " . ($boxes->count() - $shown) . ' more boxes (see manifest)'; break; }
            $who = implode(', ', array_values(array_filter(array_map(
                fn ($oid) => ($o = $orders->get((int) $oid)) ? (string) ($o->customer_name ?: $o->customer_phone) : '',
                (array) ($b->order_ids ?? [])
            ))));
            if ($who === '' && ! $b->packing && ! $b->note) continue;
            $msg .= "\n• Box {$b->box_number}" . ($b->packing ? " ({$b->packing})" : '') . ($who !== '' ? ": {$who}" : '')
                . ($b->note ? ' — ' . mb_substr((string) $b->note, 0, 60) : '');
            $shown++;
        }

        $msg .= "\n\nReceive & tick off here (no login):\n" . url('/a/' . $s->token);

        app(WhatsAppManager::class)->forTenant($tenant)->sendText($tenant->whatsapp_instance, $phone, $msg);

        return ['ok' => true, 'phone' => $phone];
    }
}
