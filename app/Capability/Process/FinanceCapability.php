<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Financial;
use App\Capability\Shared\MoneyTimingCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\ExpenseCategory;
use App\Models\OrderCost;
use Illuminate\Support\Carbon;

/** Finance workspace — cash, receivables, payables, runway. Merges the legacy cash screens. */
final class FinanceCapability implements ProcessCapability
{
    public function __construct(private MoneyTimingCapability $money) {}

    public function key(): string { return 'finance'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $m = $this->money->position($ctx);
        $t = $ctx->tenantId();

        // Parity fix (v3 retire audit 2026-07-18): /panel/expenses redirects here, so
        // recording expenses must work HERE. Same engine: POST /papi/expenses
        // (ExpenseController::store — order_no attaches to order margin, blank = overhead).
        $expenses = OrderCost::where('tenant_id', $t)->where('stage', 'expense')
            ->orderByDesc('id')->limit(30)->get();
        $monthExp = (float) OrderCost::where('tenant_id', $t)->where('stage', 'expense')
            ->where('created_at', '>=', now()->startOfMonth())->sum('usd_amount');
        $expRows = $expenses->map(fn ($e) => [
            'date' => ['value' => Carbon::parse($e->created_at)->format('d M'), 'sort' => Carbon::parse($e->created_at)->timestamp],
            'cat' => ['value' => (string) ($e->expense_category ?: '—')],
            'note' => ['value' => mb_substr((string) ($e->note ?: ''), 0, 40)],
            'by' => ['value' => (string) ($e->holder ?: $e->created_by ?: '—')],
            'status' => ['value' => ucfirst((string) $e->status),
                'badge' => ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'danger'][$e->status] ?? 'default'],
            'amount' => ['value' => $e->currency.' '.number_format((float) $e->native_amount, 0).' · $'.number_format((float) $e->usd_amount, 2), 'sort' => (float) $e->usd_amount],
        ])->all();
        $cats = ExpenseCategory::where('tenant_id', $t)->where('active', true)
            ->orderBy('sort')->pluck('name');
        $recordExpense = ['key' => 'record_expense', 'label' => 'Record expense', 'method' => 'POST',
            'endpoint' => '/papi/expenses', 'actor' => 'human', 'tone' => 'primary',
            'schema' => [
                'native_amount' => 'number',
                'currency' => 'enum:USD,UGX,SSP',
                'rate_to_usd' => 'number',
                'expense_category' => $cats->count() ? 'combo:'.$cats->implode('|') : 'text',
                'note' => 'text',
                'order_no' => 'text',
            ],
            'labels' => [
                'native_amount' => 'Amount (in the chosen currency)',
                'currency' => 'Currency',
                'rate_to_usd' => 'Rate to USD (enter 1 for USD)',
                'expense_category' => 'Category',
                'note' => 'Note',
                'order_no' => 'Order no. (optional — books it into that order\'s margin; blank = overhead)',
            ],
            'prefill' => ['currency' => 'UGX', 'rate_to_usd' => 0]];

        return (new WorkspacePayload)
            ->object(['type' => 'process:finance', 'title' => 'Finance'])
            ->headline(Money::fmt($m['cash'], 'USD'), 'cash on hand', 'ok')
            ->financials([
                new Financial('cash', 'Cash on hand', $m['cash'], 'USD', 'ok'),
                new Financial('receivable', 'Receivable', $m['receivable'], 'USD', 'default'),
                new Financial('payable', 'Payable', $m['payable'], 'USD', 'warn'),
                new Financial('runway', 'Runway', $m['runway'] ?? '∞', null, ($m['runway'] !== null && $m['runway'] < 14) ? 'warn' : 'ok', 'days'),
            ])
            ->table(
                [
                    ['key' => 'date', 'label' => 'Date', 'type' => 'num', 'sortable' => true],
                    ['key' => 'cat', 'label' => 'Category', 'sortable' => true],
                    ['key' => 'note', 'label' => 'Note'],
                    ['key' => 'by', 'label' => 'By'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'amount', 'label' => 'Amount', 'type' => 'num', 'align' => 'right', 'sortable' => true],
                ],
                $expRows,
                [['key' => 'status', 'label' => 'Status'], ['key' => 'cat', 'label' => 'Category']],
                ['pageSize' => 15, 'title' => 'Recent expenses',
                    'sub' => '$'.number_format($monthExp, 2).' this month · newest '.count($expRows),
                    'empty' => 'No expenses yet — "Record expense" books overhead, or attach an order number to book into that order\'s margin.'])
            ->actions([
                $recordExpense,
                ['key' => 'export_pdf', 'label' => 'Cashbook PDF', 'href' => '/api/v1/doc/cashbook-export?format=pdf', 'actor' => 'human', 'tone' => 'default'],
                ['key' => 'export_csv', 'label' => 'Cashbook CSV (Excel)', 'href' => '/api/v1/doc/cashbook-export?format=csv', 'actor' => 'human', 'tone' => 'default'],
            ])
            ->meta(['capability' => 'finance', 'ttl' => 45, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return $this->money->position($ctx);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
