<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;
use App\Models\User;
use App\Services\Money\CashService;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Multi-currency Cashbook & Cash Position — "how much USD / UGX / SSP do I have, WHO holds it, and
 * every cash movement." Reads the trade cash ledger (order_costs, cash rows only) and computes
 * per-currency AND per-person balances with the frozen CashService::IN_STAGES rule, so it never
 * drifts from the panel's cash-position screen. Add / exchange / transfer / edit / delete all go
 * through the panel's proven /papi/trade-cashbook endpoints — no engine changes, no duplicated logic.
 */
final class CashbookCapability implements ProcessCapability
{
    private const MAX_ROWS = 200;
    private const CURRENCIES = ['USD', 'UGX', 'SSP'];
    private const CATEGORIES = 'Customer payment|Funding|Local purchase|Transport|Salary|Rent|Utilities|Expense|Drawing|Other';

    public function key(): string { return 'cashbook'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $fx = Fx::forTenant($ctx->tenant);
        $ugx = $fx->ratePerUsd('UGX');
        $ssp = $fx->ratePerUsd('SSP');
        $rateMap = ['USD' => 1, 'UGX' => (int) round($ugx), 'SSP' => (int) round($ssp)];
        $isOwner = method_exists($ctx->user, 'isOwnerLike') ? $ctx->user->isOwnerLike() : true;
        $me = (string) ($ctx->user->name ?? '');

        // Base query: approved cash rows (credit purchases move no cash → excluded). Staff see only theirs.
        $scoped = fn ($q) => $isOwner ? $q : $q->where(fn ($w) => $w->where('holder_user_id', $ctx->user->id)->orWhere('holder', $me));

        // --- cash position: per currency AND per holder (frozen IN_STAGES sign rule) ---
        $posRows = $scoped(OrderCost::where('tenant_id', $t)->where('status', 'approved'))
            ->select('currency', 'stage', 'holder', DB::raw('SUM(paid_native) as native'), DB::raw('SUM(paid_native / NULLIF(rate_to_usd,0)) as usd'))
            ->groupBy('currency', 'stage', 'holder')->get();

        $pos = ['USD' => ['native' => 0.0, 'usd' => 0.0], 'UGX' => ['native' => 0.0, 'usd' => 0.0], 'SSP' => ['native' => 0.0, 'usd' => 0.0]];
        $holders = [];   // holder => ['USD'=>x,'UGX'=>y,'SSP'=>z,'usd'=>total]
        foreach ($posRows as $r) {
            $sign = in_array($r->stage, CashService::IN_STAGES, true) ? 1 : -1;
            $c = (string) $r->currency;
            $native = $sign * (float) $r->native;
            $usd = $sign * (float) $r->usd;
            if (isset($pos[$c])) { $pos[$c]['native'] += $native; $pos[$c]['usd'] += $usd; }
            $h = trim((string) ($r->holder ?: '')) ?: 'Unassigned';
            if (! isset($holders[$h])) { $holders[$h] = ['USD' => 0.0, 'UGX' => 0.0, 'SSP' => 0.0, 'usd' => 0.0]; }
            if (isset($holders[$h][$c])) { $holders[$h][$c] += $native; }
            $holders[$h]['usd'] += $usd;
        }
        $combinedUsd = round($pos['USD']['usd'] + $pos['UGX']['usd'] + $pos['SSP']['usd'], 2);

        // Rail: per-currency position cards.
        $financials = [];
        foreach (self::CURRENCIES as $c) {
            $native = round($pos[$c]['native'], 2);
            $financials[] = ['key' => strtolower($c), 'label' => $c . ' cash', 'value' => Money::fmt($native, $c),
                'tone' => $native < -0.01 ? 'danger' : 'ok',
                'foot' => $c === 'USD' ? 'in hand' : ('≈ ' . Money::fmt(round($pos[$c]['usd'], 2), 'USD'))];
        }
        $financials[] = ['key' => 'combined', 'label' => 'Combined (USD)', 'value' => Money::fmt($combinedUsd, 'USD'), 'tone' => $combinedUsd < -0.01 ? 'danger' : 'ok', 'foot' => 'all currencies'];

        // --- "Cash by person" table (who holds what) ---
        $holderRows = [];
        foreach ($holders as $h => $bal) {
            if (abs($bal['USD']) < 0.01 && abs($bal['UGX']) < 0.01 && abs($bal['SSP']) < 0.01) { continue; }
            $holderRows[] = ['cells' => [
                $h,
                ['value' => Money::fmt(round($bal['USD'], 2), 'USD'), 'tone' => $bal['USD'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['UGX'], 2), 'UGX'), 'tone' => $bal['UGX'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['SSP'], 2), 'SSP'), 'tone' => $bal['SSP'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['usd'], 2), 'USD'), 'tone' => $bal['usd'] < -0.01 ? 'danger' : 'ok'],
            ], 'usd' => round($bal['usd'], 2), 'link' => null];
        }
        usort($holderRows, fn ($a, $b) => $b['usd'] <=> $a['usd']);
        $holderCard = ['kind' => 'table', 'title' => 'Cash by person', 'sub' => count($holderRows) . ' holding cash', 'searchable' => true,
            'columns' => [['label' => 'Held by'], ['label' => 'USD', 'align' => 'right'], ['label' => 'UGX', 'align' => 'right'], ['label' => 'SSP', 'align' => 'right'], ['label' => 'Total (USD)', 'align' => 'right']],
            'rows' => $holderRows, 'empty' => 'No cash recorded yet — add an entry to get started', 'pageSize' => 20];

        // --- entries (with edit + delete per row) ---
        $entries = $scoped(OrderCost::where('tenant_id', $t)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status')))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

        $stageLabels = ['cash_in' => 'Cash In', 'cash_out' => 'Cash Out', 'funding' => 'Funding', 'purchase' => 'Purchase',
            'customer_payment' => 'Receipt', 'drawing' => 'Drawing', 'supplier_payment' => 'Supplier Pay', 'expense' => 'Expense'];

        $canEdit = $ctx->can('menu:finance') && $isOwner;
        $entryRows = [];
        foreach ($entries as $c) {
            $isIn = in_array($c->stage, CashService::IN_STAGES, true);
            $cur = (string) $c->currency;
            $native = (float) $c->native_amount;
            $ref = in_array($c->stage, ['cash_in', 'cash_out'], true)
                ? 'CB-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)
                : ((string) ($c->order_no ?: strtoupper(substr((string) $c->stage, 0, 3)) . '-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $simple = in_array($c->stage, ['cash_in', 'cash_out', 'funding', 'purchase', 'expense', 'drawing'], true);

            $btns = [];
            if ($canEdit && $simple) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit entry', 'action' => [
                    'key' => 'edit_cash', 'label' => 'Edit cash entry ' . $ref, 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/' . $c->id . '/update', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'category' => 'combo:' . self::CATEGORIES, 'holder' => 'text', 'note' => 'text'],
                    'prefill' => ['amount' => round($native, 2), 'currency' => $cur, 'rate_to_usd' => (float) $c->rate_to_usd, 'category' => (string) $c->category, 'holder' => (string) $c->holder, 'note' => (string) $c->note],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                ]];
            }
            if ($canEdit && $simple) {
                $btns[] = ['icon' => 'trash', 'title' => 'Delete entry', 'tone' => 'danger', 'post' => '/papi/trade-cashbook/' . $c->id . '/delete',
                    'confirm' => 'Delete this ' . ($stageLabels[$c->stage] ?? $c->stage) . ' of ' . Money::fmt($native, $cur) . '?'];
            }

            $entryRows[] = ['cells' => [
                $when ? $when->format('d M H:i') : '—',
                $ref,
                (string) ($c->category ?: ($stageLabels[$c->stage] ?? ucfirst(str_replace('_', ' ', (string) $c->stage)))),
                (string) ($c->holder ?: '—'),
                ['value' => $isIn ? 'In' : 'Out', 'badge' => $isIn ? 'ok' : 'danger'],
                ['value' => ($isIn ? '+' : '−') . Money::fmt($native, $cur), 'tone' => $isIn ? 'ok' : 'danger'],
                Money::fmt((float) $c->usd_amount, 'USD'),
                ['buttons' => $btns],
            ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
        }
        $entriesCard = ['kind' => 'table', 'title' => 'All cash entries', 'sub' => count($entryRows) . ' recent', 'searchable' => true, 'dateFilter' => true,
            'columns' => [['label' => 'When'], ['label' => 'Ref'], ['label' => 'Category'], ['label' => 'Held by'], ['label' => 'In/Out'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'USD', 'align' => 'right'], ['label' => '', 'align' => 'right']],
            'rows' => $entryRows, 'empty' => 'No cash entries yet', 'pageSize' => 25];

        // --- actions (in/out, exchange, transfer) ---
        $addCash = ['key' => 'add_cash', 'label' => 'Add cash entry', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['dir' => 'enum:in,out', 'currency' => 'enum:UGX,USD,SSP', 'native_amount' => 'money', 'rate_to_usd' => 'number', 'category' => 'combo:' . self::CATEGORIES, 'holder' => 'text', 'note' => 'text'],
            'prefill' => ['dir' => 'in', 'currency' => 'UGX', 'rate_to_usd' => (int) round($ugx), 'holder' => $me],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];
        $fxExchange = ['key' => 'fx_exchange', 'label' => 'Currency exchange', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/convert', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['from_currency' => 'enum:USD,UGX,SSP', 'to_currency' => 'enum:UGX,USD,SSP', 'from_amount' => 'money', 'rate' => 'number', 'holder' => 'text', 'note' => 'text'],
            'prefill' => ['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => (int) round($ugx), 'holder' => $me]];
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        $transfer = count($members) >= 2 ? ['key' => 'cash_transfer', 'label' => 'Transfer cash', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/transfer', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['from_person' => 'enum:' . implode(',', $members), 'to_person' => 'enum:' . implode(',', $members), 'currency' => 'enum:UGX,USD,SSP', 'amount' => 'money', 'note' => 'text'],
            'prefill' => ['from_person' => $members[0], 'to_person' => $members[1]]] : null;
        $exportAct = ['key' => 'export', 'label' => 'Export', 'href' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default'];

        $actions = array_values(array_filter([$addCash, $fxExchange, $transfer]));

        return (new WorkspacePayload)
            ->object(['type' => 'process:cashbook', 'title' => 'Cashbook', 'subtitle' => $isOwner ? 'Cash position, who holds what, and every movement' : 'My cash'])
            ->headline(Money::fmt($combinedUsd, 'USD'), 'combined cash (USD)', $combinedUsd < -0.01 ? 'danger' : 'ok')
            ->brief(new Brief('low',
                'You hold ' . Money::fmt(round($pos['UGX']['native'], 2), 'UGX') . ', ' . Money::fmt(round($pos['USD']['native'], 2), 'USD') . ' and ' . Money::fmt(round($pos['SSP']['native'], 2), 'SSP') . '.',
                'Record cash in/out, exchange between currencies, or transfer cash between people. Every entry updates the position instantly.',
                ['tone' => 'info', 'label' => count($holderRows) . ' holding cash']))
            ->financials($financials)
            ->quickActions($actions)
            ->tabSections(['holders' => [$holderCard], 'entries' => [$entriesCard]])
            ->tabs([
                ['key' => 'holders', 'label' => 'Cash by person', 'count' => count($holderRows)],
                ['key' => 'entries', 'label' => 'All entries', 'count' => count($entryRows)],
            ])
            ->actions($actions)
            ->more([$exportAct])
            ->meta(['capability' => 'cashbook', 'ttl' => 15, 'layout' => 'record', 'listKey' => 'cashbook', 'permissions' => ['menu:finance']]);
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
