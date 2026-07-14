<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\Fx;
use App\Services\Purchasing\SupplierPaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SupplierCapability implements ObjectCapability
{
    public function key(): string { return 'supplier'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'supplier'])->meta(['capability' => 'supplier']);
    }

    private const CURRENCIES = ['USD', 'UGX', 'SSP'];

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $s = Supplier::where('tenant_id', $t)->findOrFail((int) $id);
        $fx = Fx::forTenant($ctx->tenant);
        $rateMap = ['USD' => 1, 'UGX' => (int) round($fx->ratePerUsd('UGX')), 'SSP' => (int) round($fx->ratePerUsd('SSP'))];
        $baseCur = (string) ($s->opening_currency ?: $ctx->baseCurrency());
        $canEdit = $ctx->can('menu:purchases');

        // --- balance owed (USD): credit bills + opening − payments (mirrors the panel debt screen) ---
        $creditUsd = (float) OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('pay_status', 'credit')->whereNotIn('status', ['rejected', 'void'])->sum('usd_amount');
        $paidUsd = (float) OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', 'supplier_payment')->whereNotIn('status', ['rejected', 'void'])->sum('usd_amount');
        $openUsd = 0.0;
        if ($s->opening_balance > 0) {
            $orate = $fx->ratePerUsd(strtoupper((string) ($s->opening_currency ?: 'USD'))) ?: 1;
            $openUsd = (float) $s->opening_balance / ($orate ?: 1);
        }
        $owedUsd = round($creditUsd + $openUsd - $paidUsd, 2);

        // --- spend (365d), counts, last order ---
        $since = now()->subDays(365);
        $spendUsd = (float) OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', 'purchase')->whereNotIn('status', ['rejected', 'void'])
            ->where('created_at', '>=', $since)->sum('usd_amount');
        $purCount = (int) OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', 'purchase')->whereNotIn('status', ['rejected', 'void'])->count();
        $lastAt = OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', 'purchase')->max('created_at');
        $lastOrder = $lastAt ? Carbon::parse($lastAt)->diffForHumans() : '—';

        // --- people pick-list for "paid by" / holder ---
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        $me = (string) ($ctx->user->name ?? '');
        if ($me !== '') { $members = array_values(array_unique(array_merge([$me], $members))); }
        $comboPeople = 'combo:' . (count($members) ? implode('|', $members) : ($me ?: 'Me'));

        // --- purchases (bills) list with edit/delete per row ---
        $bills = OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', '!=', 'supplier_payment')->orderByDesc('id')->limit(100)->get();
        $billRows = [];
        foreach ($bills as $c) {
            $voided = in_array((string) $c->status, ['void', 'rejected'], true);
            $ref = (string) ($c->order_no ?: ('PUR-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $isCredit = strtolower((string) $c->pay_status) === 'credit';
            $btns = [];
            if ($canEdit && ! $voided) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit purchase', 'action' => [
                    'key' => 'edit_purchase', 'label' => 'Edit purchase ' . $ref, 'method' => 'POST', 'endpoint' => '/papi/order-costs/' . $c->id . '/update', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['note' => 'text', 'native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'pay_status' => 'enum:credit,cash', 'reason' => 'text'],
                    'labels' => ['note' => 'Item / description', 'native_amount' => 'Amount', 'pay_status' => 'Credit or cash', 'reason' => 'Reason (for audit)'],
                    'prefill' => ['note' => (string) $c->note, 'native_amount' => round((float) $c->native_amount, 2), 'currency' => (string) $c->currency, 'pay_status' => $isCredit ? 'credit' : 'cash'],
                ]];
                $btns[] = ['icon' => 'trash', 'title' => 'Delete purchase', 'tone' => 'danger', 'post' => '/papi/order-costs/' . $c->id . '/delete', 'confirm' => 'Delete purchase ' . $ref . '? (voided, kept in the audit trail)'];
            }
            $billRows[] = ['cells' => [
                $when ? $when->format('d M Y') : '—',
                $ref,
                (string) ($c->note ?: ucfirst((string) $c->stage)),
                ['value' => ($voided ? '(void) ' : '') . Money::fmt((float) $c->native_amount, (string) $c->currency), 'tone' => $voided ? '' : 'danger'],
                ['value' => $isCredit ? 'Credit' : 'Cash', 'badge' => $isCredit ? 'warn' : 'ok'],
                ['buttons' => $btns],
            ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
        }
        $billsCard = ['kind' => 'table', 'title' => 'Purchases (bills)', 'sub' => count($billRows) . ' recorded', 'searchable' => true,
            'columns' => [['label' => 'Date'], ['label' => 'Ref'], ['label' => 'Item'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'Pay'], ['label' => '', 'align' => 'right']],
            'rows' => $billRows, 'empty' => 'No purchases recorded for this supplier yet', 'pageSize' => 25];

        // --- payments list ---
        $pays = OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', 'supplier_payment')->whereNotIn('status', ['rejected', 'void'])->orderByDesc('id')->limit(100)->get();
        $payRows = [];
        foreach ($pays as $c) {
            $when = $c->created_at ? Carbon::parse($c->created_at) : null;
            $payRows[] = ['cells' => [
                $when ? $when->format('d M Y') : '—',
                ['value' => Money::fmt((float) $c->native_amount, (string) $c->currency), 'tone' => 'ok'],
                (string) ($c->holder ?: '—'),
                (string) ($c->note ?: '—'),
            ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
        }
        $paysCard = ['kind' => 'table', 'title' => 'Payments made', 'sub' => count($payRows) . ' payments', 'searchable' => true,
            'columns' => [['label' => 'Date'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'Paid by'], ['label' => 'Note']],
            'rows' => $payRows, 'empty' => 'No payments recorded yet', 'pageSize' => 25];

        // --- contact card ---
        $contactCard = ['kind' => 'dl', 'title' => 'Contact & terms', 'rows' => array_values(array_filter([
            ['Contact person', (string) ($s->contact_name ?: '—')],
            ['Phone', (string) ($s->phone ?: '—')],
            ['Email', (string) ($s->email ?: '—')],
            ['Tax number', (string) ($s->tax_number ?: '—')],
            ['Payment terms', $s->pay_term_days ? ('Net ' . $s->pay_term_days . ' days') : 'Cash'],
            ['Address', (string) ($s->address ?: '—')],
            $s->opening_balance > 0 ? ['Opening balance', Money::fmt((float) $s->opening_balance, strtoupper((string) ($s->opening_currency ?: 'USD')))] : null,
        ]))];

        // --- actions ---
        $recordBill = ['key' => 'record_bill', 'label' => 'Record purchase', 'method' => 'POST', 'endpoint' => '/papi/order-costs', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'note' => 'text', 'holder' => $comboPeople],
            'labels' => ['native_amount' => 'Bill amount', 'rate_to_usd' => 'Rate (native per $1)', 'pay_status' => 'Credit (owe) or cash (paid now)', 'note' => 'Item / description', 'holder' => 'Paid by (if cash)'],
            'prefill' => ['currency' => $baseCur, 'rate_to_usd' => $rateMap[$baseCur] ?? 1, 'pay_status' => 'credit', 'holder' => $me],
            'body' => ['stage' => 'purchase', 'supplier_id' => $s->id],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => 'Purchase from ' . $s->name, 'watch' => []]];
        $paySupplier = ['key' => 'pay_supplier', 'label' => 'Pay supplier', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
            'labels' => ['amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Paid by (whose cash)', 'note' => 'Note'],
            'prefill' => ['currency' => $baseCur, 'rate_to_usd' => $rateMap[$baseCur] ?? 1, 'holder' => $me],
            'body' => ['dir' => 'out', 'category' => 'Supplier payment', 'supplier_id' => $s->id],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => 'Paid ' . $s->name, 'watch' => []]];
        $editSupplier = ['key' => 'edit_supplier', 'label' => 'Edit supplier', 'method' => 'POST', 'endpoint' => '/papi/suppliers/' . $s->id, 'actor' => 'human', 'tone' => 'default',
            'schema' => ['name' => 'text', 'contact_name' => 'text', 'phone' => 'text', 'email' => 'text', 'tax_number' => 'text', 'pay_term_days' => 'number', 'opening_balance' => 'money', 'opening_currency' => 'enum:,UGX,USD,SSP', 'address' => 'text'],
            'labels' => ['name' => 'Business name', 'contact_name' => 'Contact person', 'pay_term_days' => 'Pay term (credit days)', 'opening_balance' => 'Opening balance owed', 'opening_currency' => 'Opening currency'],
            'prefill' => ['name' => (string) ($s->business_name ?: $s->name), 'contact_name' => (string) $s->contact_name, 'phone' => (string) $s->phone, 'email' => (string) $s->email, 'tax_number' => (string) $s->tax_number, 'pay_term_days' => $s->pay_term_days, 'opening_balance' => $s->opening_balance, 'opening_currency' => (string) $s->opening_currency, 'address' => (string) $s->address],
            'body' => ['business_name' => (string) ($s->business_name ?: $s->name)]];
        $statement = ['key' => 'statement', 'label' => 'View statement', 'href' => '/api/v1/doc/supplier-statement?id=' . $s->id, 'actor' => 'human', 'tone' => 'default'];
        $deleteSup = ['key' => 'delete_supplier', 'label' => 'Delete supplier', 'tone' => 'danger', 'post' => '/papi/suppliers/' . $s->id . '/delete', 'confirm' => 'Delete supplier ' . $s->name . '? (kept & deactivated if it has history)'];

        $header = $canEdit ? [$recordBill, $paySupplier] : [];
        $quick = $canEdit ? [$recordBill, $paySupplier, $statement, $editSupplier] : [$statement];
        $more = $canEdit ? [$statement, $editSupplier, $deleteSup] : [$statement];

        return (new WorkspacePayload)
            ->object([
                'type' => 'supplier', 'id' => (string) $s->id, 'ref' => (string) $s->name, 'title' => (string) ($s->business_name ?: $s->name),
                'subtitle' => trim(implode(' · ', array_filter([$s->contact_id ? ('#' . $s->contact_id) : '', (string) $s->contact_name, (string) $s->address]))),
                'status' => $s->active === false ? 'inactive' : 'active',
            ])
            ->headline(Money::fmt(max(0, $owedUsd), 'USD'), 'balance owed', $owedUsd > 0.005 ? 'warn' : 'ok')
            ->brief(new Brief($owedUsd > 0.005 ? 'medium' : 'low',
                $owedUsd > 0.005
                    ? 'You owe ' . $s->name . ' ' . Money::fmt($owedUsd, 'USD') . ($s->pay_term_days ? (' on Net ' . $s->pay_term_days . ' terms') : '') . '.'
                    : 'You are square with ' . $s->name . ' — nothing outstanding.',
                'Record a purchase (bill), pay this supplier, or download their statement. ' . $purCount . ' purchases · ' . Money::fmt(round($spendUsd, 2), 'USD') . ' spent in the last year.',
                ['tone' => $owedUsd > 0.005 ? 'warn' : 'ok', 'label' => $owedUsd > 0.005 ? ('Owe ' . Money::fmt($owedUsd, 'USD')) : 'Paid up']))
            ->financials([
                ['key' => 'owed', 'label' => 'Balance owed', 'value' => Money::fmt(max(0, $owedUsd), 'USD'), 'tone' => $owedUsd > 0.005 ? 'warn' : 'ok', 'foot' => 'credit bills − payments'],
                ['key' => 'terms', 'label' => 'Terms', 'value' => $s->pay_term_days ? ('Net ' . $s->pay_term_days) : 'Cash'],
                ['key' => 'purchases', 'label' => 'Purchases', 'value' => $purCount, 'foot' => 'bills recorded'],
                ['key' => 'spend', 'label' => 'Spend (365d)', 'value' => Money::fmt(round($spendUsd, 2), 'USD'), 'foot' => 'last 12 months'],
                ['key' => 'last', 'label' => 'Last order', 'value' => $lastOrder],
            ])
            ->quickActions($quick)
            ->tabSections(['overview' => [$contactCard], 'purchases' => [$billsCard], 'payments' => [$paysCard]])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'purchases', 'label' => 'Purchases', 'count' => count($billRows)],
                ['key' => 'payments', 'label' => 'Payments', 'count' => count($payRows)],
            ])
            ->actions($header)
            ->more($more)
            ->meta(['capability' => 'supplier', 'ttl' => 30, 'layout' => 'record', 'permissions' => ['menu:purchases']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'record_supplier_payment' => $this->recordPayment($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Delegates to the frozen-mirroring SupplierPaymentService — books a supplier_payment row. */
    private function recordPayment(CapabilityContext $ctx, array $input): array
    {
        $s = Supplier::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $s) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        try {
            app(SupplierPaymentService::class)->record($ctx->tenant, $s, $ctx->user, $input);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'Enter an amount above zero.'];
        }

        return ['ok' => true, 'message' => 'Payment recorded for ' . $s->name . '.'];
    }
}
