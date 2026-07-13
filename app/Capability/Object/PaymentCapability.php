<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CustomerPayment;

final class PaymentCapability implements ObjectCapability
{
    public function key(): string { return 'payment'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'payment'])->meta(['capability' => 'payment']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $p = CustomerPayment::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);

        return (new WorkspacePayload)
            ->object([
                'type' => 'payment', 'id' => (string) $p->id, 'ref' => $p->receipt_no, 'title' => $p->receipt_no,
                'subtitle' => (string) $p->customer_name . ' · ' . $p->method, 'status' => 'received',
            ])
            ->headline('$' . number_format((float) $p->amount), (string) $p->method, 'ok')
            ->financials([
                new Financial('amount', 'Amount', round((float) $p->amount, 2), $p->currency, 'ok'),
                new Financial('usd', 'USD', round((float) $p->usd_amount, 2), 'USD'),
            ])
            ->related(array_values(array_filter([
                $p->invoice_no ? new Related('invoice', 'invoice', null, 'Invoice ' . $p->invoice_no, null, null) : null,
                new Related('customer', 'customer', null, (string) $p->customer_name, null, null),
            ])))
            ->actions([['key' => 'send_receipt', 'label' => 'Send receipt', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/payment/' . $p->id . '/send_receipt', 'actor' => 'human', 'tone' => 'primary']])
            ->meta(['capability' => 'payment', 'ttl' => 300, 'permissions' => ['menu:finance']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'send_receipt' => ['ok' => true, 'sent' => true],
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }
}
