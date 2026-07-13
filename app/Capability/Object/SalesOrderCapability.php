<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Invoicing\InvoiceService;

/**
 * Sales Order Workspace. NOTE: production stores shopping orders in `Order` and trade/margin lines in
 * `OrderCost`. This capability is the single adapter seam for that duality (M4 decision). For M1 it
 * reads the `Order` header; the OrderCost margin lines fold in here when the adapter lands.
 */
final class SalesOrderCapability implements ObjectCapability
{
    public function key(): string { return 'salesOrder'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'salesOrder'])->meta(['capability' => 'salesOrder']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $inv = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->first();
        $delivered = $o->delivered_at !== null;

        return (new WorkspacePayload)
            ->object([
                'type' => 'salesOrder', 'id' => (string) $o->id, 'ref' => $o->order_no, 'title' => 'Order ' . $o->order_no,
                'subtitle' => (string) $o->customer_name, 'status' => (string) $o->status,
            ])
            ->headline(Money::fmt((float) $o->total, (string) ($o->sell_currency ?: $ctx->baseCurrency())), $delivered ? 'delivered' : 'in progress', 'default')
            ->financials([
                new Financial('total', 'Total', round((float) $o->total, 2), (string) ($o->sell_currency ?: $ctx->baseCurrency())),
                new Financial('paid', 'Paid', round((float) $o->amount_paid, 2), (string) ($o->sell_currency ?: $ctx->baseCurrency()), 'ok'),
            ])
            ->related(array_values(array_filter([
                new Related('customer', 'customer', null, (string) $o->customer_name, null, null),
                $inv ? new Related('invoice', 'invoice', (string) $inv->id, 'Invoice ' . $inv->invoice_no, Money::fmt((float) $inv->balance, (string) ($inv->currency ?: $ctx->baseCurrency())) . ' due', null, '/api/v1/workspace/invoice/' . $inv->id) : null,
            ])))
            ->actions($this->actionsFor($o, $delivered, $inv))
            ->meta(['capability' => 'salesOrder', 'ttl' => 30, 'permissions' => ['menu:orders']]);
    }

    /** Backend-driven buttons: deliver an open order, or invoice a delivered one. */
    private function actionsFor(Order $o, bool $delivered, ?Invoice $inv): array
    {
        $closed = in_array(strtolower((string) $o->status), ['cancelled', 'rejected'], true);
        $acts = [];
        if (! $delivered && ! $closed) {
            $acts[] = ['key' => 'mark_delivered', 'label' => 'Mark delivered', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/salesOrder/' . $o->id . '/mark_delivered', 'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Mark Order ' . $o->order_no . ' as delivered? An invoice is issued automatically.'];
        }
        if ($delivered && ! $inv) {
            $acts[] = ['key' => 'create_invoice', 'label' => 'Create invoice', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/salesOrder/' . $o->id . '/create_invoice', 'actor' => 'human', 'tone' => 'primary'];
        }

        return $acts;
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'mark_delivered' => $this->markDelivered($ctx, $input),
            'create_invoice' => $this->createInvoice($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /**
     * Flip the order to Delivered and save. The frozen OrderObserver does the rest: it stamps
     * delivered_at, auto-issues the numbered invoice, and fires the customer notification.
     */
    private function markDelivered(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));

        if (in_array(strtolower((string) $o->status), ['cancelled', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'closed', 'message' => 'This order is cancelled and cannot be delivered.'];
        }
        if ($o->delivered_at !== null) {
            return ['ok' => true, 'message' => 'Order already delivered.'];
        }

        $o->status = 'Delivered';
        $o->save();

        return ['ok' => true, 'message' => 'Order ' . $o->order_no . ' marked delivered — invoice issued.'];
    }

    /**
     * Issue an invoice for a delivered order. Delegates entirely to the frozen InvoiceService
     * (which renders the invoice from the order and books it) — no invoice logic duplicated here.
     */
    private function createInvoice(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));

        if ($o->delivered_at === null) {
            return ['ok' => false, 'error' => 'not_delivered', 'message' => 'Only delivered orders can be invoiced.'];
        }
        $existing = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->where('status', '!=', 'void')->first();
        if ($existing) {
            return ['ok' => true, 'invoice_no' => (string) $existing->invoice_no, 'message' => 'Invoice already exists.', 'reused' => true];
        }

        $inv = app(InvoiceService::class)->issueForOrder($ctx->tenant, $o, 'v3');

        return ['ok' => true, 'invoice_no' => (string) $inv->invoice_no, 'invoice_id' => (int) $inv->id, 'message' => 'Invoice ' . $inv->invoice_no . ' created.'];
    }
}
