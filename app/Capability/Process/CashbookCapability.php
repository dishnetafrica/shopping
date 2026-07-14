<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\Supplier;
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

        // --- "smart pick-list" of people the business actually deals with (team logins + anyone
        //     who has ever held cash, e.g. Jayesh bhai). combo: = tap-to-pick OR type a new name. ---
        $members = User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values()->all();
        $peopleList = collect(array_keys($holders))->merge($members)
            ->map(fn ($n) => trim(str_replace('|', ' ', (string) $n)))
            ->filter(fn ($n) => $n !== '' && strcasecmp($n, 'Unassigned') !== 0)
            ->unique(fn ($n) => mb_strtolower($n))->values()->all();
        if ($me !== '') { $peopleList = array_values(array_unique(array_merge([$me], $peopleList))); }
        $supRaw = Supplier::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim(str_replace('|', ' ', (string) $n)))->filter()->all();
        // Also remember informal payees (Mr Tomu, etc.) from past expense entries so they stay tap-able.
        $pastPayees = OrderCost::where('tenant_id', $t)->where('stage', 'cash_out')
            ->whereIn('category', ['Transport', 'Salary', 'Rent', 'Utilities', 'Expense', 'Drawing', 'Other', 'Local purchase'])
            ->whereNotNull('note')->orderByDesc('id')->limit(300)->pluck('note')
            ->map(fn ($n) => trim(str_replace('|', ' ', (string) $n)))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 40 && ! str_contains($n, '—') && stripos($n, 'handed to') === false && stripos($n, 'received from') === false)
            ->all();
        $payeeList = collect($peopleList)->merge($supRaw)->merge($pastPayees)->unique(fn ($n) => mb_strtolower($n))->values()->all();
        $comboPeople = 'combo:' . (count($peopleList) ? implode('|', $peopleList) : ($me ?: 'Me'));
        $comboPayee = 'combo:' . (count($payeeList) ? implode('|', $payeeList) : ($me ?: 'Me'));
        $expCats = 'Transport|Salary|Rent|Utilities|Local purchase|Expense|Drawing|Other';

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
            // Editable/deletable: single-row cash movements incl. supplier payments (fix who paid, amount, etc.).
            // Excluded: receipts (edit on the order screen so the balance stays in sync) and the paired
            // conversion/transfer legs (deleting one leg alone would unbalance the pair).
            $editable = in_array($c->stage, ['cash_in', 'cash_out', 'funding', 'purchase', 'expense', 'drawing', 'supplier_payment'], true)
                && ! in_array(mb_strtolower((string) $c->category), ['conversion', 'transfer'], true);
            $isSupPay = $c->stage === 'supplier_payment';

            $btns = [];
            if ($canEdit && $editable) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit entry', 'action' => [
                    'key' => 'edit_cash', 'label' => 'Edit ' . ($stageLabels[$c->stage] ?? 'entry') . ' ' . $ref, 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/' . $c->id . '/update', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'category' => 'combo:' . self::CATEGORIES, 'holder' => $comboPeople, 'note' => 'text'],
                    'labels' => ['amount' => 'Amount', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => $isSupPay ? 'Paid by (whose cash)' : 'Held by', 'category' => 'Category', 'note' => 'Note'],
                    'prefill' => ['amount' => round($native, 2), 'currency' => $cur, 'rate_to_usd' => (float) $c->rate_to_usd, 'category' => (string) $c->category, 'holder' => (string) $c->holder, 'note' => (string) $c->note],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                ]];
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

        // --- payables: who you still owe (same read as the panel debt screen: credit purchases + openings − supplier payments) ---
        $canPay = $isOwner || $ctx->can('menu:purchases');
        $credit = OrderCost::where('tenant_id', $t)->whereNotNull('supplier_id')
            ->where('pay_status', 'credit')->whereNotIn('status', ['rejected', 'void'])
            ->select('supplier_id', 'supplier_name', 'currency', DB::raw('SUM(native_amount) as owed'))
            ->groupBy('supplier_id', 'supplier_name', 'currency')->get();
        $paidRows = OrderCost::where('tenant_id', $t)->whereNotNull('supplier_id')
            ->where('stage', 'supplier_payment')->whereNotIn('status', ['rejected', 'void'])
            ->select('supplier_id', 'currency', DB::raw('SUM(native_amount) as paid'))
            ->groupBy('supplier_id', 'currency')->get();
        $paidMap = [];
        foreach ($paidRows as $p) { $paidMap[$p->supplier_id . '|' . $p->currency] = (float) $p->paid; }

        $payables = [];   // supplier_id|currency => ['id','name','currency','owed']
        foreach (Supplier::where('tenant_id', $t)->whereNotNull('opening_balance')->where('opening_balance', '>', 0)->get(['id', 'name', 'opening_balance', 'opening_currency']) as $s) {
            $ocur = strtoupper((string) ($s->opening_currency ?: 'USD'));
            $owed = (float) $s->opening_balance - ($paidMap[$s->id . '|' . $ocur] ?? 0);
            if ($owed > 0.005) { $payables[$s->id . '|' . $ocur] = ['id' => $s->id, 'name' => (string) $s->name, 'currency' => $ocur, 'owed' => round($owed, 2)]; }
        }
        foreach ($credit as $c) {
            $owed = (float) $c->owed - ($paidMap[$c->supplier_id . '|' . $c->currency] ?? 0);
            if ($owed <= 0.005) { continue; }
            $k = $c->supplier_id . '|' . $c->currency;
            $payables[$k] = ['id' => $c->supplier_id, 'name' => (string) $c->supplier_name, 'currency' => (string) $c->currency, 'owed' => round((($payables[$k]['owed'] ?? 0) + $owed), 2)];
        }
        $payables = array_values($payables);
        usort($payables, fn ($a, $b) => $b['owed'] <=> $a['owed']);

        $payRows = [];
        foreach ($payables as $pv) {
            $rate = $rateMap[$pv['currency']] ?? (int) round($fx->ratePerUsd($pv['currency']));
            $payBtns = [];
            if ($canPay) {
                $payBtns[] = ['icon' => 'dollar', 'title' => 'Pay ' . $pv['name'], 'tone' => 'primary', 'action' => [
                    'key' => 'pay_supplier_row', 'label' => 'Pay ' . $pv['name'], 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['currency' => 'enum:UGX,USD,SSP', 'amount' => 'money', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
                    'labels' => ['amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Paid by (whose cash)', 'note' => 'Note'],
                    'prefill' => ['currency' => $pv['currency'], 'amount' => $pv['owed'], 'rate_to_usd' => $rate, 'holder' => $me, 'note' => 'Paid ' . $pv['name']],
                    'body' => ['dir' => 'out', 'category' => 'Supplier payment', 'supplier_id' => $pv['id']],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                ]];
            }
            $payRows[] = ['cells' => [
                $pv['name'],
                ['value' => Money::fmt($pv['owed'], $pv['currency']), 'tone' => 'danger'],
                ['buttons' => $payBtns],
            ], 'usd' => 0, 'link' => null];
        }
        $payablesCard = ['kind' => 'table', 'title' => 'Suppliers you owe', 'sub' => count($payRows) . ' with an open balance', 'searchable' => true,
            'columns' => [['label' => 'Supplier'], ['label' => 'Owed', 'align' => 'right'], ['label' => '', 'align' => 'right']],
            'rows' => $payRows, 'empty' => 'Nothing outstanding — you are square with every supplier 🎉', 'pageSize' => 25];

        // Supplier picker map for the header "Pay supplier" action (pay anyone, incl. advances not yet on account).
        $supIdMap = []; $supNames = []; $seen = [];
        foreach (Supplier::where('tenant_id', $t)->orderBy('name')->get(['id', 'name']) as $s) {
            $nm = trim(str_replace(',', ' ', (string) $s->name)); if ($nm === '') { continue; }
            $label = isset($seen[$nm]) ? ($nm . ' #' . $s->id) : $nm; $seen[$nm] = true;
            $supNames[] = $label; $supIdMap[$label] = (int) $s->id;
        }

        // --- granular actions (kept in Quick actions for power users) ---
        $addCash = ['key' => 'add_cash', 'label' => 'Add cash entry', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['dir' => 'enum:in,out', 'currency' => 'enum:UGX,USD,SSP', 'native_amount' => 'money', 'rate_to_usd' => 'number', 'category' => 'combo:' . self::CATEGORIES, 'holder' => $comboPeople, 'note' => 'text'],
            'labels' => ['dir' => 'In or out', 'native_amount' => 'Amount', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Held by', 'category' => 'Category', 'note' => 'Note'],
            'prefill' => ['dir' => 'in', 'currency' => 'UGX', 'rate_to_usd' => (int) round($ugx), 'holder' => $me],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => '{category}', 'watch' => ['category', 'dir']]];
        $fxExchange = ['key' => 'fx_exchange', 'label' => 'Currency exchange', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/convert', 'actor' => 'human', 'tone' => 'default', 'flow' => 'convert',
            'schema' => ['from_amount' => 'money', 'from_currency' => 'enum:USD,UGX,SSP', 'to_currency' => 'enum:UGX,USD,SSP', 'rate' => 'number', 'holder' => $comboPeople, 'receiver' => $comboPeople, 'note' => 'text'],
            'labels' => ['from_amount' => 'Amount to convert', 'from_currency' => 'From', 'to_currency' => 'To', 'rate' => 'Rate', 'holder' => 'Whose cash goes out (spends the From)', 'receiver' => 'Who gets the converted money (blank = same person)', 'note' => 'Note'],
            'prefill' => ['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => (int) round($ugx), 'holder' => $me, 'receiver' => ''],
            'autoRate' => ['field' => 'rate', 'source' => 'to_currency', 'map' => $rateMap],
            'preview' => ['left' => 'from_amount', 'right' => 'rate', 'curField' => 'to_currency', 'label' => 'You will get']];
        $transfer = count($peopleList) >= 2 ? ['key' => 'cash_transfer', 'label' => 'Transfer cash', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook/transfer', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['from_person' => $comboPeople, 'to_person' => $comboPeople, 'currency' => 'enum:UGX,USD,SSP', 'amount' => 'money', 'note' => 'text'],
            'labels' => ['from_person' => 'From (who gave)', 'to_person' => 'To (who received)', 'amount' => 'Amount', 'note' => 'Note'],
            'prefill' => ['from_person' => $peopleList[0], 'to_person' => $peopleList[1] ?? '', 'currency' => 'UGX']] : null;
        $paySupplier = ($canPay && count($supNames)) ? ['key' => 'pay_supplier', 'label' => 'Pay supplier', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['supplier' => 'enum:' . implode(',', $supNames), 'currency' => 'enum:UGX,USD,SSP', 'amount' => 'money', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
            'labels' => ['supplier' => 'Supplier', 'amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Paid by (whose cash)', 'note' => 'Note (auto)'],
            'prefill' => ['supplier' => $supNames[0], 'currency' => 'UGX', 'rate_to_usd' => (int) round($ugx), 'holder' => $me],
            'body' => ['dir' => 'out', 'category' => 'Supplier payment'],
            'valueMap' => ['field' => 'supplier', 'into' => 'supplier_id', 'map' => $supIdMap, 'error' => 'Pick a supplier from the list'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => 'Paid {supplier}', 'watch' => ['supplier']]] : null;

        // --- guided recorder sub-actions (plain language; each fills only what its event needs) ---
        $evReceived = ['key' => 'ev_received', 'label' => 'Money received', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:USD,UGX,SSP', 'rate_to_usd' => 'number', 'holder' => $comboPeople, 'note' => 'text'],
            'labels' => ['native_amount' => 'Amount received', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => 'Held by (who has the cash now)', 'note' => 'Note (auto — add source if you like)'],
            'prefill' => ['currency' => 'USD', 'rate_to_usd' => 1, 'holder' => $me],
            'body' => ['dir' => 'in', 'category' => 'Funding'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
            'autoNote' => ['template' => 'Funding received by {holder}', 'watch' => ['holder']]];
        $evExpense = ['key' => 'ev_expense', 'label' => 'Paid an expense / someone', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'category' => 'combo:' . $expCats, 'note' => $comboPayee, 'holder' => $comboPeople],
            'labels' => ['native_amount' => 'Amount paid', 'rate_to_usd' => 'Rate (native per $1)', 'category' => 'What for', 'note' => 'Paid to (person or shop)', 'holder' => 'From whose cash'],
            'prefill' => ['currency' => 'UGX', 'rate_to_usd' => (int) round($ugx), 'category' => 'Transport', 'holder' => $me],
            'body' => ['dir' => 'out'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];
        $goOrders = ['key' => 'go_orders', 'label' => 'Customer paid', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesOrders', 'actor' => 'human'];
        // Customer paid — inline receipt against an open order; the remaining balance is shown right here.
        $orderLabels = []; $orderMap = []; $recvAgg = [];
        foreach (Order::where('tenant_id', $t)->whereColumn('amount_paid', '<', 'total')->orderByDesc('id')->limit(200)->get(['id', 'order_no', 'customer_name', 'total', 'amount_paid', 'sell_currency']) as $o) {
            $bal = round(max(0, (float) $o->total - (float) $o->amount_paid), 2);
            if ($bal <= 0.005) { continue; }
            $ocur = strtoupper(trim((string) ($o->sell_currency ?: ''))) ?: $ctx->nativeCurrency();
            $cust = trim(str_replace('|', ' ', (string) ($o->customer_name ?: 'Customer')));
            $ono = (string) ($o->order_no ?: ('#' . $o->id));
            $label = str_replace('|', ' ', $ono . ' · ' . $cust . ' · owes ' . Money::fmt($bal, $ocur));
            $orderMap[$label] = (int) $o->id;                       // full lookup (resolves any prefill)
            if (count($orderLabels) < 60) { $orderLabels[] = $label; } // but only show the 60 most recent
            $rk = mb_strtolower($cust) . '|' . $ocur;
            if (! isset($recvAgg[$rk])) { $recvAgg[$rk] = ['customer' => $cust, 'currency' => $ocur, 'owed' => 0.0, 'order' => $label]; }
            $recvAgg[$rk]['owed'] += $bal;
        }
        $customerPaid = count($orderLabels) ? [
            'key' => 'ev_customer_paid', 'label' => 'Customer paid', 'method' => 'POST', 'endpoint' => '/papi/record-payment', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['order' => 'combo:' . implode('|', $orderLabels), 'amount' => 'money', 'note' => 'text'],
            'labels' => ['order' => 'Which order (customer · balance owed)', 'amount' => 'Amount received', 'note' => 'Note (auto)'],
            'prefill' => ['order' => $orderLabels[0]],
            'body' => ['notify' => '0'],
            'autoNote' => ['template' => 'Customer payment', 'watch' => []],
            'valueMap' => ['field' => 'order', 'into' => 'order', 'map' => $orderMap, 'error' => 'Pick an order from the list'],
            'onResult' => 'payment',
        ] : $goOrders;

        // --- "Customers who owe you" (receivables) — the mirror of the payables tab ---
        $recvRows = [];
        if (count($orderLabels)) {
            $recvList = array_values($recvAgg);
            usort($recvList, fn ($a, $b) => $b['owed'] <=> $a['owed']);
            foreach ($recvList as $rv) {
                if ($rv['owed'] <= 0.005) { continue; }
                $recAct = array_merge($customerPaid, ['key' => 'ev_customer_paid', 'label' => 'Payment from ' . $rv['customer'],
                    'prefill' => array_merge($customerPaid['prefill'] ?? [], ['order' => $rv['order']])]);
                $recvRows[] = ['cells' => [
                    $rv['customer'],
                    ['value' => Money::fmt(round($rv['owed'], 2), $rv['currency']), 'tone' => 'danger'],
                    ['buttons' => [['icon' => 'dollar', 'title' => 'Record payment from ' . $rv['customer'], 'tone' => 'primary', 'action' => $recAct]]],
                ], 'usd' => 0, 'link' => null];
            }
        }
        $receivablesCard = ['kind' => 'table', 'title' => 'Customers who owe you', 'sub' => count($recvRows) . ' with an open balance', 'searchable' => true,
            'columns' => [['label' => 'Customer'], ['label' => 'Owed', 'align' => 'right'], ['label' => '', 'align' => 'right']],
            'rows' => $recvRows, 'empty' => 'Everyone has paid up — nothing outstanding 🎉', 'pageSize' => 25];

        // --- Working capital = cash on hand + what customers owe you − what you owe suppliers.
        //     Built from the SAME figures as the three tabs above, so it always reconciles. ---
        $recvUsd = 0.0;
        foreach ($recvAgg as $rv) { $rr = $rateMap[$rv['currency']] ?? 1; if ($rr > 0) { $recvUsd += $rv['owed'] / $rr; } }
        $payUsd = 0.0;
        foreach ($payables as $pv) { $rr = $rateMap[$pv['currency']] ?? 1; if ($rr > 0) { $payUsd += $pv['owed'] / $rr; } }
        $recvUsd = round($recvUsd, 2); $payUsd = round($payUsd, 2);
        $workingCapital = round($combinedUsd + $recvUsd - $payUsd, 2);
        $financials[] = ['key' => 'working_capital', 'label' => 'Working capital', 'value' => Money::fmt($workingCapital, 'USD'),
            'tone' => $workingCapital < -0.01 ? 'danger' : 'ok',
            'foot' => 'cash ' . Money::fmt($combinedUsd, 'USD') . ' + owed to you ' . Money::fmt($recvUsd, 'USD') . ' − you owe ' . Money::fmt($payUsd, 'USD')];

        $tiles = [];
        $tiles[] = ['icon' => 'download', 'title' => 'Money received', 'sub' => 'Cash in — funding, or from an agent', 'tone' => 'in', 'action' => $evReceived];
        $tiles[] = ['icon' => 'user', 'title' => 'Customer paid', 'sub' => 'Against their order — shows remaining balance', 'tone' => 'in', 'action' => $customerPaid];
        $tiles[] = ['icon' => 'trend', 'title' => 'Converted currency', 'sub' => 'e.g. $2,000 → UGX at your rate', 'tone' => 'move', 'action' => array_merge($fxExchange, ['label' => 'Converted currency'])];
        if ($paySupplier) { $tiles[] = ['icon' => 'dollar', 'title' => 'Paid a supplier', 'sub' => 'On account — reduces what you owe them', 'tone' => 'out', 'action' => array_merge($paySupplier, ['label' => 'Pay supplier'])]; }
        $tiles[] = ['icon' => 'send', 'title' => 'Paid an expense / someone', 'sub' => 'Transport, salary, rent — to a person or shop', 'tone' => 'out', 'action' => $evExpense];
        if ($transfer) { $tiles[] = ['icon' => 'chev', 'title' => 'Moved cash between people', 'sub' => 'Hand cash from one person to another', 'tone' => 'move', 'action' => array_merge($transfer, ['label' => 'Move cash between people'])]; }

        $record = ['key' => 'record_activity', 'label' => 'Record activity', 'actor' => 'human', 'tone' => 'primary', 'chooser' => $tiles];
        $mFrom = now()->startOfMonth()->toDateString();
        $mTo = now()->endOfMonth()->toDateString();
        $exportPdf = ['key' => 'export_pdf', 'label' => 'Download PDF statement', 'href' => '/api/v1/doc/cashbook-export?format=pdf', 'actor' => 'human', 'tone' => 'default'];
        $exportCsv = ['key' => 'export_csv', 'label' => 'Download CSV (Excel)', 'href' => '/api/v1/doc/cashbook-export?format=csv', 'actor' => 'human', 'tone' => 'default'];
        $exportPdfMonth = ['key' => 'export_pdf_month', 'label' => 'PDF — this month', 'href' => '/api/v1/doc/cashbook-export?format=pdf&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
        $exportCsvMonth = ['key' => 'export_csv_month', 'label' => 'CSV — this month', 'href' => '/api/v1/doc/cashbook-export?format=csv&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
        // Per-currency cash book (running balance) — one download per currency you actually hold/use.
        $curExports = [];
        foreach (self::CURRENCIES as $cc) {
            if (abs($pos[$cc]['native']) < 0.01) { continue; }
            $curExports[] = ['key' => 'export_' . strtolower($cc), 'label' => $cc . ' cash book (PDF)', 'href' => '/api/v1/doc/cashbook-export?format=pdf&currency=' . $cc, 'actor' => 'human', 'tone' => 'default'];
            $curExports[] = ['key' => 'export_' . strtolower($cc) . '_csv', 'label' => $cc . ' cash book (CSV)', 'href' => '/api/v1/doc/cashbook-export?format=csv&currency=' . $cc, 'actor' => 'human', 'tone' => 'default'];
        }

        $headerActions = array_values(array_filter([$record, $paySupplier]));
        $actions = array_values(array_filter([$record, $addCash, $fxExchange, $transfer, $paySupplier]));

        return (new WorkspacePayload)
            ->object(['type' => 'process:cashbook', 'title' => 'Cashbook', 'subtitle' => $isOwner ? 'Cash position, who holds what, and every movement' : 'My cash'])
            ->headline(Money::fmt($combinedUsd, 'USD'), 'combined cash (USD)', $combinedUsd < -0.01 ? 'danger' : 'ok')
            ->brief(new Brief('low',
                'You hold ' . Money::fmt(round($pos['UGX']['native'], 2), 'UGX') . ', ' . Money::fmt(round($pos['USD']['native'], 2), 'USD') . ' and ' . Money::fmt(round($pos['SSP']['native'], 2), 'SSP') . '. Working capital ' . Money::fmt($workingCapital, 'USD') . ' (cash + owed to you − you owe).',
                'Tap "Record activity" and pick what happened — money received, converted, paid to a supplier or person, or handed between people. Every entry updates the position instantly.',
                ['tone' => 'info', 'label' => 'WC ' . Money::fmt($workingCapital, 'USD')]))
            ->financials($financials)
            ->quickActions($actions)
            ->tabSections(['holders' => [$holderCard], 'entries' => [$entriesCard], 'receivables' => [$receivablesCard], 'payables' => [$payablesCard]])
            ->tabs([
                ['key' => 'holders', 'label' => 'Cash by person', 'count' => count($holderRows)],
                ['key' => 'entries', 'label' => 'All entries', 'count' => count($entryRows)],
                ['key' => 'receivables', 'label' => 'Customers who owe you', 'count' => count($recvRows)],
                ['key' => 'payables', 'label' => 'Suppliers you owe', 'count' => count($payRows)],
            ])
            ->actions($headerActions)
            ->more(array_merge([$exportPdf, $exportCsv, $exportPdfMonth, $exportCsvMonth], $curExports))
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
