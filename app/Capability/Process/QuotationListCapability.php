<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Services\Sales\QuotationWriteService;
use Illuminate\Support\Carbon;

/**
 * Quotations directory — list workspace. KPIs (awaiting reply / accepted / conversion rate / open value)
 * plus a sortable table. Read-only; each row links to its converted order when one exists.
 *
 * NOTE: the prototype's "Prepared by" column has no column in the frozen quotations schema, so it is
 * omitted. Add a nullable quotations.prepared_by (or created_by) field to populate it.
 */
final class QuotationListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;

    public function key(): string { return 'quotations'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();

        $quotes = Quotation::where('tenant_id', $t)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $awaiting = 0; $accepted = 0; $converted = 0; $openValue = 0.0;
        $rows = [];

        foreach ($quotes as $q) {
            $status = strtolower((string) $q->status);
            if ($status === 'sent') { $awaiting++; $openValue += (float) $q->total; }
            if (in_array($status, ['accepted', 'converted'], true)) { $accepted++; }
            if ($status === 'converted') { $converted++; }

            $label = ucfirst($status);
            $badge = match ($status) {
                'accepted', 'converted' => 'ok',
                'declined' => 'danger',
                'expired' => 'default',
                'sent' => 'info',
                default => 'default',
            };
            $valid = $q->valid_until ? Carbon::parse($q->valid_until) : null;
            $created = $q->created_at ? Carbon::parse($q->created_at) : null;

            $rowBtns = [['icon' => 'download', 'title' => 'Download PDF', 'href' => '/papi/quotation-pdf?id=' . $q->id]];
            if ($status !== 'converted') {
                $rowBtns[] = ['icon' => 'trash', 'title' => 'Archive quotation', 'tone' => 'danger', 'post' => '/papi/quotation-delete', 'body' => ['id' => (int) $q->id], 'confirm' => 'Archive quotation ' . $q->quote_no . '? It is hidden from the active list but kept for records.'];
            }

            $rows[] = [
                'quote'    => (string) $q->quote_no,
                'customer' => ['value' => (string) ($q->customer_name ?: 'Customer'), 'sub' => (string) ($q->customer_phone ?: ''), 'avatar' => true],
                'items'    => (int) ($q->items_count ?? 0),
                'value'    => ['value' => Money::fmt((float) $q->total, (string) ($q->currency ?: $cur)), 'sort' => (float) $q->total],
                'valid'    => ['value' => $valid ? $valid->format('d M Y') : '—', 'sort' => $valid ? $valid->timestamp : 0],
                'status'   => ['value' => $label, 'badge' => $badge],
                'created'  => ['value' => $created ? $created->format('d M Y') : '—', 'sort' => $created ? $created->timestamp : 0],
                'act'      => ['buttons' => $rowBtns],
                'link'     => $q->order_id ? '/api/v1/workspace/salesOrder/' . $q->order_id : '/api/v1/workspace/quotation/' . $q->id,
            ];
        }

        $total = $quotes->count();
        $conv = $total > 0 ? round($converted / $total * 100) : 0;

        $columns = [
            ['key' => 'quote',    'label' => 'Quote #',    'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer',   'type' => 'avatar', 'sortable' => true],
            ['key' => 'items',    'label' => 'Items',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'value',    'label' => 'Value',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'valid',    'label' => 'Valid until', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',     'type' => 'badge', 'sortable' => true],
            ['key' => 'created',  'label' => 'Created',    'sortable' => true],
            ['key' => 'act',      'label' => '',           'type' => 'action', 'sortable' => false],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:quotations', 'title' => 'Quotations',
                'subtitle' => number_format($total) . ' quotations · ' . $conv . '% conversion rate',
            ])
            ->financials([
                ['key' => 'awaiting', 'label' => 'Awaiting reply', 'value' => $awaiting, 'tone' => $awaiting > 0 ? 'warn' : 'ok', 'foot' => 'sent, no reply'],
                ['key' => 'accepted', 'label' => 'Accepted', 'value' => $accepted, 'tone' => 'ok', 'foot' => 'accepted or converted'],
                ['key' => 'conversion', 'label' => 'Conversion rate', 'value' => $conv, 'format' => 'pct', 'foot' => $converted . ' converted to orders'],
                ['key' => 'openvalue', 'label' => 'Open value', 'value' => Money::fmt($openValue, $cur), 'foot' => 'awaiting reply'],
            ])
            ->table($columns, $rows, [], [
                'dateKey' => 'created', 'dateLabel' => 'Created', 'currency' => $cur, 'pageSize' => 20,
                'totals' => [['key' => 'value', 'label' => 'Total value', 'money' => true]],
            ])
            ->actions([
                ['key' => 'new_quotation', 'label' => 'New quotation', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/quotations/_/create_quotation',
                 'actor' => 'human', 'tone' => 'primary', 'builder' => 'quotation', 'pickCustomer' => true,
                 'customerSearch' => '/api/v1/workspace/customers/_/search', 'productSearch' => '/api/v1/workspace/products/_/search',
                 'currencies' => array_values(array_unique([$cur, 'USD', 'UGX', 'SSP', 'KES'])), 'prefill' => ['currency' => $cur]],
                ['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/quotations', 'actor' => 'human', 'tone' => 'default'],
            ])
            ->meta(['capability' => 'quotations', 'ttl' => 60, 'permissions' => ['menu:orders'], 'note' => 'Prepared-by column omitted — not in the frozen quotations schema.']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['quotations' => Quotation::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'create_quotation') {
            $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['customer_id'] ?? 0));
            if (! $c) {
                return ['ok' => false, 'error' => 'no_customer', 'message' => 'Choose a customer for the quotation.'];
            }
            $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
            $res = app(QuotationWriteService::class)->createFor(
                $ctx->tenant, (string) $c->phone, (string) $c->name, $lines,
                isset($input['currency']) ? (string) $input['currency'] : null,
                (bool) ($input['send_whatsapp'] ?? false),
            );
            if (! ($res['ok'] ?? false)) {
                return $res;
            }
            $res['message'] = 'Quotation ' . $res['quote_no'] . ' created for ' . ($c->name ?: $c->phone) . ($res['sent'] ? ' and sent on WhatsApp' : '') . '.';

            return $res;
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
