<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Invoice;
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
        $cur = $ctx->baseCurrency();
        $now = Carbon::now();

        $invoices = Invoice::where('tenant_id', $t)
            ->where('status', '!=', 'void')
            ->orderByDesc('issued_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $outstanding = 0.0; $overdue = 0.0; $overdueCount = 0; $paid30 = 0.0;
        $payDaysSum = 0.0; $payDaysN = 0;
        $rows = [];

        foreach ($invoices as $i) {
            $total = (float) $i->total;
            $paid = (float) $i->amount_paid;
            $balance = (float) $i->balance;
            $due = $i->due_at ? Carbon::parse($i->due_at) : null;
            $issued = $i->issued_at ? Carbon::parse($i->issued_at) : null;
            $isOverdue = $balance > 0.01 && $due && $due->isPast();

            $outstanding += max(0, $balance);
            if ($isOverdue) { $overdue += $balance; $overdueCount++; }
            if ($issued && $issued->gte($now->copy()->subDays(30))) { $paid30 += $paid; }

            $status = $balance <= 0.01 ? 'Paid' : ($isOverdue ? 'Overdue' : ($paid > 0.01 ? 'Partial' : 'Open'));
            if ($status === 'Paid' && $issued && $i->updated_at) {
                $payDaysSum += max(0, $issued->diffInDays(Carbon::parse($i->updated_at))); $payDaysN++;
            }
            $badge = $status === 'Paid' ? 'ok' : ($status === 'Overdue' ? 'danger' : ($status === 'Partial' ? 'warn' : 'default'));

            $rows[] = [
                'invoice'  => (string) $i->invoice_no,
                'customer' => ['value' => (string) ($i->customer_name ?: 'Customer'), 'sub' => (string) ($i->customer_phone ?: ''), 'avatar' => true],
                'total'    => ['value' => Money::fmt($total, (string) ($i->currency ?: $cur)), 'sort' => $total],
                'paid'     => ['value' => Money::fmt($paid, (string) ($i->currency ?: $cur)), 'tone' => $paid > 0 ? 'ok' : '', 'sort' => $paid],
                'balance'  => $balance > 0.01
                    ? ['value' => Money::fmt($balance, (string) ($i->currency ?: $cur)), 'tone' => 'danger', 'sort' => $balance]
                    : ['value' => '—', 'sort' => 0],
                'due'      => ['value' => $due ? $due->format('d M Y') : '—', 'sort' => $due ? $due->timestamp : 0],
                'status'   => ['value' => $status, 'badge' => $badge],
                'link'     => '/api/v1/workspace/invoice/' . $i->id,
            ];
        }

        $avgDays = $payDaysN > 0 ? (int) round($payDaysSum / $payDaysN) : 0;

        $columns = [
            ['key' => 'invoice',  'label' => 'Invoice #', 'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer',  'type' => 'avatar', 'sortable' => true],
            ['key' => 'total',    'label' => 'Total',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'paid',     'label' => 'Paid',      'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'balance',  'label' => 'Balance',   'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'due',      'label' => 'Due date',  'sortable' => true],
            ['key' => 'status',   'label' => 'Status',    'type' => 'badge', 'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:invoices', 'title' => 'Invoices',
                'subtitle' => number_format($invoices->count()) . ' invoices · ' . Money::fmt($outstanding, $cur) . ' outstanding · ' . Money::fmt($overdue, $cur) . ' overdue',
            ])
            ->financials([
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => Money::fmt($outstanding, $cur), 'tone' => $outstanding > 0 ? 'warn' : 'ok', 'foot' => 'all open invoices'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => Money::fmt($overdue, $cur), 'tone' => $overdue > 0 ? 'danger' : 'ok', 'foot' => number_format($overdueCount) . ' invoices'],
                ['key' => 'paid30', 'label' => 'Paid (30d)', 'value' => Money::fmt($paid30, $cur), 'tone' => 'ok', 'foot' => 'issued in last 30 days'],
                ['key' => 'avgdays', 'label' => 'Avg. days to pay', 'value' => $avgDays . ' days', 'foot' => 'paid invoices'],
            ])
            ->table($columns, $rows)
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
