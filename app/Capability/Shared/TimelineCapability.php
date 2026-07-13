<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Dto\TimelineEvent;
use App\Models\CustomerPayment;
use App\Models\Invoice;

final class TimelineCapability
{
    /** @return TimelineEvent[] */
    public function forPhone(CapabilityContext $ctx, string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        $events = [];

        Invoice::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('status', '!=', 'void')
            ->orderByDesc('id')->limit(20)->get()
            ->each(function ($inv) use (&$events) {
                $events[] = new TimelineEvent(
                    (string) optional($inv->issued_at)->toDateString(),
                    'invoice',
                    'Invoice ' . $inv->invoice_no . ' · ' . number_format((float) $inv->total),
                    (string) $inv->status,
                    null,
                    '/api/v1/workspace/invoice/' . $inv->id,
                );
            });

        CustomerPayment::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->orderByDesc('id')->limit(20)->get()
            ->each(function ($p) use (&$events) {
                $events[] = new TimelineEvent(
                    (string) optional($p->created_at)->toDateString(),
                    'payment',
                    'Payment ' . number_format((float) $p->amount) . ' ' . $p->currency,
                    (string) $p->method,
                    (string) $p->created_by,
                    null,
                );
            });

        usort($events, fn ($a, $b) => strcmp($b->at, $a->at));

        return $events;
    }
}
