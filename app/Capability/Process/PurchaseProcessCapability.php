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
use Illuminate\Support\Carbon;

/**
 * Purchasing — every purchase (bill) across all suppliers in one list. Real data only:
 * order_costs at stage='purchase'. Each row shows date, supplier, item, amount, cash/credit
 * and outstanding, with per-row edit / delete / pay and a header "Record purchase".
 *
 * There are no formal purchase-order or goods-receipt documents in the engine, so this screen
 * deliberately does not fake them — a "purchase" here is a recorded bill, exactly what the
 * frozen /papi/order-costs endpoint stores.
 */
final class PurchaseProcessCapability implements ProcessCapability
{
    private const MAX_ROWS = 300;

    public function key(): string { return 'purchase.process'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $cur = $ctx->baseCurrency();
        $canEdit = $ctx->can('menu:purchases');

        $fx = Fx::forTenant($ctx->tenant);
        $rateMap = ['USD' => 1, 'UGX' => (int) round($fx->ratePerUsd('UGX')), 'SSP' => (int) round($fx->ratePerUsd('SSP'))];

        // people pick-list for "paid by" / holder on the quick row + header actions
        $me = (string) ($ctx->user->name ?? '');
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        if ($me !== '') { $members = array_values(array_unique(array_merge([$me], $members))); }
        $comboPeople = 'combo:' . (count($members) ? implode('|', $members) : ($me ?: 'Me'));

        // supplier pick-list (for the header "Record purchase" picker → maps name to supplier_id)
        $suppliers = Supplier::where('tenant_id', $t)->orderBy('name')->limit(1000)->get();
        $supNameById = [];
        $supMap = [];       // display name → id
        $supNames = [];
        foreach ($suppliers as $s) {
            $nm = trim((string) ($s->name ?: $s->business_name ?: ('Supplier #' . $s->id)));
            $supNameById[$s->id] = $nm;
            // keep the first supplier that claims a name; append id when a name repeats
            $disp = isset($supMap[$nm]) ? ($nm . ' #' . $s->id) : $nm;
            $supMap[$disp] = $s->id;
            $supNames[] = $disp;
        }
        $comboSuppliers = 'combo:' . (count($supNames) ? implode('|', $supNames) : '—');

        // --- all purchases across suppliers ---
        $purchases = OrderCost::where('tenant_id', $t)
            ->where('stage', 'purchase')->where('status', '!=', 'void')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::MAX_ROWS)->get();

        $monthStart = Carbon::now()->startOfMonth();
        $totalSpendUsd = 0.0; $payableUsd = 0.0; $monthSpendUsd = 0.0; $creditCount = 0; $cashCount = 0;
        $rows = [];
        foreach ($purchases as $c) {
            $voided = in_array((string) $c->status, ['void', 'rejected'], true);
            $native = (float) $c->native_amount;
            $paid = (float) ($c->paid_native ?? 0);
            $outstanding = max(0.0, $native - $paid);
            $usd = (float) $c->usd_amount ?: OrderCost::toUsd($native, (float) $c->rate_to_usd);
            $isCredit = strtolower((string) $c->pay_status) === 'credit';
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $ref = (string) ($c->order_no ?: ('PUR-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $supName = $supNameById[$c->supplier_id] ?? (string) ($c->supplier_name ?: '—');
            $ccur = (string) ($c->currency ?: $cur);

            if (! $voided) {
                $totalSpendUsd += $usd;
                $payableUsd += ($outstanding > 0 && $c->rate_to_usd > 0) ? ($outstanding / (float) $c->rate_to_usd) : 0;
                if ($when && $when->greaterThanOrEqualTo($monthStart)) { $monthSpendUsd += $usd; }
                $isCredit ? $creditCount++ : $cashCount++;
            }

            $btns = [];
            if ($canEdit && ! $voided) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit purchase', 'action' => [
                    'key' => 'edit_purchase', 'label' => 'Edit purchase ' . $ref, 'method' => 'POST', 'endpoint' => '/papi/order-costs/' . $c->id . '/update', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['note' => 'text', 'native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'pay_status' => 'enum:credit,cash', 'reason' => 'text'],
                    'labels' => ['note' => 'Item / description', 'native_amount' => 'Amount', 'pay_status' => 'Credit or cash', 'reason' => 'Reason (for audit)'],
                    'prefill' => ['note' => (string) $c->note, 'native_amount' => round($native, 2), 'currency' => $ccur, 'pay_status' => $isCredit ? 'credit' : 'cash'],
                ]];
                if ($isCredit && $outstanding > 0) {
                    $srate = $rateMap[$ccur] ?? ((float) $c->rate_to_usd ?: 1);
                    $btns[] = ['icon' => 'dollar', 'title' => 'Pay ' . $supName, 'action' => [
                        'key' => 'pay_supplier', 'label' => 'Pay ' . $supName, 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
                        'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
                        'labels' => ['amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Paid by (whose cash)'],
                        'prefill' => ['currency' => $ccur, 'rate_to_usd' => $srate, 'amount' => round($outstanding, 2), 'holder' => $me],
                        'body' => ['dir' => 'out', 'category' => 'Supplier payment', 'supplier_id' => $c->supplier_id],
                        'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                        'autoNote' => ['template' => 'Paid ' . $supName, 'watch' => []],
                    ]];
                }
                $btns[] = ['icon' => 'trash', 'title' => 'Delete purchase', 'tone' => 'danger', 'post' => '/papi/order-costs/' . $c->id . '/delete', 'confirm' => 'Delete purchase ' . $ref . '? (voided, kept in the audit trail)'];
            }

            $payCell = $voided
                ? ['value' => 'Void', 'badge' => 'default']
                : ($isCredit
                    ? ($outstanding > 0
                        ? ['value' => 'Owe ' . Money::fmt($outstanding, $ccur), 'badge' => 'warn', 'sort' => $outstanding]
                        : ['value' => 'Credit · paid', 'badge' => 'ok', 'sort' => 0])
                    : ['value' => 'Cash', 'badge' => 'ok', 'sort' => 0]);

            $rows[] = [
                'date'     => ['value' => $when ? $when->format('d M Y') : '—', 'sort' => $when ? $when->timestamp : 0],
                'supplier' => ['value' => $supName, 'sub' => $ref, 'avatar' => true],
                'item'     => (string) ($c->note ?: ucfirst((string) $c->stage)),
                'amount'   => ['value' => ($voided ? '(void) ' : '') . Money::fmt($native, $ccur), 'sub' => Money::fmt($usd, 'USD'), 'tone' => $voided ? '' : 'danger', 'sort' => $usd],
                'pay'      => $payCell,
                'act'      => ['buttons' => $btns],
                'link'     => $c->supplier_id ? ('/api/v1/workspace/supplier/' . $c->supplier_id) : null,
            ];
        }

        $columns = [
            ['key' => 'date',     'label' => 'Date', 'type' => 'num', 'sortable' => true],
            ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'item',     'label' => 'Item', 'sortable' => true],
            ['key' => 'amount',   'label' => 'Amount', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'pay',      'label' => 'Status', 'type' => 'badge', 'sortable' => true],
            ['key' => 'act',      'label' => '', 'type' => 'action', 'align' => 'right'],
        ];

        $recordPurchase = ['key' => 'record_purchase', 'label' => 'Record purchase', 'method' => 'POST', 'endpoint' => '/papi/order-costs', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['supplier' => $comboSuppliers, 'native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'note' => 'text', 'holder' => $comboPeople],
            'labels' => ['supplier' => 'Supplier', 'native_amount' => 'Bill amount', 'rate_to_usd' => 'Rate (native per $1)', 'pay_status' => 'Credit (owe) or cash (paid now)', 'note' => 'Item / description', 'holder' => 'Paid by (if cash)'],
            'prefill' => ['currency' => $cur, 'rate_to_usd' => $rateMap[$cur] ?? 1, 'pay_status' => 'credit', 'holder' => $me],
            'body' => ['stage' => 'purchase'],
            'valueMap' => ['field' => 'supplier', 'into' => 'supplier_id', 'map' => $supMap, 'error' => 'Pick a supplier from the list (or add them first in Suppliers).'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => 'Purchase — {supplier}', 'watch' => ['supplier']]];

        $unpaid = collect($rows)->filter(fn ($r) => ($r['pay']['badge'] ?? '') === 'warn')->count();

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:purchase', 'title' => 'Purchasing',
                'subtitle' => number_format($purchases->count()) . ' purchases · ' . Money::fmt($payableUsd, 'USD') . ' still owed',
            ])
            ->financials([
                ['key' => 'count', 'label' => 'Purchases', 'value' => number_format($purchases->count()), 'foot' => $cashCount . ' cash · ' . $creditCount . ' credit'],
                ['key' => 'payable', 'label' => 'Still owed', 'value' => Money::fmt($payableUsd, 'USD'), 'tone' => $payableUsd > 0 ? 'warn' : 'ok', 'foot' => $unpaid . ' bills unpaid'],
                ['key' => 'month', 'label' => 'This month', 'value' => Money::fmt(round($monthSpendUsd, 2), 'USD'), 'foot' => 'purchased since ' . $monthStart->format('d M')],
                ['key' => 'total', 'label' => 'Total (shown)', 'value' => Money::fmt(round($totalSpendUsd, 2), 'USD'), 'foot' => 'last ' . self::MAX_ROWS . ' purchases'],
            ])
            ->table($columns, $rows)
            ->actions($canEdit ? [$recordPurchase] : [])
            ->meta(['capability' => 'purchase.process', 'ttl' => 30, 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $rows = OrderCost::where('tenant_id', $ctx->tenantId())
            ->where('stage', 'purchase')->where('status', '!=', 'void')
            ->orderByDesc('id')->limit(self::MAX_ROWS)->get();
        $payable = $rows->sum(function ($c) {
            $out = max(0.0, (float) $c->native_amount - (float) ($c->paid_native ?? 0));
            return ($out > 0 && $c->rate_to_usd > 0) ? $out / (float) $c->rate_to_usd : 0;
        });

        return ['purchaseCount' => $rows->count(), 'payable' => round((float) $payable, 2)];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
