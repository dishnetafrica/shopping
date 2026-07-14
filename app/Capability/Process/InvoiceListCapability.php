<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
use App\Services\Money\ReportingCurrency;
use Illuminate\Support\Carbon;

/**
 * Invoices directory — list workspace. Headline KPIs (outstanding / overdue / paid-30d / avg days to pay)
 * plus a sortable table. Read-only; each row links to the Invoice 360. Void invoices are excluded.
 */
final class InvoiceListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;

    public function key(): string { return 'invoices'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $rc = ReportingCurrency::forContext($ctx);
        $cur = $rc->consolidationCurrency();   // KPI tiles + grid totals are always ONE currency
        $now = Carbon::now();

        $invoices = Invoice::where('tenant_id', $t)
            ->where('status', '!=', 'void')
            ->orderByDesc('issued_at')
            ->limit(self::MAX_ROWS)
            ->get();

        // KPI accumulators are collected as {amount,currency,date} rows and consolidated at the end,
        // so mixed-currency invoices never get naively added into one wrong number.
        $outRows = []; $ovRows = []; $paid30Rows = [];
        $overdueCount = 0; $payDaysSum = 0.0; $payDaysN = 0;
        $rows = [];

        foreach ($invoices as $i) {
            $icur = (string) ($i->currency ?: $cur);
            $total = (float) $i->total;
            $paid = (float) $i->amount_paid;
            $balance = (float) $i->balance;
            $due = $i->due_at ? Carbon::parse($i->due_at) : null;
            $issued = $i->issued_at ? Carbon::parse($i->issued_at) : null;
            $date = $issued ? $issued->format('Y-m-d') : null;
            $isOverdue = $balance > 0.01 && $due && $due->isPast();

            if ($balance > 0.01) { $outRows[] = ['amount' => $balance, 'currency' => $icur, 'date' => $date]; }
            if ($isOverdue) { $ovRows[] = ['amount' => $balance, 'currency' => $icur, 'date' => $date]; $overdueCount++; }
            if ($issued && $issued->gte($now->copy()->subDays(30))) { $paid30Rows[] = ['amount' => $paid, 'currency' => $icur, 'date' => $date]; }

            $status = $balance <= 0.01 ? 'Paid' : ($isOverdue ? 'Overdue' : ($paid > 0.01 ? 'Partial' : 'Open'));
            if ($status === 'Paid' && $issued && $i->updated_at) {
                $payDaysSum += max(0, $issued->diffInDays(Carbon::parse($i->updated_at))); $payDaysN++;
            }
            $badge = $status === 'Paid' ? 'ok' : ($status === 'Overdue' ? 'danger' : ($status === 'Partial' ? 'warn' : 'default'));

            // Displayed text follows the report mode (native in Original, converted otherwise);
            // the hidden 'sort' is always the consolidated figure so column sort + footer totals are one currency.
            $totalLine = $rc->line($total, $icur, null, $date);
            $paidLine  = $rc->line($paid, $icur, null, $date);
            $balLine   = $rc->line($balance, $icur, null, $date);
            $cons = fn ($amt) => $rc->consolidate([['amount' => $amt, 'currency' => $icur, 'date' => $date]])['amount'];

            $isVoid = strtolower((string) $i->status) === 'void';
            $rowBtns = [['icon' => 'download', 'title' => 'Download PDF', 'href' => '/papi/invoice-pdf?id=' . $i->id]];
            if (! $isVoid) {
                $rowBtns[] = ['icon' => 'send', 'title' => 'Send on WhatsApp', 'post' => '/papi/invoice-send', 'body' => ['id' => (int) $i->id], 'confirm' => 'Send invoice ' . $i->invoice_no . ' to the customer on WhatsApp?'];
                $rowBtns[] = ['icon' => 'ban', 'title' => 'Void invoice', 'tone' => 'danger', 'post' => '/papi/invoice-void', 'body' => ['id' => (int) $i->id], 'confirm' => 'Void invoice ' . $i->invoice_no . '? It stays on record but is marked void.'];
            }

            $rows[] = [
                'invoice'  => (string) $i->invoice_no,
                'customer' => ['value' => (string) ($i->customer_name ?: 'Customer'), 'sub' => (string) ($i->customer_phone ?: ''), 'avatar' => true],
                'total'    => ['value' => $totalLine['text'], 'sort' => $cons($total)],
                'paid'     => ['value' => $paidLine['text'], 'tone' => $paid > 0 ? 'ok' : '', 'sort' => $cons($paid)],
                'balance'  => $balance > 0.01
                    ? ['value' => $balLine['text'], 'tone' => 'danger', 'sort' => $cons($balance)]
                    : ['value' => '—', 'sort' => 0],
                'issued'   => ['value' => $issued ? $issued->format('d M Y') : '—', 'sort' => $issued ? $issued->timestamp : 0],
                'due'      => ['value' => $due ? $due->format('d M Y') : '—', 'sort' => $due ? $due->timestamp : 0],
                'status'   => ['value' => $status, 'badge' => $badge],
                'act'      => ['buttons' => $rowBtns],
                'link'     => '/api/v1/workspace/invoice/' . $i->id,
            ];
        }

        $outstanding = $rc->consolidate($outRows)['amount'];
        $overdue = $rc->consolidate($ovRows)['amount'];
        $paid30 = $rc->consolidate($paid30Rows)['amount'];
        $avgDays = $payDaysN > 0 ? (int) round($payDaysSum / $payDaysN) : 0;

        $columns = [
            ['key' => 'invoice',  'label' => 'Invoice #', 'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer',  'type' => 'avatar', 'sortable' => true],
            ['key' => 'total',    'label' => 'Total',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'paid',     'label' => 'Paid',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'balance',  'label' => 'Amount due', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'issued',   'label' => 'Created',   'sortable' => true],
            ['key' => 'due',      'label' => 'Due date',  'sortable' => true],
            ['key' => 'status',   'label' => 'Status',    'type' => 'badge', 'sortable' => true],
            ['key' => 'act',      'label' => '',          'type' => 'action', 'sortable' => false],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:invoices', 'title' => 'Invoices',
                'subtitle' => number_format($invoices->count()) . ' invoices · ' . Money::fmt($outstanding, $cur) . ' outstanding · ' . Money::fmt($overdue, $cur) . ' overdue · ' . $rc->modeLabel(),
            ])
            ->financials([
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => Money::fmt($outstanding, $cur), 'tone' => $outstanding > 0 ? 'warn' : 'ok', 'foot' => 'all open invoices'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => Money::fmt($overdue, $cur), 'tone' => $overdue > 0 ? 'danger' : 'ok', 'foot' => number_format($overdueCount) . ' invoices'],
                ['key' => 'paid30', 'label' => 'Paid (30d)', 'value' => Money::fmt($paid30, $cur), 'tone' => 'ok', 'foot' => 'issued in last 30 days'],
                ['key' => 'avgdays', 'label' => 'Avg. days to pay', 'value' => $avgDays . ' days', 'foot' => 'paid invoices'],
            ])
            ->table($columns, $rows, [], [
                'dateKey' => 'issued', 'dateLabel' => 'Created', 'currency' => $cur, 'pageSize' => 20,
                'totals' => [
                    ['key' => 'total', 'label' => 'Total', 'money' => true],
                    ['key' => 'balance', 'label' => 'Amount due', 'money' => true],
                ],
            ])
            ->actions([['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/invoices', 'actor' => 'human', 'tone' => 'default']])
            ->meta(['capability' => 'invoices', 'ttl' => 60, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['invoices' => Invoice::where('tenant_id', $ctx->tenantId())->where('status', '!=', 'void')->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
