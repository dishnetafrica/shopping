<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use App\Models\Order;

/** Sales pipeline: open orders and delivered-but-not-invoiced (revenue at risk). */
final class SalesProcessCapability implements ProcessCapability
{
    public function key(): string { return 'sales.process'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $ocur = fn ($o) => (string) ($o->sell_currency ?: $native);
        $odate = fn ($o) => $o->delivered_at ? \Illuminate\Support\Carbon::parse($o->delivered_at)->toDateString() : null;

        $toInvoice = $this->toInvoice($ctx);
        $related = $toInvoice->take(20)->map(fn ($o) => (new Related(
            'to_invoice', 'salesOrder', (string) $o->id, (string) $o->customer_name,
            $o->order_no . ' · delivered · ' . $rc->line((float) $o->total, $ocur($o), null, $odate($o))['text'], null,
            '/api/v1/workspace/salesOrder/' . $o->id,
        ))->toArray())->values()->all();

        // consolidate delivered-not-invoiced value to the report currency (don't add mixed currencies raw)
        $value = $rc->totalText($toInvoice->map(fn ($o) => ['amount' => (float) $o->total, 'currency' => $ocur($o), 'date' => $odate($o)])->all());

        return (new WorkspacePayload)
            ->object(['type' => 'process:sales', 'title' => 'Sales pipeline', 'status' => $toInvoice->count() . ' to invoice'])
            ->headline($value, 'delivered, not invoiced', 'warn')
            ->related($related)
            ->meta(['capability' => 'sales.process', 'ttl' => 30, 'permissions' => ['menu:orders']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $ti = $this->toInvoice($ctx);
        $open = Order::where('tenant_id', $ctx->tenantId())->whereNull('delivered_at')
            ->whereNotIn('status', ['cancelled', 'rejected'])->count();
        $value = $rc->consolidate($ti->map(fn ($o) => [
            'amount' => (float) $o->total,
            'currency' => (string) ($o->sell_currency ?: $native),
            'date' => $o->delivered_at ? \Illuminate\Support\Carbon::parse($o->delivered_at)->toDateString() : null,
        ])->all())['amount'];

        return ['openOrders' => $open, 'toInvoice' => $ti->count(), 'toInvoiceValue' => round((float) $value, 2)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    private function toInvoice(CapabilityContext $ctx)
    {
        $invoicedOrderIds = Invoice::where('tenant_id', $ctx->tenantId())->whereNotNull('order_id')->pluck('order_id')->all();

        return Order::where('tenant_id', $ctx->tenantId())
            ->whereNotNull('delivered_at')
            ->whereNotIn('id', $invoicedOrderIds ?: [0])
            ->orderByDesc('delivered_at')->limit(100)->get();
    }
}
