<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerPayment;
use Illuminate\Support\Carbon;

/**
 * Payments directory — list workspace. KPIs (received total / count / this month / average)
 * as aggregates; table shows the most recent receipts. Read-only.
 */
final class PaymentListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;

    public function key(): string { return 'payments'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();
        $now = Carbon::now();

        $count = CustomerPayment::where('tenant_id', $t)->count();
        $received = (float) CustomerPayment::where('tenant_id', $t)->selectRaw('COALESCE(SUM(amount),0) as v')->value('v');
        $monthTotal = (float) CustomerPayment::where('tenant_id', $t)
            ->where('created_at', '>=', $now->copy()->startOfMonth())->selectRaw('COALESCE(SUM(amount),0) as v')->value('v');
        $avg = $count > 0 ? $received / $count : 0.0;

        $payments = CustomerPayment::where('tenant_id', $t)->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($payments as $p) {
            $when = $p->created_at ? Carbon::parse($p->created_at) : null;
            $appliedTo = $p->invoice_no ?: ($p->order_no ?: '—');
            $method = (string) ($p->method ?: 'cash');
            $rows[] = [
                'receipt'  => (string) ($p->receipt_no ?: ('PAY-' . $p->id)),
                'customer' => ['value' => (string) ($p->customer_name ?: 'Customer'), 'sub' => (string) ($p->customer_phone ?: ''), 'avatar' => true],
                'method'   => ['value' => ucfirst($method), 'badge' => 'info'],
                'applied'  => (string) $appliedTo,
                'amount'   => ['value' => Money::fmt((float) $p->amount, (string) ($p->currency ?: $cur)), 'tone' => 'ok', 'sort' => (float) $p->amount],
                'date'     => ['value' => $when ? $when->format('d M Y') : '—', 'sort' => $when ? $when->timestamp : 0],
                'link'     => '/api/v1/workspace/payment/' . $p->id,
            ];
        }

        $columns = [
            ['key' => 'receipt',  'label' => 'Receipt #', 'type' => 'strong', 'sortable' => true],
            ['key' => 'customer', 'label' => 'Customer',  'type' => 'avatar', 'sortable' => true],
            ['key' => 'method',   'label' => 'Method',    'type' => 'badge', 'sortable' => true],
            ['key' => 'applied',  'label' => 'Applied to', 'sortable' => true],
            ['key' => 'amount',   'label' => 'Amount',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'date',     'label' => 'Date',      'sortable' => true],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:payments', 'title' => 'Payments',
                'subtitle' => number_format($count) . ' payments · ' . Money::fmt($received, $cur) . ' received',
            ])
            ->financials([
                ['key' => 'received', 'label' => 'Total received', 'value' => Money::fmt($received, $cur), 'tone' => 'ok', 'foot' => 'all time'],
                ['key' => 'count', 'label' => 'Payments', 'value' => $count, 'foot' => 'receipts recorded'],
                ['key' => 'month', 'label' => 'This month', 'value' => Money::fmt($monthTotal, $cur), 'tone' => 'ok', 'foot' => 'received this month'],
                ['key' => 'avg', 'label' => 'Avg. payment', 'value' => Money::fmt($avg, $cur), 'foot' => 'per receipt'],
            ])
            ->table($columns, $rows)
            ->actions([['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/payments', 'actor' => 'human', 'tone' => 'default']])
            ->meta(['capability' => 'payments', 'ttl' => 60, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['payments' => CustomerPayment::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
