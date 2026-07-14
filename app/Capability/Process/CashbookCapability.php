<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;
use App\Models\User;
use App\Services\Money\CashService;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Multi-currency Cashbook & Cash Position — "how much USD / UGX / SSP do I have, and every cash
 * movement." Reads the trade cash ledger (order_costs, cash rows only) and computes per-currency
 * balances using the frozen CashService::IN_STAGES rule (so this never drifts from the panel's
 * cash-position screen). Recording and deleting entries goes through the panel's proven
 * /papi/trade-cashbook endpoints — no engine changes, no duplicated write logic.
 */
final class CashbookCapability implements ProcessCapability
{
    private const MAX_ROWS = 200;
    private const CURRENCIES = ['USD', 'UGX', 'SSP'];

    public function key(): string { return 'cashbook'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $fx = Fx::forTenant($ctx->tenant);
        $ugx = $fx->ratePerUsd('UGX');
        $ssp = $fx->ratePerUsd('SSP');

        // --- cash position per currency (approved rows, frozen IN_STAGES sign rule) ---
        $posRows = OrderCost::where('tenant_id', $t)->where('status', 'approved')
            ->select('currency', 'stage', DB::raw('SUM(paid_native) as native'), DB::raw('SUM(paid_native / NULLIF(rate_to_usd,0)) as usd'))
            ->groupBy('currency', 'stage')->get();
        $pos = ['USD' => ['native' => 0.0, 'usd' => 0.0], 'UGX' => ['native' => 0.0, 'usd' => 0.0], 'SSP' => ['native' => 0.0, 'usd' => 0.0]];
        foreach ($posRows as $r) {
            $sign = in_array($r->stage, CashService::IN_STAGES, true) ? 1 : -1;
            $c = (string) $r->currency;
            if (! isset($pos[$c])) { continue; }
            $pos[$c]['native'] += $sign * (float) $r->native;
            $pos[$c]['usd'] += $sign * (float) $r->usd;
        }
        $combinedUsd = round($pos['USD']['usd'] + $pos['UGX']['usd'] + $pos['SSP']['usd'], 2);

        $financials = [];
        foreach (self::CURRENCIES as $c) {
            $native = round($pos[$c]['native'], 2);
            $financials[] = [
                'key' => strtolower($c), 'label' => $c . ' cash', 'value' => Money::fmt($native, $c),
                'tone' => $native < 0 ? 'danger' : 'ok',
                'foot' => $c === 'USD' ? 'US dollars in hand' : ('≈ ' . Money::fmt(round($pos[$c]['usd'], 2), 'USD')),
            ];
        }
        $financials[] = ['key' => 'combined', 'label' => 'Combined (USD)', 'value' => Money::fmt($combinedUsd, 'USD'), 'tone' => $combinedUsd < 0 ? 'danger' : 'ok', 'foot' => 'all currencies at today\'s rate'];

        // --- entries (cash-affecting only: credit purchases move no cash) ---
        $entries = OrderCost::where('tenant_id', $t)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

        $stageLabels = ['cash_in' => 'Cash In', 'cash_out' => 'Cash Out', 'funding' => 'Funding', 'purchase' => 'Purchase',
            'customer_payment' => 'Receipt', 'drawing' => 'Drawing', 'supplier_payment' => 'Supplier Pay', 'expense' => 'Expense'];

        $rows = [];
        foreach ($entries as $c) {
            $isIn = in_array($c->stage, CashService::IN_STAGES, true);
            $cur = (string) $c->currency;
            $native = (float) $c->native_amount;
            $ref = in_array($c->stage, ['cash_in', 'cash_out'], true)
                ? 'CB-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)
                : ((string) ($c->order_no ?: strtoupper(substr((string) $c->stage, 0, 3)) . '-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $editable = in_array($c->stage, ['cash_in', 'cash_out', 'funding', 'purchase', 'expense', 'drawing', 'supplier_payment'], true);

            $rowBtns = ($editable && $ctx->can('menu:finance')) ? [[
                'icon' => 'trash', 'title' => 'Delete entry', 'tone' => 'danger',
                'post' => '/papi/trade-cashbook/' . $c->id . '/delete',
                'confirm' => 'Delete this ' . ($stageLabels[$c->stage] ?? $c->stage) . ' entry of ' . Money::fmt($native, $cur) . '? Cash balances update immediately.',
            ]] : [];

            $rows[] = [
                'when'     => ['value' => $when ? $when->format('d M H:i') : '—', 'sort' => $when ? $when->timestamp : 0],
                'ref'      => (string) $ref,
                'category' => (string) ($c->category ?: ($stageLabels[$c->stage] ?? ucfirst(str_replace('_', ' ', (string) $c->stage)))),
                'holder'   => (string) ($c->holder ?: '—'),
                'dir'      => ['value' => $isIn ? 'In' : 'Out', 'badge' => $isIn ? 'ok' : 'danger'],
                'amount'   => ['value' => ($isIn ? '+' : '−') . Money::fmt($native, $cur), 'tone' => $isIn ? 'ok' : 'danger', 'sort' => ($isIn ? 1 : -1) * $native],
                'usd'      => ['value' => Money::fmt((float) $c->usd_amount, 'USD'), 'sort' => (float) $c->usd_amount],
                'act'      => ['buttons' => $rowBtns],
                'currency' => $cur,
                'link'     => null,
            ];
        }

        $columns = [
            ['key' => 'when', 'label' => 'When', 'sortable' => true],
            ['key' => 'ref', 'label' => 'Ref', 'type' => 'strong', 'sortable' => true],
            ['key' => 'category', 'label' => 'Category', 'sortable' => true],
            ['key' => 'holder', 'label' => 'Held by', 'sortable' => true],
            ['key' => 'dir', 'label' => 'In/Out', 'type' => 'badge', 'sortable' => true],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'usd', 'label' => 'USD', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'act', 'label' => '', 'type' => 'action', 'sortable' => false],
        ];

        $rateMap = ['USD' => 1, 'UGX' => (int) round($ugx), 'SSP' => (int) round($ssp)];
        $cats = 'Customer payment|Funding|Local purchase|Transport|Salary|Rent|Utilities|Expense|Drawing|Other';
        $addCash = [
            'key' => 'add_cash', 'label' => 'Add cash entry', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
            'schema' => [
                'dir' => 'enum:in,out',
                'currency' => 'enum:UGX,USD,SSP',
                'native_amount' => 'money',
                'rate_to_usd' => 'number',
                'category' => 'combo:' . $cats,
                'note' => 'text',
            ],
            'prefill' => ['dir' => 'in', 'currency' => 'UGX', 'rate_to_usd' => (int) round($ugx)],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
        ];

        // Currency exchange — same in/out idea, across currencies (money leaves one, enters another).
        $fxExchange = [
            'key' => 'fx_exchange', 'label' => 'Currency exchange', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/convert', 'actor' => 'human', 'tone' => 'default',
            'schema' => [
                'from_currency' => 'enum:USD,UGX,SSP',
                'to_currency' => 'enum:UGX,USD,SSP',
                'from_amount' => 'money',
                'rate' => 'number',
                'note' => 'text',
            ],
            'prefill' => ['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => (int) round($ugx)],
        ];

        // Transfer cash between people (owner/manager). Only offered when there are ≥2 team members.
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')
            ->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        $transfer = count($members) >= 2 ? [
            'key' => 'cash_transfer', 'label' => 'Transfer cash', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/transfer', 'actor' => 'human', 'tone' => 'default',
            'schema' => [
                'from_person' => 'enum:' . implode(',', $members),
                'to_person' => 'enum:' . implode(',', $members),
                'currency' => 'enum:UGX,USD,SSP',
                'amount' => 'money',
                'note' => 'text',
            ],
            'prefill' => ['from_person' => $members[0], 'to_person' => $members[1]],
        ] : null;

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:cashbook', 'title' => 'Cashbook',
                'subtitle' => 'Cash position: ' . Money::fmt($combinedUsd, 'USD') . ' combined · ' . number_format($entries->count()) . ' recent entries',
            ])
            ->financials($financials)
            ->table($columns, $rows, [
                ['key' => 'currency', 'label' => 'Currency'],
                ['key' => 'dir', 'label' => 'In/Out'],
            ], ['pageSize' => 25])
            ->actions(array_values(array_filter([
                $addCash,
                $fxExchange,
                $transfer,
                ['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/cashbook', 'actor' => 'human', 'tone' => 'default'],
            ])))
            ->meta(['capability' => 'cashbook', 'ttl' => 15, 'permissions' => ['menu:finance']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
