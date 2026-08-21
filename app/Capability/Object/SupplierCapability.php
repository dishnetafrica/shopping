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
use App\Services\Finance\SupplierStatement;
use App\Services\Money\Fx;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\WhatsApp\WhatsAppManager;
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

        // --- product pick-list (name → id) so a purchase on THIS supplier links to a catalogue product ---
        // v4.42 — the line editor takes this map straight; the flattened combo string it used to be
        // built into went with the four fixed slots.
        $prodMap = [];
        foreach (\App\Models\Product::where('tenant_id', $t)->whereNull('archived_at')->orderBy('name')->limit(1000)->get(['id', 'name']) as $p) {
            $nm = trim((string) $p->name);
            if ($nm === '' || isset($prodMap[$nm])) continue;
            $prodMap[$nm] = $p->id;
        }

        // --- purchases (bills) list with edit/delete per row ---
        $bills = OrderCost::where('tenant_id', $t)->where('supplier_id', $s->id)
            ->where('stage', '!=', 'supplier_payment')->orderByDesc('id')->limit(100)->get();
        // v4.64 — unit labels in one query, so a row can say "50 CTN" instead of only the money.
        $units = [];
        try {
            $pids = $bills->pluck('product_id')->filter()->unique()->values()->all();
            if ($pids) {
                \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t)->whereIn('id', $pids)
                    ->get(['id', 'unit_label'])
                    ->each(fn ($p) => $units[(int) $p->id] = trim((string) ($p->unit_label ?: '')) ?: 'units');
            }
        } catch (\Throwable $e) {
        }

        $billRows = [];
        $boughtByProduct = [];
        $curTally = [];   // which currency this supplier actually invoices in (drives the native statement)
        foreach ($bills as $c) {
            $voided = in_array((string) $c->status, ['void', 'rejected'], true);
            if (! $voided) {
                $cc = strtoupper(trim((string) $c->currency));
                if ($cc !== '') { $curTally[$cc] = ($curTally[$cc] ?? 0) + 1; }
            }
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
            $qtyText = \App\Services\Inventory\PurchaseStock::describe(
                $c->qty, $units[(int) $c->product_id] ?? null, $c->unit_cost, (string) $c->currency
            );
            // How much of each product has ever come from this supplier — the summary underneath.
            if (! $voided && $c->product_id && (float) $c->qty > 0) {
                $k = (int) $c->product_id;
                $boughtByProduct[$k] ??= ['name' => (string) $c->note, 'qty' => 0.0, 'unit' => $units[$k] ?? 'units'];
                $boughtByProduct[$k]['qty'] += (float) $c->qty;
            }

            $billRows[] = ['cells' => [
                $when ? $when->format('d M Y') : '—',
                $ref,
                (string) ($c->note ?: ucfirst((string) $c->stage)),
                ['value' => $qtyText ?: '—', 'align' => 'right'],
                ['value' => ($voided ? '(void) ' : '') . Money::fmt((float) $c->native_amount, (string) $c->currency), 'tone' => $voided ? '' : 'danger'],
                ['value' => $isCredit ? 'Credit' : 'Cash', 'badge' => $isCredit ? 'warn' : 'ok'],
                ['buttons' => $btns],
            ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
        }
        // v4.64 — what this supplier has actually supplied, added up. A year of bills tells you what
        // you spent; this tells you what they are FOR, which is the thing worth knowing before the
        // next order.
        uasort($boughtByProduct, fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $boughtRows = [];
        foreach (array_slice($boughtByProduct, 0, 20, true) as $pid => $b) {
            $boughtRows[] = ['cells' => [
                (string) $b['name'],
                ['value' => \App\Services\Inventory\PurchaseStock::number($b['qty']) . ' ' . $b['unit'], 'align' => 'right'],
            ], 'link' => '/api/v1/workspace/product/' . $pid];
        }
        $boughtCard = ['kind' => 'table', 'title' => 'What you buy from them',
            'sub' => $boughtRows ? count($boughtRows) . ' product(s)' : 'no product-linked purchases yet',
            'columns' => [['label' => 'Product'], ['label' => 'Bought, all time', 'align' => 'right']],
            'rows' => $boughtRows,
            'empty' => 'Purchases recorded as money only. Use Buy stock to link a purchase to a product.',
            'pageSize' => 20];

        $billsCard = ['kind' => 'table', 'title' => 'Purchases (bills)', 'sub' => count($billRows) . ' recorded', 'searchable' => true,
            'columns' => [['label' => 'Date'], ['label' => 'Ref'], ['label' => 'Item'], ['label' => 'Quantity', 'align' => 'right'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'Pay'], ['label' => '', 'align' => 'right']],
            'rows' => $billRows, 'empty' => 'No purchases recorded for this supplier yet', 'pageSize' => 25, 'dateFilter' => true];

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
            'rows' => $payRows, 'empty' => 'No payments recorded yet', 'pageSize' => 25, 'dateFilter' => true];

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
        $recordBill = ['key' => 'record_bill', 'label' => 'Record a bill', 'method' => 'POST', 'endpoint' => '/papi/order-costs', 'actor' => 'human', 'tone' => 'primary',
            // This stays a ONE-LINE bill on purpose — a transport charge, a service fee, a lump-sum
            // invoice with no item breakdown. Use "Buy product" for stock. What changed in v4.41 is
            // that the box below is the item: autoNote used to overwrite it with "Purchase from
            // <supplier>", which is how the supplier's name ended up in the Item column of the
            // Purchases list, and how a cleared box left the list printing the word "Purchase".
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'note' => 'text', 'holder' => $comboPeople],
            'labels' => ['native_amount' => 'Bill amount', 'rate_to_usd' => 'Rate (native per $1)', 'pay_status' => 'Credit (owe) or cash (paid now)', 'note' => 'Item / description — what did you buy?', 'holder' => 'Paid by (if cash)'],
            'prefill' => ['currency' => $baseCur, 'rate_to_usd' => $rateMap[$baseCur] ?? 1, 'pay_status' => 'credit', 'holder' => $me],
            'body' => ['stage' => 'purchase', 'supplier_id' => $s->id],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];
        // Product-linked purchase: pick a catalogue product + qty + unit cost. Posts to /papi/purchases,
        // which books the cost to THIS supplier (credit/cash), links the product, and updates its cost.
        $buyProduct = ['key' => 'record_product_purchase', 'label' => 'Buy stock', 'method' => 'POST', 'endpoint' => '/papi/purchases', 'actor' => 'human', 'tone' => 'primary',
            // v4.42 — one row per item, add as many as the invoice has. This was four fixed slots,
            // so a five-line delivery had to be split into two purchases; and the first slot lost
            // its product name on the way out, because the form mapped it to an id and then dropped
            // the name it had mapped from. Rows post as the `lines` array the classic Purchases
            // screen already sends, so the endpoint did not change.
            'schema' => [
                'items' => 'lines:products',
                'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'holder' => $comboPeople],
            'labels' => ['items' => 'What did you buy?', 'rate_to_usd' => 'Rate (native per $1)',
                'pay_status' => 'Credit (owe) or cash (paid now)', 'holder' => 'Paid by (if cash)'],
            'prefill' => ['currency' => $baseCur, 'rate_to_usd' => $rateMap[$baseCur] ?? 1, 'pay_status' => 'credit', 'holder' => $me],
            'productMap' => $prodMap,
            'body' => ['supplier_id' => $s->id, 'update_cost' => true],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];
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
        // Native-currency statement (e.g. UGX) — figures taken from each bill's native amount so it
        // reconciles line-for-line against the supplier's own invoice. Offered only when the supplier
        // trades in a non-USD currency; the USD "View statement" above stays the consolidated view.
        // The supplier's real invoicing currency = the most common currency across their bills
        // (NOT the tenant base — a USD-reporting tenant still buys foil in UGX). Falls back to the
        // opening/base currency only when there are no bills yet.
        arsort($curTally);
        $txnCur = strtoupper((string) (array_key_first($curTally) ?: $baseCur));
        $nativeCur = $txnCur !== 'USD' ? $txnCur : null;
        $statementNative = $nativeCur
            ? ['key' => 'statement_native', 'label' => 'Download statement (' . $nativeCur . ')', 'href' => '/api/v1/doc/supplier-statement?id=' . $s->id . '&currency=' . $nativeCur, 'actor' => 'human', 'tone' => 'default']
            : null;
        // CSV of the same statement (spreadsheet reconciliation) — in the supplier's own currency,
        // needs no PDF engine. USD stays available via &currency=USD on the same endpoint.
        $csvCur = $nativeCur ?: 'USD';
        $statementCsv = ['key' => 'statement_csv', 'label' => 'Download statement CSV (' . $csvCur . ')', 'href' => '/api/v1/doc/supplier-statement?id=' . $s->id . '&currency=' . $csvCur . '&format=csv', 'actor' => 'human', 'tone' => 'default'];
        $sendStatement = ['key' => 'send_statement', 'label' => 'Send statement (WhatsApp)', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/supplier/' . $s->id . '/send_statement', 'actor' => 'human', 'tone' => 'default',
            'confirm' => 'Send ' . $s->name . '\'s statement as a WhatsApp document to ' . ($s->phone ? $s->phone : 'the owner number') . '?'];
        $deleteSup = ['key' => 'delete_supplier', 'label' => 'Delete supplier', 'tone' => 'danger', 'post' => '/papi/suppliers/' . $s->id . '/delete', 'confirm' => 'Delete supplier ' . $s->name . '? (kept & deactivated if it has history)'];

        $header = $canEdit ? [$buyProduct, $recordBill, $paySupplier] : [];
        $quick = $canEdit ? array_values(array_filter([$buyProduct, $recordBill, $paySupplier, $statement, $statementNative, $editSupplier])) : array_values(array_filter([$statement, $statementNative]));
        $more = $canEdit ? array_values(array_filter([$statement, $statementNative, $statementCsv, $sendStatement, $editSupplier, $deleteSup])) : array_values(array_filter([$statement, $statementNative, $statementCsv]));

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
            ->tabSections(['overview' => [$contactCard], 'purchases' => [$boughtCard, $billsCard], 'payments' => [$paysCard]])
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
            'send_statement' => $this->sendStatement($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Generate the supplier's payables statement PDF and send it as a WhatsApp document. */
    private function sendStatement(CapabilityContext $ctx, array $input): array
    {
        $t = $ctx->tenant;
        $s = Supplier::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $s) { return ['ok' => false, 'error' => 'not_found']; }

        $svc = app(SupplierStatement::class);
        $data = $svc->collect($t, $s->id);
        $pdf = $data ? $svc->pdf($t, $data) : null;
        if (! $pdf) { return ['ok' => false, 'message' => 'Could not build the statement PDF.']; }

        $gw = app(WhatsAppManager::class)->forTenant($t);
        if (! method_exists($gw, 'sendDocument')) { return ['ok' => false, 'message' => 'WhatsApp document sending is not available on this connection.']; }
        $instance = $t->whatsapp_instance ?? null;
        $supPhone = preg_replace('/[^0-9]/', '', (string) $s->phone);
        $target = $supPhone !== '' ? $supPhone : null;
        $toWho = $target ? $s->name : 'the owner';
        if (! $target && method_exists($t, 'ownerAlertNumbers')) { $nums = $t->ownerAlertNumbers(); $target = $nums[0] ?? null; }
        if (! $instance || ! $target) { return ['ok' => false, 'message' => 'No WhatsApp number to send to — add a phone to this supplier or set an owner alert number.']; }

        try {
            $gw->sendDocument($instance, $target, base64_encode($pdf),
                'Statement-' . preg_replace('/[^A-Za-z0-9]+/', '', $data['supplier']) . '.pdf',
                '📄 Statement — ' . $s->name . '. Balance owed $' . number_format((float) $data['closing'], 2) . '.');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'WhatsApp send failed — try again shortly.'];
        }

        return ['ok' => true, 'message' => 'Statement sent on WhatsApp to ' . $toWho . '.'];
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
