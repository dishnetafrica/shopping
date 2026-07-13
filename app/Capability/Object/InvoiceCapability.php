<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CustomerPayment;
use App\Models\Invoice;

final class InvoiceCapability implements ObjectCapability
{
    public function key(): string { return 'invoice'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'invoice'])->meta(['capability' => 'invoice']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $i = Invoice::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $overdue = $i->due_at && $i->due_at->isPast() && (float) $i->balance > 0;
        $ep = '/api/v1/workspace/invoice/' . $i->id;

        $pays = CustomerPayment::where('tenant_id', $ctx->tenantId())
            ->where('invoice_no', $i->invoice_no)->orderByDesc('id')->limit(20)->get()
            ->map(fn ($p) => new TimelineEvent(
                (string) optional($p->created_at)->toDateString(), 'payment',
                'Payment ' . number_format((float) $p->amount) . ' ' . $p->currency, (string) $p->method, (string) $p->created_by,
            ))->all();

        $actions = [];
        if ((float) $i->balance > 0) {
            $actions[] = ['key' => 'record_payment', 'label' => 'Record payment', 'method' => 'POST', 'endpoint' => $ep . '/record_payment', 'actor' => 'human', 'tone' => 'primary'];
            $actions[] = ['key' => 'send_reminder', 'label' => 'Send reminder', 'method' => 'POST', 'endpoint' => $ep . '/send_reminder', 'actor' => 'human'];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'invoice', 'id' => (string) $i->id, 'ref' => $i->invoice_no, 'title' => 'Invoice ' . $i->invoice_no,
                'subtitle' => (string) $i->customer_name, 'status' => $overdue ? 'overdue' : (string) $i->status,
            ])
            ->headline('$' . number_format((float) $i->balance), 'of $' . number_format((float) $i->total) . ' due', (float) $i->balance > 0 ? 'warn' : 'ok')
            ->financials([
                new Financial('total', 'Total', round((float) $i->total, 2), $i->currency),
                new Financial('paid', 'Paid', round((float) $i->amount_paid, 2), $i->currency, 'ok'),
                new Financial('balance', 'Balance', round((float) $i->balance, 2), $i->currency, (float) $i->balance > 0 ? 'warn' : 'ok'),
            ])
            ->timeline($pays)
            ->related([new Related('customer', 'customer', null, (string) $i->customer_name, null, null)])
            ->documents($i->pdf_path ? [['type' => 'invoice', 'title' => 'Invoice PDF', 'url' => $i->pdf_path]] : [])
            ->actions($actions)
            ->meta(['capability' => 'invoice', 'ttl' => 30, 'permissions' => ['menu:finance']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'send_reminder' => ['ok' => true, 'sent' => true],
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }
}
