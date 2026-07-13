<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;
use App\Models\Supplier;

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

            $rows[] = [
                'supplier' => ['value' => (string) ($s->name ?: $s->business_name ?: 'Supplier'), 'sub' => (string) ($s->contact_name ?: ''), 'avatar' => true],
                'contact'  => (string) ($s->phone ?: $s->email ?: '—'),
                'terms'    => $s->pay_term_days ? ('Net ' . $s->pay_term_days) : 'Cash',
                'pos'      => $pos,
                'owed'     => $owed > 0
                    ? ['value' => Money::fmt($owed, (string) ($s->opening_currency ?: $cur)), 'tone' => 'warn', 'sort' => $owed]
                    : ['value' => '—', 'sort' => 0],
                'status'   => ['value' => $isActive ? 'Active' : 'Inactive', 'badge' => $isActive ? 'ok' : 'default'],
                'link'     => '/api/v1/workspace/supplier/' . $s->id,
            ];
        }

        $columns = [
            ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'contact',  'label' => 'Contact',  'sortable' => true],
            ['key' => 'terms',    'label' => 'Terms',    'sortable' => true],
            ['key' => 'pos',      'label' => 'Open POs', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'owed',     'label' => 'Owed',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
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
            ->actions([['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/suppliers', 'actor' => 'human', 'tone' => 'default']])
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
