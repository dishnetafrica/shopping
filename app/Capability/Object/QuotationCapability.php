<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Financial;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Quotation;
use App\Services\Sales\QuotationConvertService;
use Illuminate\Support\Carbon;

/** Quotation 360 — view a quote and convert it into an order (delegates to QuotationConvertService). */
final class QuotationCapability implements ObjectCapability
{
    private const OPEN = ['sent', 'accepted', 'draft', 'pending'];

    public function key(): string { return 'quotation'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'quotation'])->meta(['capability' => 'quotation']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $q = Quotation::where('tenant_id', $ctx->tenantId())->withCount('items')->find((int) $id);
        if (! $q) {
            return (new WorkspacePayload)->object(['type' => 'quotation', 'title' => 'Quotation not found'])->meta(['capability' => 'quotation']);
        }
        $cur = (string) ($q->currency ?: $ctx->baseCurrency());
        $status = strtolower((string) $q->status);
        $valid = $q->valid_until ? Carbon::parse($q->valid_until) : null;

        $related = [];
        if ($q->order_id) {
            $related[] = new Related('order', 'salesOrder', (string) $q->order_id, 'Converted order', null, null, '/api/v1/workspace/salesOrder/' . $q->order_id);
        }

        $canConvert = ! $q->order_id && in_array($status, self::OPEN, true);
        $actions = $canConvert ? [[
            'key' => 'convert_to_order', 'label' => 'Convert to order', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/quotation/' . $q->id . '/convert_to_order',
            'actor' => 'human', 'tone' => 'primary',
            'confirm' => 'Convert quotation ' . $q->quote_no . ' into an order?',
        ]] : [];

        return (new WorkspacePayload)
            ->object([
                'type' => 'quotation', 'id' => (string) $q->id, 'ref' => (string) $q->quote_no,
                'title' => 'Quote ' . $q->quote_no, 'subtitle' => (string) ($q->customer_name ?: $q->customer_phone),
                'status' => ucfirst($status),
            ])
            ->headline(Money::fmt((float) $q->total, $cur), $q->order_id ? 'converted to order' : 'quotation total', $q->order_id ? 'ok' : 'default')
            ->financials([
                new Financial('total', 'Total', round((float) $q->total, 2), $cur),
                new Financial('items', 'Line items', (int) ($q->items_count ?? 0)),
                new Financial('valid', 'Valid until', $valid ? $valid->format('d M Y') : '—'),
            ])
            ->related($related)
            ->actions($actions)
            ->meta(['capability' => 'quotation', 'ttl' => 30, 'permissions' => ['menu:orders']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'convert_to_order') {
            return ['ok' => false, 'error' => 'unknown_action:' . $action];
        }
        $q = Quotation::where('tenant_id', $ctx->tenantId())->with('items')->find((int) ($input['id'] ?? 0));
        if (! $q) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        try {
            $res = app(QuotationConvertService::class)->convert($ctx->tenant, $q);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'This quotation has no priced items to convert.'];
        }
        $o = $res['order'];
        $no = $o ? (string) $o->order_no : '';

        return ['ok' => true, 'order_id' => $o ? (int) $o->id : null, 'message' => ($res['already'] ? 'Already converted — order ' : 'Converted to order ') . $no . '.'];
    }
}
