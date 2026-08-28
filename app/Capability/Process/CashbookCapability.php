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

    /** Order states that are NOT money owed — the sale is off, so the balance is not a receivable. */
    private const NOT_RECEIVABLE = ['Cancelled', 'Rejected'];

    /** Unpaid orders scanned for receivables. Was a bare 200, which silently truncated the total
     *  once a tenant passed that many open orders — the figure just stopped growing, with no sign. */
    private const MAX_OPEN_ORDERS = 2000;
    private const CURRENCIES = ['USD', 'UGX', 'SSP'];
    private const CATEGORIES = 'Customer payment|Funding|Local purchase|Transport|Salary|Rent|Utilities|Expense|Drawing|Other';

    public function key(): string { return 'cashbook'; }

    // Classic parity: the Cashbook menu item was gated on the 'cash' key (purchaser/field
    // hold cash but NOT finance) — Phase C flips those roles to /v3, so the gate must match.
    public function permission(): string { return 'menu:cash'; }

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

        // --- cash position: per currency AND per holder (the ONE cash-on-hand reader) ---
        // CashOnHand is the shared reader behind this screen, the cash-book exports AND the Pay Advisor,
        // so cash-by-person, the combined position and each person's cash book can never disagree.
        $cash = $isOwner
            ? \App\Services\Finance\CashOnHand::position($t)
            : \App\Services\Finance\CashOnHand::position($t, (int) $ctx->user->id, $me);
        $pos = $cash['pos'];
        $holders = $cash['holders'];   // holder => ['USD'=>x,'UGX'=>y,'SSP'=>z,'usd'=>total]
        $combinedUsd = $cash['combined_usd'];

        // Rail: per-currency position cards.
        $financials = [];
        foreach (self::CURRENCIES as $c) {
            $native = round($pos[$c]['native'], 2);
            $financials[] = ['key' => strtolower($c), 'label' => $c . ' cash', 'value' => Money::fmt($native, $c),
                'tone' => $native < -0.01 ? 'danger' : 'ok',
                'foot' => $c === 'USD' ? 'in hand' : ('≈ ' . Money::fmt(round($pos[$c]['usd'], 2), 'USD'))];
        }
        $financials[] = ['key' => 'combined', 'label' => 'Combined (USD)', 'value' => Money::fmt($combinedUsd, 'USD'), 'tone' => $combinedUsd < -0.01 ? 'danger' : 'ok', 'foot' => 'all currencies'];

        // --- "Cash by person" table (who holds what) — each row exports that person's cash book ---
        $holderRows = [];
        foreach ($holders as $h => $bal) {
            if (abs($bal['USD']) < 0.01 && abs($bal['UGX']) < 0.01 && abs($bal['SSP']) < 0.01) { continue; }
            $enc = rawurlencode($h);
            $tiles = [];
            foreach (self::CURRENCIES as $cc) {
                if (abs($bal[$cc]) < 0.01) { continue; }
                $tiles[] = ['icon' => 'download', 'title' => $h . ' — ' . $cc . ' cash book (PDF)', 'sub' => 'running ' . $cc . ' balance', 'tone' => 'move', 'action' => ['href' => '/api/v1/doc/cashbook-export?format=pdf&holder=' . $enc . '&currency=' . $cc]];
                $tiles[] = ['icon' => 'doc', 'title' => $h . ' — ' . $cc . ' cash book (CSV)', 'sub' => 'open in Excel', 'action' => ['href' => '/api/v1/doc/cashbook-export?format=csv&holder=' . $enc . '&currency=' . $cc]];
            }
            $tiles[] = ['icon' => 'download', 'title' => $h . ' — all currencies in USD (PDF)', 'sub' => 'one running USD balance', 'tone' => 'in', 'action' => ['href' => '/api/v1/doc/cashbook-export?format=pdf&view=company&holder=' . $enc]];
            $tiles[] = ['icon' => 'doc', 'title' => $h . ' — all currencies in USD (CSV)', 'action' => ['href' => '/api/v1/doc/cashbook-export?format=csv&view=company&holder=' . $enc]];
            $exportBtn = ['icon' => 'download', 'title' => 'Export ' . $h . '\'s cash book', 'action' => ['key' => 'person_book', 'label' => $h . ' — cash book', 'chooser' => $tiles]];

            $holderRows[] = ['cells' => [
                $h,
                ['value' => Money::fmt(round($bal['USD'], 2), 'USD'), 'tone' => $bal['USD'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['UGX'], 2), 'UGX'), 'tone' => $bal['UGX'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['SSP'], 2), 'SSP'), 'tone' => $bal['SSP'] < -0.01 ? 'danger' : ''],
                ['value' => Money::fmt(round($bal['usd'], 2), 'USD'), 'tone' => $bal['usd'] < -0.01 ? 'danger' : 'ok'],
                ['buttons' => [$exportBtn]],
            ], 'usd' => round($bal['usd'], 2), 'link' => null];
        }
        usort($holderRows, fn ($a, $b) => $b['usd'] <=> $a['usd']);
        $holderCard = ['kind' => 'table', 'title' => 'Cash by person', 'sub' => count($holderRows) . ' holding cash — tap ⤓ to export a person\'s cash book', 'searchable' => true,
            'columns' => [['label' => 'Held by'], ['label' => 'USD', 'align' => 'right'], ['label' => 'UGX', 'align' => 'right'], ['label' => 'SSP', 'align' => 'right'], ['label' => 'Total (USD)', 'align' => 'right'], ['label' => '', 'align' => 'right']],
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
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->whereNotIn('status', ['void', 'rejected']))
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
                    'schema' => ['amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'category' => 'combo:' . self::CATEGORIES, 'holder' => $comboPeople, 'note' => 'text', 'created_date' => 'datetime'],
                    'labels' => ['amount' => 'Amount', 'rate_to_usd' => 'Rate (native per $1)', 'holder' => $isSupPay ? 'Paid by (whose cash)' : 'Held by', 'category' => 'Category', 'note' => 'Note', 'created_date' => 'Date & time (pick — fixes ordering)'],
                    'prefill' => ['amount' => round($native, 2), 'currency' => $cur, 'rate_to_usd' => (float) $c->rate_to_usd, 'category' => (string) $c->category, 'holder' => (string) $c->holder, 'note' => (string) $c->note, 'created_date' => $when ? $when->format('Y-m-d\TH:i') : ''],
                    'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                ]];
                $btns[] = ['icon' => 'trash', 'title' => 'Delete entry', 'tone' => 'danger', 'post' => '/papi/trade-cashbook/' . $c->id . '/delete',
                    'confirm' => 'Delete this ' . ($stageLabels[$c->stage] ?? $c->stage) . ' of ' . Money::fmt($native, $cur) . '?'];
            }

            $entryRows[] = ['cells' => [
                $when ? $when->format('d M H:i') : '—',
                $ref,
                (string) ($c->category ?: ($stageLabels[$c->stage] ?? ucfirst(str_replace('_', ' ', (string) $c->stage)))),
                (string) ($c->note ?: '—'),
                (string) ($c->holder ?: '—'),
                ['value' => $isIn ? 'In' : 'Out', 'badge' => $isIn ? 'ok' : 'danger'],
                ['value' => ($isIn ? '+' : '−') . Money::fmt($native, $cur), 'tone' => $isIn ? 'ok' : 'danger'],
                Money::fmt((float) $c->usd_amount, 'USD'),
                ['buttons' => $btns],
            ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
        }
        $entriesCard = ['kind' => 'table', 'title' => 'All cash entries', 'sub' => count($entryRows) . ' recent', 'searchable' => true, 'dateFilter' => true,
            'columns' => [['label' => 'When'], ['label' => 'Ref'], ['label' => 'Category'], ['label' => 'Note'], ['label' => 'Held by'], ['label' => 'In/Out'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'USD', 'align' => 'right'], ['label' => '', 'align' => 'right']],
            'rows' => $entryRows, 'empty' => 'No cash entries yet', 'pageSize' => 25];

        // --- Company account (USD): every transaction valued in USD, one running USD balance (chronological).
        //     This is the accounting view for the bookkeeper — where the company really stands in one currency. ---
        $compAsc = []; $rbal = 0.0; $compIn = 0.0; $compOut = 0.0;
        foreach (array_reverse($entries->all()) as $c) {   // oldest → newest so the balance accumulates correctly
            $isIn = in_array($c->stage, CashService::IN_STAGES, true);
            $ccur = (string) $c->currency;
            $cnat = (float) $c->native_amount;
            $cusd = (float) $c->usd_amount;
            $rbal += ($isIn ? 1 : -1) * $cusd;
            if ($isIn) { $compIn += $cusd; } else { $compOut += $cusd; }
            $cref = in_array($c->stage, ['cash_in', 'cash_out'], true)
                ? 'CB-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)
                : ((string) ($c->order_no ?: strtoupper(substr((string) $c->stage, 0, 3)) . '-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)));
            $cwhen = $c->created_at ? Carbon::parse($c->created_at) : null;
            $compAsc[] = ['cells' => [
                $cwhen ? $cwhen->format('d M H:i') : '—',
                $cref,
                (string) ($c->note ?: ($c->category ?: ($stageLabels[$c->stage] ?? ucfirst(str_replace('_', ' ', (string) $c->stage))))),
                (string) ($c->holder ?: '—'),
                ['value' => Money::fmt($cnat, $ccur), 'tone' => ''],
                ['value' => ($isIn ? '+' : '−') . Money::fmt($cusd, 'USD'), 'tone' => $isIn ? 'ok' : 'danger'],
                ['value' => Money::fmt(round($rbal, 2), 'USD'), 'tone' => $rbal < -0.01 ? 'danger' : 'ok'],
            ], 'ts' => $cwhen ? $cwhen->timestamp : 0, 'link' => null];
        }
        $companyRows = array_reverse($compAsc);   // newest first for display; each row shows the balance AFTER it
        $companyCard = ['kind' => 'table', 'title' => 'Company account — USD', 'sub' => 'in ' . Money::fmt(round($compIn, 2), 'USD') . ' · out ' . Money::fmt(round($compOut, 2), 'USD') . ' · balance ' . Money::fmt(round($rbal, 2), 'USD'), 'searchable' => true, 'dateFilter' => true,
            'columns' => [['label' => 'When'], ['label' => 'Ref'], ['label' => 'Description'], ['label' => 'Held by'], ['label' => 'Original', 'align' => 'right'], ['label' => 'In/Out (USD)', 'align' => 'right'], ['label' => 'Balance (USD)', 'align' => 'right']],
            'rows' => $companyRows, 'empty' => 'No transactions yet', 'pageSize' => 25];

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

        // "Who to pay today" — the Pay Advisor's plan, in-app. Cash-aware (pay/hold vs the payer's cash,
        // keeping the buffer) when a cash holder is configured; otherwise a simple overdue list. Owner-only,
        // read-only; tapping a row opens that supplier (where "Pay supplier" lives). Never breaks the page.
        $payPlanCard = null;
        if ($isOwner) {
            try {
                $adv = app(\App\Services\Finance\PayAdvisor::class);
                $PA = \App\Services\Finance\PayAdvisor::class;
                $holderSet = trim((string) $ctx->tenant->setting('pay_advisor_cash_holder', '')) !== '';
                $planRows = [];
                $sub = '';
                $ageCell = fn ($a) => ['value' => $a['oldest_days'] . 'd overdue', 'tone' => $a['oldest_days'] >= 15 ? 'danger' : 'warn'];
                $link = fn ($a) => '/api/v1/workspace/supplier/' . $a['supplier_id'];

                if ($holderSet) {
                    $plan = $adv->cashPlan($ctx->tenant);
                    foreach ($plan['pay'] as $a) {
                        $amt = $a['pay_native'] !== null ? $PA::money((float) $a['pay_native'], $a['currency']) : ('$' . number_format($a['pay_usd'], 0));
                        $planRows[] = ['cells' => [$a['supplier'], ['value' => 'Pay ' . $amt . ($a['partial'] ? ' (partial)' : ''), 'tone' => 'ok'], $ageCell($a)], 'link' => $link($a)];
                    }
                    foreach ($plan['hold'] as $a) {
                        $planRows[] = ['cells' => [$a['supplier'], ['value' => 'Hold ' . $PA::money((float) $a['overdue'], $a['currency'])], $ageCell($a)], 'link' => $link($a)];
                    }
                    if ($planRows) {
                        $cashUgx = (float) ($plan['cash_native']['UGX'] ?? 0);
                        $cashBit = $cashUgx > 0 ? $PA::money($cashUgx, 'UGX') : ('$' . number_format($plan['cash_usd'], 0));
                        $sub = 'Cash ' . $cashBit . ' · keep $' . number_format($plan['buffer_usd'], 0) . ' · ~$' . number_format($plan['spendable_usd'], 0) . ' to spend';
                    }
                } else {
                    foreach ($adv->forTenant($ctx->tenant) as $a) {
                        if (! in_array($a['state'], ['pay', 'tension'], true) || $a['overdue'] <= 0.005) { continue; }
                        $amt = $PA::money((float) $a['overdue'], $a['currency']) . $PA::usdBracket((float) $a['overdue'], $a['currency'], (float) ($a['rate'] ?? 0));
                        $planRows[] = ['cells' => [$a['supplier'], ['value' => 'Pay ' . $amt, 'tone' => 'ok'], $ageCell($a)], 'link' => $link($a)];
                    }
                    if ($planRows) { $sub = count($planRows) . ' overdue · set a cash holder to get a cash-aware plan'; }
                }

                if ($planRows) {
                    $payPlanCard = ['kind' => 'table', 'title' => 'Who to pay today', 'sub' => $sub,
                        'columns' => [['label' => 'Supplier'], ['label' => 'Plan'], ['label' => 'Age', 'align' => 'right']],
                        'rows' => $planRows, 'empty' => 'Nothing overdue', 'pageSize' => 20];
                }
            } catch (\Throwable $e) {
                $payPlanCard = null;
            }
        }

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
        // Owner drawing — equity out, NOT an expense. `category` rides in the body so nobody has to
        // pick it, and TradeCashbookController::store() maps it to stage 'drawing', which cashPnl
        // already keeps out of operating profit. Cash still leaves the chosen person's hand.
        $evDrawing = ['key' => 'ev_drawing', 'label' => 'Owner drawing', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'note' => $comboPeople, 'holder' => $comboPeople],
            'labels' => ['native_amount' => 'Amount taken', 'rate_to_usd' => 'Rate (native per $1)', 'note' => 'Which owner is taking it', 'holder' => 'From whose cash'],
            'prefill' => ['currency' => 'UGX', 'rate_to_usd' => (int) round($ugx), 'holder' => $me],
            'body' => ['dir' => 'out', 'category' => 'Drawing'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];

        // Money borrowed from an owner/director. Whether this reads as DEBT or as EQUITY depends
        // entirely on books.loan_holders naming the lender — the row itself is identical either way.
        $evLoan = ['key' => 'ev_loan', 'label' => 'Borrowed a loan', 'method' => 'POST', 'endpoint' => '/papi/trade-cashbook', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'note' => $comboPeople, 'holder' => $comboPeople],
            'labels' => ['native_amount' => 'Amount borrowed', 'rate_to_usd' => 'Rate (native per $1)', 'note' => 'Who lent it', 'holder' => 'Into whose cash'],
            'prefill' => ['currency' => 'USD', 'rate_to_usd' => 1, 'holder' => $me],
            'body' => ['dir' => 'in', 'category' => 'Loan received'],
            'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap]];

        $goOrders = ['key' => 'go_orders', 'label' => 'Customer paid', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesOrders', 'actor' => 'human'];
        // Customer paid — inline receipt against an open order; the remaining balance is shown right here.
        $orderLabels = []; $orderMap = []; $recvAgg = []; $custOrderLabel = [];
        // This query had NO status filter, so Cancelled and Rejected orders were counted as money
        // customers owe — 32% of the receivables figure on the first tenant it was measured against,
        // feeding straight into the Working capital headline. A cancelled order is not a debt.
        foreach (Order::where('tenant_id', $t)->whereColumn('amount_paid', '<', 'total')
                     ->whereNotIn('status', self::NOT_RECEIVABLE)
                     ->orderByDesc('id')->limit(self::MAX_OPEN_ORDERS)->get(['id', 'order_no', 'customer_name', 'total', 'amount_paid', 'sell_currency']) as $o) {
            $bal = round(max(0, (float) $o->total - (float) $o->amount_paid), 2);
            if ($bal <= 0.005) { continue; }
            $ocur = strtoupper(trim((string) ($o->sell_currency ?: ''))) ?: $ctx->nativeCurrency();
            $cust = trim(str_replace('|', ' ', (string) ($o->customer_name ?: 'Customer')));
            $ono = (string) ($o->order_no ?: ('#' . $o->id));
            $label = str_replace('|', ' ', $ono . ' · ' . $cust . ' · owes ' . Money::fmt($bal, $ocur));
            $orderMap[$label] = (int) $o->id;                       // full lookup (resolves any prefill)
            if (count($orderLabels) < 60) { $orderLabels[] = $label; } // but only show the 60 most recent
            $ck = mb_strtolower($cust);
            if (! isset($custOrderLabel[$ck])) { $custOrderLabel[$ck] = $label; }   // for the receivables row button
        }

        // ---------------------------------------------------------------------------------------
        // RECEIVABLES COME FROM INVOICES, NOT ORDERS.
        //
        // Payments attach to an INVOICE. `orders.amount_paid` is never updated from them, so an
        // order that was invoiced and then paid sits at zero for ever and reads as outstanding.
        // Measured on the first tenant this was checked against: 63 delivered orders showed
        // USD 20,912 "unpaid" — every one of them at exactly 0.00 paid — while 62 of 85 payments
        // were attached to invoices and settled. The money was collected; only the orders looked open.
        //
        // (Related, still unfixed elsewhere: where `orders.amount_paid` IS written it is stored in
        // UGX while `total` is in USD — exactly ×3,655 every time — so `whereColumn('amount_paid',
        // '<', 'total')` compares two different currencies. Another reason not to price debt off it.)
        //
        // This is the same source /papi/finance-overview uses, so Finance and the Cashbook can no
        // longer disagree about what customers owe.
        // ---------------------------------------------------------------------------------------
        $recvAgg = [];
        foreach (\App\Models\Invoice::where('tenant_id', $t)->where('status', '!=', 'void')->get(['id', 'invoice_no', 'customer_name', 'currency', 'balance']) as $inv) {
            $bal = round((float) $inv->balance, 2);
            if ($bal <= 0.005) { continue; }
            $icur = \App\Services\Money\SalesBaseCurrency::txnCurrency($inv, $ctx->tenant);
            $cust = trim(str_replace('|', ' ', (string) ($inv->customer_name ?: 'Customer')));
            $rk = mb_strtolower($cust) . '|' . $icur;
            if (! isset($recvAgg[$rk])) {
                $recvAgg[$rk] = ['customer' => $cust, 'currency' => $icur, 'owed' => 0.0,
                    'order' => $custOrderLabel[mb_strtolower($cust)] ?? ''];
            }
            $recvAgg[$rk]['owed'] += $bal;
        }
        $customerPaid = count($orderLabels) ? [
            'key' => 'ev_customer_paid', 'label' => 'Customer paid', 'method' => 'POST', 'endpoint' => '/papi/record-payment', 'actor' => 'human', 'tone' => 'primary',
            // `received_by` names whose cash this lands in. PanelApiController::recordPayment() has
            // always accepted it (and `holder`) — this form simply never asked, so every payment taken
            // from the Cashbook silently went into whoever was logged in. The customer screen asks;
            // this now matches it.
            'schema' => ['order' => 'combo:' . implode('|', $orderLabels), 'amount' => 'money', 'received_by' => $comboPeople, 'note' => 'text'],
            'labels' => ['order' => 'Which order (customer · balance owed)', 'amount' => 'Amount received', 'received_by' => 'Received by (whose cash)', 'note' => 'Note (auto)'],
            'prefill' => ['order' => $orderLabels[0], 'received_by' => $me],
            'body' => ['notify' => '0'],
            'autoNote' => ['template' => 'Customer payment', 'watch' => []],
            'valueMap' => ['field' => 'order', 'into' => 'order', 'map' => $orderMap, 'error' => 'Pick an order from the list'],
            'onResult' => 'payment',
        ] : $goOrders;

        // --- "Customers who owe you" (receivables) — the mirror of the payables tab ---
        $recvRows = [];
        // Gate on $recvAgg (invoices), not $orderLabels (orders) — a tenant can have an unpaid
        // invoice with no open order behind it, and the whole card used to vanish in that case.
        if (count($recvAgg)) {
            $recvList = array_values($recvAgg);
            usort($recvList, fn ($a, $b) => $b['owed'] <=> $a['owed']);
            foreach ($recvList as $rv) {
                if ($rv['owed'] <= 0.005) { continue; }
                // The payment button needs an ORDER (that is what /papi/record-payment resolves).
                // If this customer has no open order to point at, show the debt without the button
                // rather than prefilling somebody else's order.
                $btns = [];
                if (($rv['order'] ?? '') !== '' && count($orderLabels)) {
                    $recAct = array_merge($customerPaid, ['key' => 'ev_customer_paid', 'label' => 'Payment from ' . $rv['customer'],
                        'prefill' => array_merge($customerPaid['prefill'] ?? [], ['order' => $rv['order']])]);
                    $btns[] = ['icon' => 'dollar', 'title' => 'Record payment from ' . $rv['customer'], 'tone' => 'primary', 'action' => $recAct];
                }
                $recvRows[] = ['cells' => [
                    $rv['customer'],
                    ['value' => Money::fmt(round($rv['owed'], 2), $rv['currency']), 'tone' => 'danger'],
                    ['buttons' => $btns],
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
        $tiles[] = ['icon' => 'user', 'title' => 'Owner drawing', 'sub' => 'An owner takes money out — not an expense, profit is untouched', 'tone' => 'out', 'action' => $evDrawing];
        $tiles[] = ['icon' => 'dollar', 'title' => 'Borrowed a loan', 'sub' => 'An owner lends money to the business', 'tone' => 'in', 'action' => $evLoan];
        if ($transfer) { $tiles[] = ['icon' => 'chev', 'title' => 'Moved cash between people', 'sub' => 'Hand cash from one person to another', 'tone' => 'move', 'action' => array_merge($transfer, ['label' => 'Move cash between people'])]; }

        $record = ['key' => 'record_activity', 'label' => 'Record activity', 'actor' => 'human', 'tone' => 'primary', 'chooser' => $tiles];
        $mFrom = now()->startOfMonth()->toDateString();
        $mTo = now()->endOfMonth()->toDateString();
        $exportPdf = ['key' => 'export_pdf', 'label' => 'Download PDF statement', 'href' => '/api/v1/doc/cashbook-export?format=pdf', 'actor' => 'human', 'tone' => 'default'];
        $exportCsv = ['key' => 'export_csv', 'label' => 'Download CSV (Excel)', 'href' => '/api/v1/doc/cashbook-export?format=csv', 'actor' => 'human', 'tone' => 'default'];
        $exportPdfMonth = ['key' => 'export_pdf_month', 'label' => 'PDF — this month', 'href' => '/api/v1/doc/cashbook-export?format=pdf&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
        $exportCsvMonth = ['key' => 'export_csv_month', 'label' => 'CSV — this month', 'href' => '/api/v1/doc/cashbook-export?format=csv&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
        // Company account book (consolidated USD) — the bookkeeper's ledger of every transaction in USD.
        $exportCompanyPdf = ['key' => 'export_company_pdf', 'label' => 'Company book — USD (PDF)', 'href' => '/api/v1/doc/cashbook-export?format=pdf&view=company', 'actor' => 'human', 'tone' => 'default'];
        $exportCompanyCsv = ['key' => 'export_company_csv', 'label' => 'Company book — USD (CSV)', 'href' => '/api/v1/doc/cashbook-export?format=csv&view=company', 'actor' => 'human', 'tone' => 'default'];
        $exportCompanyMonthPdf = ['key' => 'export_company_month_pdf', 'label' => 'Company book — this month (PDF)', 'href' => '/api/v1/doc/cashbook-export?format=pdf&view=company&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
        $exportCompanyMonthCsv = ['key' => 'export_company_month_csv', 'label' => 'Company book — this month (CSV)', 'href' => '/api/v1/doc/cashbook-export?format=csv&view=company&from=' . $mFrom . '&to=' . $mTo, 'actor' => 'human', 'tone' => 'default'];
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
            ->tabSections(['holders' => [$holderCard], 'entries' => [$entriesCard], 'company' => [$companyCard], 'receivables' => [$receivablesCard], 'payables' => array_values(array_filter([$payPlanCard, $payablesCard]))])
            ->tabs([
                ['key' => 'holders', 'label' => 'Cash by person', 'count' => count($holderRows)],
                ['key' => 'entries', 'label' => 'All entries', 'count' => count($entryRows)],
                ['key' => 'company', 'label' => 'Company account (USD)', 'count' => count($companyRows)],
                ['key' => 'receivables', 'label' => 'Customers who owe you', 'count' => count($recvRows)],
                ['key' => 'payables', 'label' => 'Suppliers you owe', 'count' => count($payRows)],
            ])
            ->actions($headerActions)
            ->more(array_merge([$exportCompanyPdf, $exportCompanyCsv, $exportCompanyMonthPdf, $exportCompanyMonthCsv, $exportPdf, $exportCsv, $exportPdfMonth, $exportCsvMonth], $curExports))
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
