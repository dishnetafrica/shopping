<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CustomerPayment;
use App\Services\Money\ReportingCurrency;
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
        $now = Carbon::now();
        // Payments are stored in each receipt's OWN currency; consolidate to the report currency
        // (never add UGX + USD raw and stamp one symbol on the sum).
        $rc = ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();

        $count = CustomerPayment::where('tenant_id', $t)->count();
        $byCur = CustomerPayment::where('tenant_id', $t)
            ->selectRaw("upper(coalesce(nullif(currency,''), ?)) as cur", [$native])
            ->selectRaw('COALESCE(SUM(amount),0) as v')->groupBy('cur')->get();
        $received = (float) $rc->consolidate($byCur->map(fn ($r) => ['amount' => (float) $r->v, 'currency' => (string) $r->cur])->all())['amount'];
        $byCurMonth = CustomerPayment::where('tenant_id', $t)->where('created_at', '>=', $now->copy()->startOfMonth())
            ->selectRaw("upper(coalesce(nullif(currency,''), ?)) as cur", [$native])
            ->selectRaw('COALESCE(SUM(amount),0) as v')->groupBy('cur')->get();
        $monthTotal = (float) $rc->consolidate($byCurMonth->map(fn ($r) => ['amount' => (float) $r->v, 'currency' => (string) $r->cur])->all())['amount'];
        $avg = $count > 0 ? $received / $count : 0.0;

        $payments = CustomerPayment::where('tenant_id', $t)->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($payments as $p) {
            $when = $p->created_at ? Carbon::parse($p->created_at) : null;
            $appliedTo = $p->invoice_no ?: ($p->order_no ?: '—');
            $method = (string) ($p->method ?: 'cash');
            $pcur = (string) ($p->currency ?: $native);
            $line = $rc->line((float) $p->amount, $pcur, null, $when ? $when->toDateString() : null);
            $rows[] = [
                'receipt'  => (string) ($p->receipt_no ?: ('PAY-' . $p->id)),
                'customer' => ['value' => (string) ($p->customer_name ?: 'Customer'), 'sub' => (string) ($p->customer_phone ?: ''), 'avatar' => true],
                'method'   => ['value' => ucfirst($method), 'badge' => 'info'],
                'applied'  => (string) $appliedTo,
                'amount'   => ['value' => $line['text'], 'tone' => 'ok', 'sort' => (float) $line['amount']],
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
                'subtitle' => number_format($count) . ' payments · ' . $rc->fmtConsolidated($received) . ' received',
            ])
            ->financials([
                ['key' => 'received', 'label' => 'Total received', 'value' => $rc->fmtConsolidated($received), 'tone' => 'ok', 'foot' => 'all time'],
                ['key' => 'count', 'label' => 'Payments', 'value' => $count, 'foot' => 'receipts recorded'],
                ['key' => 'month', 'label' => 'This month', 'value' => $rc->fmtConsolidated($monthTotal), 'tone' => 'ok', 'foot' => 'received this month'],
                ['key' => 'avg', 'label' => 'Avg. payment', 'value' => $rc->fmtConsolidated($avg), 'foot' => 'per receipt'],
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
