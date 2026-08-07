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
        // Each quote is in its own currency; consolidate the KPI + grid totals to the report currency.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $cur = $rc->consolidationCurrency();

        $quotes = Quotation::where('tenant_id', $t)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ROWS)
            ->get();

        // v4.57 — the quotation's own status stops being maintained the moment an order exists, so the
        // outcome is read from the money instead: quote → order → invoice → what was actually paid.
        // Two queries for the whole page rather than one per quote.
        $orderIds = $quotes->pluck('order_id')->filter()->unique()->values();
        $paidByOrder = [];
        if ($orderIds->isNotEmpty()) {
            \App\Models\Invoice::where('tenant_id', $t)
                ->whereIn('order_id', $orderIds)->where('status', '!=', 'void')
                ->get(['order_id', 'total', 'amount_paid'])
                ->each(function ($inv) use (&$paidByOrder) {
                    $k = (int) $inv->order_id;
                    $paidByOrder[$k] ??= ['total' => 0.0, 'paid' => 0.0];
                    $paidByOrder[$k]['total'] += (float) $inv->total;
                    $paidByOrder[$k]['paid']  += (float) $inv->amount_paid;
                });
        }

        $awaiting = 0; $accepted = 0; $converted = 0; $openRows = [];
        $stageRows = []; $stuckCount = 0; $uninvoiced = 0;
        $rows = [];

        foreach ($quotes as $q) {
            $status = strtolower((string) $q->status);
            $qcur = (string) ($q->currency ?: $native);
            $qdate = $q->created_at ? Carbon::parse($q->created_at)->toDateString() : null;
            // Revised quotes are still awaiting the customer (v4.6.3 — they were falling out of every bucket).
            if (in_array($status, ['sent', 'revised', 'customer_revised'], true)) { $awaiting++; $openRows[] = ['amount' => (float) $q->total, 'currency' => $qcur, 'date' => $qdate]; }
            if (in_array($status, ['accepted', 'converted'], true)) { $accepted++; }
            if ($status === 'converted') { $converted++; }

            $inv = $q->order_id ? ($paidByOrder[(int) $q->order_id] ?? null) : null;
            $stage = \App\Services\Growth\QuoteToCash::stage([
                'status' => $status,
                'sent' => (int) ($q->send_count ?? 0) > 0 || $q->last_sent_at !== null,
                'viewed' => $q->viewed_at !== null,
                'accepted' => $q->accepted_at !== null,
                'orderId' => $q->order_id,
                'invoiced' => $inv !== null,
                'invoiceTotal' => (float) ($inv['total'] ?? 0),
                'invoicePaid' => (float) ($inv['paid'] ?? 0),
            ]);
            $stageRows[] = ['stage' => $stage, 'value' => $rc->consolidate([['amount' => (float) $q->total, 'currency' => $qcur, 'date' => $qdate]])['amount']];
            if (\App\Services\Growth\QuoteToCash::isStuck($stage)) { $stuckCount++; }
            if ($stage === 'won_uninvoiced') { $uninvoiced++; }

            $label = ucfirst(str_replace('_', ' ', $status));   // "customer_revised" → "Customer revised"
            $badge = match ($status) {
                'accepted', 'converted' => 'ok',
                'declined' => 'danger',
                'expired' => 'default',
                'sent', 'revised', 'customer_revised' => 'info',
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
                'value'    => ['value' => $rc->line((float) $q->total, $qcur, null, $qdate)['text'], 'sort' => (float) $rc->consolidate([['amount' => (float) $q->total, 'currency' => $qcur, 'date' => $qdate]])['amount']],
                'valid'    => ['value' => $valid ? $valid->format('d M Y') : '—', 'sort' => $valid ? $valid->timestamp : 0],
                'status'   => ['value' => $label, 'badge' => $badge],
                // v4.57 — what happened to the MONEY, which the status field stops tracking once an
                // order exists. "Ordered, never invoiced" is the one nothing else in the system shows:
                // Collections reads invoices, and for these there is no invoice to read.
                'outcome'  => ['value' => \App\Services\Growth\QuoteToCash::LABEL[$stage] ?? '—',
                               'badge' => match ($stage) {
                                   'paid' => 'ok',
                                   'won_uninvoiced', 'invoiced_unpaid' => 'danger',
                                   'part_paid' => 'warn',
                                   'lost', 'expired' => 'default',
                                   default => 'info',
                               }],
                'created'  => ['value' => $created ? $created->format('d M Y') : '—', 'sort' => $created ? $created->timestamp : 0],
                'act'      => ['buttons' => $rowBtns],
                'link'     => $q->order_id ? '/api/v1/workspace/salesOrder/' . $q->order_id : '/api/v1/workspace/quotation/' . $q->id,
                'id'       => (int) $q->id,
            ];
        }

        $total = $quotes->count();
        $conv = $total > 0 ? round($converted / $total * 100) : 0;

        $funnel = \App\Services\Growth\QuoteToCash::funnel($stageRows);
        $cash   = \App\Services\Growth\QuoteToCash::collected($funnel);

        $columns = [
            ['key' => 'quote',    'label' => 'Quote #',    'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer',   'type' => 'avatar', 'sortable' => true],
            ['key' => 'items',    'label' => 'Items',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'value',    'label' => 'Value',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'valid',    'label' => 'Valid until', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',     'type' => 'badge', 'sortable' => true],
            ['key' => 'outcome',  'label' => 'Outcome',    'type' => 'badge', 'sortable' => true],
            ['key' => 'created',  'label' => 'Created',    'sortable' => true],
            ['key' => 'act',      'label' => '',           'type' => 'action', 'sortable' => false],
        ];

        // Asset Lifecycle · S8b — build a project quote straight from a kit (each kit = a priced section).
        $projectAction = null;
        $kitList = \App\Models\Product::where('tenant_id', $t)->where('is_kit', true)->orderBy('name')->limit(50)->get(['id', 'name']);
        if ($kitList->isNotEmpty()) {
            $projectAction = [
                'key' => 'new_project_quote', 'label' => 'New project quote', 'method' => 'POST',
                'endpoint' => '/papi/project-quotes/from-kits', 'actor' => 'human', 'tone' => 'default',
                'schema' => ['kit_ids' => 'combo:' . $kitList->map(fn ($k) => $k->id . ' — ' . $k->name)->implode('|'),
                    'customer_name' => 'text', 'customer_phone' => 'text'],
                'labels' => ['kit_ids' => 'System / kit', 'customer_name' => 'Customer name', 'customer_phone' => 'Customer WhatsApp'],
            ];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:quotations', 'title' => 'Quotations',
                'subtitle' => number_format($total) . ' quotations · ' . $conv . '% conversion rate',
            ])
            ->financials([
                ['key' => 'awaiting', 'label' => 'Awaiting reply', 'value' => $awaiting, 'tone' => $awaiting > 0 ? 'warn' : 'ok', 'foot' => 'sent, no reply'],
                ['key' => 'accepted', 'label' => 'Accepted', 'value' => $accepted, 'tone' => 'ok', 'foot' => 'accepted or converted'],
                ['key' => 'conversion', 'label' => 'Turned into orders', 'value' => $conv, 'format' => 'pct', 'foot' => $converted . ' of ' . $quotes->count() . ' quotations'],
                // The number that was missing. The one above counts orders; this one counts money that
                // arrived, so a quote that became an order nobody invoiced stops reading as a win.
                ['key' => 'banked', 'label' => 'Actually paid', 'value' => round($cash['rate'] * 100, 1), 'format' => 'pct',
                 'tone' => $cash['rate'] >= 0.5 ? 'ok' : 'warn',
                 'foot' => 'of quoted value, collected'],
                ['key' => 'stuck', 'label' => 'Won but not collected', 'value' => \App\Capability\Support\Money::fmt($cash['stuck'], $cur),
                 'tone' => $cash['stuck'] > 0 ? 'danger' : 'ok',
                 'foot' => $stuckCount . ' quotations' . ($uninvoiced > 0 ? ' · ' . $uninvoiced . ' never invoiced' : '')],
                ['key' => 'openvalue', 'label' => 'Open value', 'value' => $rc->totalText($openRows), 'foot' => 'awaiting reply'],
            ])
            ->table($columns, $rows, [], [
                'selectable' => true,
                'bulk' => [
                    ['label' => 'Export CSV', 'method' => 'GET', 'endpoint' => '/papi/export/quotations?format=csv'],
                    ['label' => 'Export PDF', 'method' => 'GET', 'endpoint' => '/papi/export/quotations?format=pdf'],
                    ['label' => 'Send on WhatsApp', 'method' => 'POST', 'endpoint' => '/papi/quotations/bulk-send', 'tone' => 'primary', 'confirm' => 'Send {n} quotation PDF(s) to their customers on WhatsApp?'],
                ],
                'dateKey' => 'created', 'dateLabel' => 'Created', 'currency' => $cur, 'pageSize' => 20,
                'totals' => [['key' => 'value', 'label' => 'Total value', 'money' => true]],
            ])
            ->actions(array_values(array_filter([
                ['key' => 'new_quotation', 'label' => 'New quotation', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/quotations/_/create_quotation',
                 'actor' => 'human', 'tone' => 'primary', 'builder' => 'quotation', 'pickCustomer' => true,
                 'customerSearch' => '/api/v1/workspace/customers/_/search', 'productSearch' => '/api/v1/workspace/products/_/search',
                 'currencies' => array_values(array_unique([$cur, 'USD', 'UGX', 'SSP', 'KES'])), 'prefill' => ['currency' => $cur]],
                $projectAction,
                ['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/quotations', 'actor' => 'human', 'tone' => 'default'],
            ])))
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
