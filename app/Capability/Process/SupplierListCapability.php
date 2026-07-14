<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\Fx;

/**
 * Suppliers directory — list workspace. Payable owed and open-PO count per supplier come from ONE
 * grouped query over order_costs, joined in memory to the supplier list. Read-only.
 */
final class SupplierListCapability implements ProcessCapability
{
    private const MAX_ROWS = 500;

    public function key(): string { return 'suppliers'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();

        $agg = OrderCost::where('tenant_id', $t)
            ->where('stage', 'purchase')->where('status', '!=', 'void')
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id')
            ->selectRaw('COALESCE(SUM(native_amount - COALESCE(paid_native,0)),0) as owed')
            ->selectRaw('COUNT(*) as pos')
            ->groupBy('supplier_id')
            ->get()->keyBy('supplier_id');

        $suppliers = Supplier::where('tenant_id', $t)->orderBy('name')->limit(self::MAX_ROWS)->get();

        // people pick-list + rates for the quick "record bill" / "pay" row actions
        $fx = Fx::forTenant($ctx->tenant);
        $rateMap = ['USD' => 1, 'UGX' => (int) round($fx->ratePerUsd('UGX')), 'SSP' => (int) round($fx->ratePerUsd('SSP'))];
        $me = (string) ($ctx->user->name ?? '');
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        if ($me !== '') { $members = array_values(array_unique(array_merge([$me], $members))); }
        $comboPeople = 'combo:' . (count($members) ? implode('|', $members) : ($me ?: 'Me'));

        $totalPayable = 0.0; $active = 0; $withBalance = 0;
        $rows = [];
        foreach ($suppliers as $s) {
            $a = $agg[$s->id] ?? null;
            $owed = max(0.0, (float) ($a->owed ?? 0));
            $pos = (int) ($a->pos ?? 0);
            $isActive = $s->active !== false;
            if ($isActive) { $active++; }
            $totalPayable += $owed;
            if ($owed > 0) { $withBalance++; }

            $canEdit = $ctx->can('menu:purchases');
            $scur = (string) ($s->opening_currency ?: $cur);
            $srate = $rateMap[$scur] ?? 1;
            $rowBtns = [];
            if ($canEdit) {
                $rowBtns[] = ['icon' => 'cart', 'title' => 'Record bill from ' . $s->name, 'action' => [
                    'key' => 'record_bill', 'label' => 'Record purchase — ' . $s->name, 'method' => 'POST', 'endpoint' => '/papi/order-costs', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'note' => 'text', 'holder' => $comboPeople],
                    'labels' => ['native_amount' => 'Bill amount', 'rate_to_usd' => 'Rate (native per $1)', 'pay_status' => 'Credit (owe) or cash (paid now)', 'note' => 'Item / description', 'holder' => 'Paid by (if cash)'],
                    'prefill' => ['currency' => $scur, 'rate_to_usd' => $srate, 'pay_status' => 'credit', 'holder' => $me],
                    'body' => ['stage' => 'purchase', 'supplier_id' => $s->id],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                    'autoNote' => ['template' => 'Purchase from ' . $s->name, 'watch' => []],
                ]];
                $rowBtns[] = ['icon' => 'dollar', 'title' => 'Pay ' . $s->name, 'action' => [
                    'key' => 'pay_supplier', 'label' => 'Pay ' . $s->name, 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
                    'labels' => ['amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Paid by (whose cash)'],
                    'prefill' => ['currency' => $scur, 'rate_to_usd' => $srate, 'holder' => $me],
                    'body' => ['dir' => 'out', 'category' => 'Supplier payment', 'supplier_id' => $s->id],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                    'autoNote' => ['template' => 'Paid ' . $s->name, 'watch' => []],
                ]];
                $rowBtns[] = ['icon' => 'edit', 'title' => 'Edit ' . $s->name, 'action' => [
                    'key' => 'edit_supplier', 'label' => 'Edit ' . $s->name, 'method' => 'POST', 'endpoint' => '/papi/suppliers/' . $s->id, 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['name' => 'text', 'contact_name' => 'text', 'phone' => 'text', 'email' => 'text', 'tax_number' => 'text', 'pay_term_days' => 'number', 'opening_balance' => 'money', 'opening_currency' => 'enum:,UGX,USD,SSP', 'address' => 'text'],
                    'labels' => ['name' => 'Business name', 'contact_name' => 'Contact person', 'pay_term_days' => 'Pay term (credit days)', 'opening_balance' => 'Opening balance owed', 'opening_currency' => 'Opening currency'],
                    'prefill' => ['name' => (string) ($s->business_name ?: $s->name), 'contact_name' => (string) $s->contact_name, 'phone' => (string) $s->phone, 'email' => (string) $s->email, 'tax_number' => (string) $s->tax_number, 'pay_term_days' => $s->pay_term_days, 'opening_balance' => $s->opening_balance, 'opening_currency' => (string) $s->opening_currency, 'address' => (string) $s->address],
                ]];
                $rowBtns[] = ['icon' => 'trash', 'title' => 'Delete', 'tone' => 'danger', 'post' => '/papi/suppliers/' . $s->id . '/delete', 'confirm' => 'Delete supplier ' . $s->name . '? (kept & deactivated if it has history)'];
            }

            $rows[] = [
                'supplier' => ['value' => (string) ($s->name ?: $s->business_name ?: 'Supplier'), 'sub' => (string) ($s->contact_name ?: ''), 'avatar' => true],
                'contact'  => (string) ($s->phone ?: $s->email ?: '—'),
                'terms'    => $s->pay_term_days ? ('Net ' . $s->pay_term_days) : 'Cash',
                'owed'     => $owed > 0
                    ? ['value' => Money::fmt($owed, (string) ($s->opening_currency ?: $cur)), 'tone' => 'warn', 'sort' => $owed]
                    : ['value' => '—', 'sort' => 0],
                'status'   => ['value' => $isActive ? 'Active' : 'Inactive', 'badge' => $isActive ? 'ok' : 'default'],
                'act'      => ['buttons' => $rowBtns],
                'link'     => '/api/v1/workspace/supplier/' . $s->id,
            ];
        }

        $columns = [
            ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'contact',  'label' => 'Contact',  'sortable' => true],
            ['key' => 'terms',    'label' => 'Terms',    'sortable' => true],
            ['key' => 'owed',     'label' => 'Owed',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
            ['key' => 'act',      'label' => '', 'type' => 'action', 'align' => 'right'],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:suppliers', 'title' => 'Suppliers',
                'subtitle' => number_format($suppliers->count()) . ' suppliers · ' . Money::fmt($totalPayable, $cur) . ' payable',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Total suppliers', 'value' => $suppliers->count(), 'foot' => number_format($active) . ' active'],
                ['key' => 'payable', 'label' => 'Total payable', 'value' => Money::fmt($totalPayable, $cur), 'tone' => $totalPayable > 0 ? 'warn' : 'ok', 'foot' => 'owed across suppliers'],
                ['key' => 'withbal', 'label' => 'With balance', 'value' => $withBalance, 'tone' => $withBalance > 0 ? 'warn' : 'ok', 'foot' => 'suppliers owed money'],
                ['key' => 'active', 'label' => 'Active', 'value' => $active, 'tone' => 'ok', 'foot' => 'currently trading'],
            ])
            ->table($columns, $rows)
            ->actions([[
                'key' => 'add_supplier', 'label' => 'Add supplier', 'method' => 'POST', 'endpoint' => '/papi/suppliers', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['name' => 'text', 'contact_name' => 'text', 'phone' => 'text', 'email' => 'text', 'tax_number' => 'text', 'pay_term_days' => 'number', 'opening_balance' => 'money', 'opening_currency' => 'enum:,UGX,USD,SSP', 'address' => 'text'],
                'labels' => ['name' => 'Business name', 'contact_name' => 'Contact person', 'pay_term_days' => 'Pay term (credit days)', 'opening_balance' => 'Opening balance owed', 'opening_currency' => 'Opening currency'],
                'prefill' => ['opening_currency' => ''],
            ]])
            ->meta(['capability' => 'suppliers', 'ttl' => 60, 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['suppliers' => Supplier::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
