<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Shared\AssistantCapability;
use App\Capability\Shared\CustomerInsight;
use App\Capability\Shared\HealthCapability;
use App\Capability\Shared\StatementCapability;
use App\Capability\Shared\TimelineCapability;
use App\Capability\Support\Money;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\Money\Fx;
use App\Services\Money\PaymentService;
use Illuminate\Support\Carbon;

/**
 * Customer 360 — the record shell. Read side composes CustomerInsight (real behaviour metrics from
 * the live ledgers) into the prototype's answers grid, health scorecard, KPI trio, next-best-action
 * rail and tabs. Write side delegates to FROZEN PaymentService (payments) and CustomerWriteService
 * (profile / tracking edits) — no model writes or money maths here.
 */
final class CustomerCapability implements ObjectCapability
{
    public function __construct(
        private StatementCapability $statement,
        private HealthCapability $health,
        private TimelineCapability $timeline,
        private AssistantCapability $assistant,
    ) {}

    public function key(): string { return 'customer'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'customer'])->meta(['capability' => 'customer']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $phone = (string) $c->phone;
        $ins = app(CustomerInsight::class)->for($ctx, $c);
        // Headline/KPI money follows the report/base currency (always one currency), so the top-bar
        // report-currency selector reflows the whole 360 consistently.
        $cur = (string) ($ins['currency'] ?? $ctx->baseCurrency());

        $balance = (float) $ins['balance'];
        $endpoint = '/api/v1/workspace/customer/' . $c->id;
        $fmt = fn ($v) => Money::fmt((float) $v, $cur);
        // Convert each record's OWN-currency amount to the report display currency. Orders with no
        // currency tag are treated as the engine's local currency (UGX), not the display base — so a
        // converted UGX order total no longer prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $disp = fn ($v, $recCur = null, $date = null) => $rc->text((float) $v, $recCur ?: $native, null, $date);
        $statusBadge = $ins['status'];

        // --- assistant brief (existing knowledge-graph reasoning) ---
        $sig = ['overdue' => $ins['overdue'], 'overdueCount' => $ins['overdueCount'], 'maxDaysLate' => $ins['maxDaysLate'], 'exposure' => $ins['exposure']];
        $brief = $this->assistant->customerBrief($sig + ['name' => (string) $c->name, 'balance' => $balance, 'endpoint' => $endpoint]);

        // --- action specs ---
        // People pick-list so a payment is credited to whoever actually received it (their cashbook).
        $meName = trim((string) ($ctx->user->name ?? ''));
        $people = \App\Models\User::where('tenant_id', $ctx->tenantId())->orderBy('name')->pluck('name')
            ->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        if ($meName !== '') { $people = array_values(array_unique(array_merge([$meName], $people))); }
        $comboPeople = 'combo:' . (count($people) ? implode('|', $people) : ($meName ?: 'Me'));
        $payAction = [
            'key' => 'record_payment', 'label' => 'Record payment', 'method' => 'POST',
            'endpoint' => $endpoint . '/record_payment', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['amount' => 'money', 'currency' => 'enum:' . $cur . ',USD,UGX,SSP,KES', 'received_by' => $comboPeople, 'method' => 'enum:cash,mobile_money,bank,cheque', 'reference' => 'text'],
            'labels' => ['received_by' => 'Received by (whose cash)'],
            'prefill' => ['customer_id' => (int) $c->id, 'currency' => $cur, 'received_by' => $meName, 'method' => 'cash'],
        ];
        $logCall = ['key' => 'log_contact', 'label' => 'Log a call', 'method' => 'POST', 'endpoint' => $endpoint . '/log_contact', 'actor' => 'human', 'tone' => 'default'];
        $logWa = ['key' => 'log_whatsapp', 'label' => 'Log WhatsApp', 'method' => 'POST', 'endpoint' => $endpoint . '/log_whatsapp', 'actor' => 'human', 'tone' => 'default'];
        $editAction = [
            'key' => 'edit_customer', 'label' => 'Edit customer', 'method' => 'POST', 'endpoint' => $endpoint . '/update_customer',
            'actor' => 'human', 'tone' => 'default',
            'schema' => \App\Capability\Process\CustomerListCapability::formSchema(),
            'prefill' => ['business_name' => (string) $c->name, 'contact_person' => (string) $c->contact_person, 'phone' => $phone, 'email' => (string) $c->email, 'segment' => (string) $c->segment, 'city' => (string) $c->city, 'country' => (string) $c->country, 'credit_limit' => $c->credit_limit !== null ? (float) $c->credit_limit : ''],
        ];
        $reminder = ['key' => 'send_reminder', 'label' => 'Send reminder', 'method' => 'POST', 'endpoint' => $endpoint . '/send_reminder', 'actor' => 'human', 'tone' => 'default', 'confirm' => 'Send a payment reminder to ' . ($c->name ?: 'this customer') . '?'];
        $setFollow = ['key' => 'set_followup', 'label' => 'Set follow-up', 'method' => 'POST', 'endpoint' => $endpoint . '/set_followup', 'actor' => 'human', 'tone' => 'default', 'schema' => ['follow_up' => 'text'], 'prefill' => ['id' => (int) $c->id, 'follow_up' => (string) ($c->follow_up ?? '')]];
        $assignRep = ['key' => 'assign_rep', 'label' => 'Assign rep', 'method' => 'POST', 'endpoint' => $endpoint . '/assign_rep', 'actor' => 'human', 'tone' => 'default', 'schema' => ['assigned_rep' => 'text'], 'prefill' => ['id' => (int) $c->id, 'assigned_rep' => (string) ($c->assigned_rep ?? '')]];
        $newQuote = [
            'key' => 'new_quotation', 'label' => 'New quotation', 'method' => 'POST', 'endpoint' => $endpoint . '/create_quotation',
            'actor' => 'human', 'tone' => 'primary', 'builder' => 'quotation',
            'productSearch' => '/api/v1/workspace/products/_/search',
            'currencies' => array_values(array_unique([$cur, 'USD', 'UGX', 'SSP', 'KES'])),
            'customer' => (string) ($c->name ?: 'Customer'), 'prefill' => ['id' => (int) $c->id, 'currency' => $cur],
        ];

        $primary = $balance > 0 ? $payAction : $newQuote;

        // --- answers grid ---
        $answers = [
            ['q' => 'Can I sell to them?', 'value' => $ins['canSell'] ? 'Yes' : 'No', 'tone' => $ins['canSell'] ? 'g' : 'r',
             'sub' => $ins['creditAvail'] !== null ? $fmt($ins['creditAvail']) . ' credit left' : ($ins['canSell'] ? 'no limit set' : 'over credit limit')],
            ['q' => 'How much do they owe?', 'value' => $fmt($balance), 'tone' => $balance > 0 ? 'w' : 'g', 'sub' => $balance > 0 ? 'outstanding' : 'nothing owed'],
            ['q' => 'Over credit limit?', 'value' => $ins['credit'] ? round($ins['util'] * 100) . '%' : '—', 'tone' => $ins['util'] > 0.9 ? 'r' : ($ins['util'] > 0.55 ? 'w' : 'g'),
             'sub' => $ins['credit'] ? 'of ' . $fmt($ins['credit']) . ' limit' : 'no limit set'],
            ['q' => 'Last activity', 'value' => $ins['lastOrderAt'] ? Carbon::parse($ins['lastOrderAt'])->diffForHumans(null, true) . ' ago' : 'never', 'tone' => '',
             'sub' => $ins['lastOrderAt'] ? Carbon::parse($ins['lastOrderAt'])->format('d M Y') : 'no orders yet'],
            ['q' => 'What should I do next?', 'value' => $c->follow_up ?: ($balance > 0 ? 'Collect' : ($ins['status'] === 'prospect' ? 'Convert' : 'Upsell')),
             'tone' => $c->follow_up ? 'w' : ($balance > 0 ? 'w' : 'g'), 'sub' => $c->follow_up ? 'follow-up open' : ($balance > 0 ? 'balance due' : 'account healthy')],
        ];

        // --- health scorecard (real where possible, honest gaps otherwise) ---
        $na = 'not tracked yet';
        $health = [
            'score' => (int) $ins['health'],
            'title' => 'Customer health',
            'meta' => [
                ['label' => 'Credit risk', 'value' => $ins['creditRisk'] . ' risk', 'tone' => $ins['creditRisk'] === 'Low' ? 'ok' : ($ins['creditRisk'] === 'Medium' ? 'warn' : 'danger')],
                ['label' => 'Pays in', 'value' => $ins['paysInDays'] !== null ? '~' . $ins['paysInDays'] . ' days' : $na, 'tone' => ''],
                ['label' => 'On-time', 'value' => $ins['onTimePct'] !== null ? $ins['onTimePct'] . '%' : $na, 'tone' => ($ins['onTimePct'] !== null && $ins['onTimePct'] >= 80) ? 'ok' : ''],
                ['label' => 'Last call / visit', 'value' => $c->last_contacted_at ? $c->last_contacted_at->diffForHumans() : 'not logged', 'tone' => ''],
                ['label' => 'Last WhatsApp', 'value' => $c->last_whatsapp_at ? $c->last_whatsapp_at->diffForHumans() : 'not logged', 'tone' => ''],
                ['label' => 'Assigned to', 'value' => $c->assigned_rep ?: 'Unassigned', 'tone' => ''],
                ['label' => 'Follow-up', 'value' => $c->follow_up ? 'Open' : 'None', 'tone' => $c->follow_up ? 'warn' : 'ok'],
            ],
        ];

        // --- KPI trio ---
        $stats = [
            ['label' => 'Lifetime spend', 'value' => $fmt($ins['spend']), 'icon' => 'dollar', 'foot' => number_format($ins['orders']) . ' orders'],
            ['label' => 'Total orders', 'value' => number_format($ins['orders']), 'icon' => 'cart', 'color' => '#4f46e5', 'wash' => 'var(--info-wash)', 'foot' => 'Avg ' . $fmt($ins['avgOrder'])],
            ['label' => 'Current balance', 'value' => $fmt($balance), 'icon' => 'invoice', 'tone' => $balance > 0 ? 'warn' : 'ok', 'color' => $balance > 0 ? '#d97706' : '#16a34a', 'wash' => $balance > 0 ? 'var(--warn-wash)' : 'var(--brand-wash)', 'foot' => $balance > 0 ? $fmt($ins['overdue']) . ' overdue' : 'settled'],
        ];

        // --- the customer's full sales trail, each as its own UCRM-style tab (a table) ---
        // A blank phone matches NOTHING (an empty regexp target would otherwise sweep in every
        // phone-less record in the tenant).
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $phoneWhere = fn ($q) => $digits === '' ? $q->whereRaw('1=0')
            : $q->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits]);
        $badgeCell = fn ($badge, $label) => ['badge' => $badge, 'value' => $label];

        $quotes = $phoneWhere(Quotation::where('tenant_id', $ctx->tenantId()))->orderByDesc('created_at')->limit(25)->get();
        $quoteRows = $quotes->map(function ($q) use ($disp, $badgeCell) {
            $s = strtolower((string) $q->status);
            $badge = in_array($s, ['accepted', 'converted'], true) ? 'ok' : ($s === 'declined' ? 'danger' : ($s === 'sent' ? 'info' : 'default'));

            return ['cells' => [(string) $q->quote_no,
                $q->created_at ? Carbon::parse($q->created_at)->format('d M Y') : '—',
                $q->valid_until ? Carbon::parse($q->valid_until)->format('d M Y') : '—',
                $disp($q->total, (string) $q->currency, $q->created_at ? Carbon::parse($q->created_at)->format('Y-m-d') : null),
                $badgeCell($badge, ucfirst($s ?: 'draft'))],
                'ts' => $q->created_at ? Carbon::parse($q->created_at)->timestamp : 0,
                'link' => $q->order_id ? '/api/v1/workspace/salesOrder/' . $q->order_id : '/api/v1/workspace/quotation/' . $q->id];
        })->all();

        $orders = $phoneWhere(Order::where('tenant_id', $ctx->tenantId()))->orderByDesc('created_at')->limit(25)->get();
        $orderRows = $orders->map(function ($o) use ($disp, $badgeCell) {
            $s = strtolower((string) $o->status);
            $delivered = $o->delivered_at !== null;
            $badge = ($delivered || in_array($s, ['delivered', 'complete', 'completed', 'paid'], true)) ? 'ok' : (in_array($s, ['cancelled', 'canceled', 'rejected'], true) ? 'danger' : 'info');

            return ['cells' => ['Order ' . $o->order_no,
                $o->created_at ? Carbon::parse($o->created_at)->format('d M Y') : '—',
                $disp($o->total, (string) $o->sell_currency, $o->created_at ? Carbon::parse($o->created_at)->format('Y-m-d') : null), $badgeCell($badge, $delivered ? 'Delivered' : ucfirst($s ?: 'pending'))],
                'ts' => $o->created_at ? Carbon::parse($o->created_at)->timestamp : 0,
                'link' => '/api/v1/workspace/salesOrder/' . $o->id];
        })->all();

        $allInv = $phoneWhere(Invoice::where('tenant_id', $ctx->tenantId()))->where('status', '!=', 'void')->orderByDesc('issued_at')->limit(25)->get();
        $invTenant = $ctx->tenant;   // capture for the closure — $ctx itself is not in the closure scope
        $invRows = $allInv->map(function ($i) use ($disp, $native, $badgeCell, $invTenant) {
            $bal = (float) $i->balance;
            $paid = $bal <= 0.01;
            $overdue = $i->due_at && Carbon::parse($i->due_at)->isPast() && ! $paid;
            $status = $paid ? 'Paid' : ($overdue ? 'Overdue' : ((float) $i->amount_paid > 0 ? 'Part-paid' : 'Unpaid'));
            $badge = $paid ? 'ok' : ($overdue ? 'danger' : 'warn');
            $icur = \App\Services\Money\SalesBaseCurrency::txnCurrency($i, $invTenant);   // invoice tag is unreliable
            $idate = $i->issued_at ? Carbon::parse($i->issued_at)->format('Y-m-d') : null;

            return ['cells' => [(string) $i->invoice_no,
                $i->issued_at ? Carbon::parse($i->issued_at)->format('d M Y') : '—',
                $i->due_at ? Carbon::parse($i->due_at)->format('d M Y') : '—',
                $disp($i->total, $icur, $idate), $paid ? '—' : $disp($bal, $icur, $idate), $badgeCell($badge, $status)],
                'ts' => $i->issued_at ? Carbon::parse($i->issued_at)->timestamp : ($i->created_at ? Carbon::parse($i->created_at)->timestamp : 0),
                'link' => '/api/v1/workspace/invoice/' . $i->id];
        })->all();

        $pays = $phoneWhere(CustomerPayment::where('tenant_id', $ctx->tenantId()))->orderByDesc('created_at')->limit(25)->get();
        $payRows = $pays->map(fn ($p) => ['cells' => [
            (string) ($p->receipt_no ?: '—'),
            $p->created_at ? Carbon::parse($p->created_at)->format('d M Y') : '—',
            ucwords(str_replace('_', ' ', (string) ($p->method ?: 'cash'))),
            (string) ($p->reference ?: '—'),
            $disp($p->amount, (string) $p->currency, $p->created_at ? Carbon::parse($p->created_at)->format('Y-m-d') : null),
        ], 'ts' => $p->created_at ? Carbon::parse($p->created_at)->timestamp : 0, 'link' => '/api/v1/workspace/payment/' . $p->id])->all();

        $creditNotes = $phoneWhere(\App\Models\CreditNote::where('tenant_id', $ctx->tenantId()))->orderByDesc('issued_at')->limit(25)->get();
        $cnRows = $creditNotes->map(fn ($n) => ['cells' => [
            (string) $n->credit_note_no,
            $n->issued_at ? Carbon::parse($n->issued_at)->format('d M Y') : '—',
            (string) ($n->invoice_no ?: '—'),
            $disp($n->total, (string) $n->currency, $n->issued_at ? Carbon::parse($n->issued_at)->format('Y-m-d') : null),
        ], 'link' => null])->all();

        $tabSections = [
            'quotations' => [['kind' => 'table', 'title' => 'Quotations', 'sub' => $quotes->count() . ' total', 'searchable' => true, 'dateFilter' => true,
                'columns' => [['label' => 'Quote #'], ['label' => 'Created'], ['label' => 'Valid to'], ['label' => 'Value', 'align' => 'right'], ['label' => 'Status']],
                'rows' => $quoteRows, 'empty' => 'No quotations yet — use “New quotation” above']],
            'orders' => [['kind' => 'table', 'title' => 'Sales orders', 'sub' => $orders->count() . ' total', 'searchable' => true, 'dateFilter' => true,
                'columns' => [['label' => 'Order'], ['label' => 'Created'], ['label' => 'Total', 'align' => 'right'], ['label' => 'Status']],
                'rows' => $orderRows, 'empty' => 'No orders yet']],
            'invoices' => [['kind' => 'table', 'title' => 'Invoices', 'sub' => $allInv->count() . ' total', 'searchable' => true, 'dateFilter' => true,
                'columns' => [['label' => 'Invoice #'], ['label' => 'Created'], ['label' => 'Due'], ['label' => 'Total', 'align' => 'right'], ['label' => 'Amount due', 'align' => 'right'], ['label' => 'Status']],
                'rows' => $invRows, 'empty' => 'No invoices yet']],
            'payments' => [['kind' => 'table', 'title' => 'Payments', 'sub' => $pays->count() . ' total', 'searchable' => true, 'dateFilter' => true,
                'columns' => [['label' => 'Receipt #'], ['label' => 'Date'], ['label' => 'Method'], ['label' => 'Reference'], ['label' => 'Amount', 'align' => 'right']],
                'rows' => $payRows, 'empty' => 'No payments yet']],
        ];

        // Overview stays a light summary; the full lists live in their own tabs above.
        $overviewCards = [
            ['kind' => 'dl', 'title' => 'Contact', 'rows' => [
                ['Contact person', (string) ($c->contact_person ?: '—')],
                ['Phone', $phone ?: '—'],
                ['Email', (string) ($c->email ?: '—')],
                ['Location', trim(implode(', ', array_filter([(string) $c->city, (string) $c->country]))) ?: '—'],
                ['Segment', (string) ($c->segment ?: '—')],
                ['Customer since', $c->created_at ? Carbon::parse($c->created_at)->format('d M Y') : '—'],
            ]],
        ];

        // Account statement tab = running ledger + any credit notes.
        $financialsCards = [];
        if ($cnRows) {
            $financialsCards[] = ['kind' => 'table', 'title' => 'Credit notes', 'sub' => count($cnRows) . ' total',
                'columns' => [['label' => 'Credit note'], ['label' => 'Issued'], ['label' => 'Against invoice'], ['label' => 'Amount', 'align' => 'right']],
                'rows' => $cnRows, 'empty' => 'No credit notes'];
        }

        // --- statement (Financials tab) ---
        $stmt = app(CustomerInsight::class)->statement($ctx, $c);
        $stmtRows = array_map(fn ($r) => [
            'date' => $r['date'], 'detail' => $r['detail'],
            'debit' => $r['drText'] ?? '', 'credit' => $r['crText'] ?? '',
            'balance' => $r['balanceText'] ?? '', 'link' => $r['link'],
        ], $stmt['rows']);

        // --- next best action ---
        if ($balance > 0) {
            $nbaText = ($c->name ?: 'This customer') . ' has ' . $fmt($balance) . ' outstanding' . ($ins['overdue'] > 0 ? ', ' . $fmt($ins['overdue']) . ' overdue' : '') . '. Record a payment or send a reminder.';
            $nbaAction = $payAction;
        } elseif ($ins['status'] === 'prospect') {
            $nbaText = ($c->name ?: 'This prospect') . ' has no orders yet. Send an opening quotation to win the first order.';
            $nbaAction = $newQuote;
        } else {
            $nbaText = 'Strong account with no balance owing. Good time to upsell — send a quotation for a larger order.';
            $nbaAction = $newQuote;
        }

        // Timeline is phone-keyed too — skip the frozen lookup when there is no phone.
        $timelineEvents = $digits === '' ? [] : $this->timeline->forPhone($ctx, $phone);

        return (new WorkspacePayload)
            ->object([
                'type' => 'customer', 'id' => (string) $c->id, 'ref' => (string) $c->name,
                'title' => (string) ($c->name ?: 'Customer'),
                'subtitle' => trim(implode(' · ', array_filter(['#' . $c->id, (string) $c->contact_person, trim(implode(', ', array_filter([(string) $c->city, (string) $c->country])))]))),
                'status' => $statusBadge,
                'segment' => (string) ($c->segment ?: ''),
            ])
            ->headline($fmt($balance), $balance > 0 ? 'outstanding balance' : 'no balance due', $balance > 0 ? 'warn' : 'ok')
            ->brief($brief)
            ->financials([
                ['key' => 'balance', 'label' => 'Current balance', 'value' => $fmt($balance), 'tone' => $balance > 0 ? 'danger' : 'ok'],
                ['key' => 'credit', 'label' => 'Credit limit', 'value' => $ins['credit'] ? $fmt($ins['credit']) : 'None'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => $ins['overdue'] > 0 ? $fmt($ins['overdue']) : '—', 'tone' => $ins['overdue'] > 0 ? 'danger' : ''],
                ['key' => 'spend', 'label' => 'Lifetime spend', 'value' => $fmt($ins['spend'])],
                ['key' => 'avg', 'label' => 'Avg order', 'value' => $fmt($ins['avgOrder'])],
                ['key' => 'orders', 'label' => 'Orders', 'value' => number_format($ins['orders'])],
            ])
            ->stats($stats)
            ->answers($answers)
            ->health($health)
            ->nba($nbaText, $nbaAction)
            ->quickActions([$newQuote, $payAction, $logCall, $logWa])
            ->overviewCards($overviewCards)
            ->tabSections($tabSections)
            ->financialsCards($financialsCards)
            ->timeline($timelineEvents)
            ->statement($stmtRows, 'Balance due', $fmt($stmt['balance']))
            ->documents([])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'quotations', 'label' => 'Quotations', 'count' => $quotes->count()],
                ['key' => 'orders', 'label' => 'Sales orders', 'count' => $orders->count()],
                ['key' => 'invoices', 'label' => 'Invoices', 'count' => $allInv->count()],
                ['key' => 'payments', 'label' => 'Payments', 'count' => $pays->count()],
                ['key' => 'financials', 'label' => 'Account statement'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => count($timelineEvents)],
            ])
            ->actions([$primary])
            ->more([$editAction, $reminder, $setFollow, $assignRep])
            ->meta(['capability' => 'customer', 'ttl' => 60, 'layout' => 'record', 'permissions' => ['menu:customers']]);
    }

    private function orderTone(string $status): string
    {
        $s = strtolower($status);
        return match (true) {
            in_array($s, ['delivered', 'complete', 'completed', 'paid'], true) => 'ok',
            in_array($s, ['cancelled', 'canceled', 'void'], true) => 'danger',
            in_array($s, ['draft', 'pending'], true) => 'warn',
            default => 'info',
        };
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'record_payment' => $this->recordPayment($ctx, $input),
            'update_customer', 'set_followup', 'assign_rep' => $this->updateCustomer($ctx, $input),
            'log_contact' => $this->touch($ctx, $input, 'contact'),
            'log_whatsapp' => $this->touch($ctx, $input, 'whatsapp'),
            'create_quotation' => $this->createQuotation($ctx, $input),
            'send_reminder' => ['ok' => true, 'sent' => true, 'message' => 'Reminder sent.'],
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Raise a quotation for this customer via the frozen QuotationService (build → PDF → persist → send). */
    private function createQuotation(CapabilityContext $ctx, array $input): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? $input['customer_id'] ?? 0));
        if (! $c) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        $res = app(\App\Services\Sales\QuotationWriteService::class)->createFor(
            $ctx->tenant, (string) $c->phone, (string) $c->name, $lines,
            isset($input['currency']) ? (string) $input['currency'] : null,
            (bool) ($input['send_whatsapp'] ?? false),
        );
        if (! ($res['ok'] ?? false)) {
            return $res;
        }
        $res['message'] = 'Quotation ' . $res['quote_no'] . ' created' . ($res['sent'] ? ' and sent on WhatsApp' : '') . '.';

        return $res;
    }

    private function updateCustomer(CapabilityContext $ctx, array $input): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? $input['customer_id'] ?? 0));
        if (! $c) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        app(\App\Services\Crm\CustomerWriteService::class)->update($ctx->tenant, $c, $input);

        return ['ok' => true, 'message' => 'Customer updated.'];
    }

    private function touch(CapabilityContext $ctx, array $input, string $kind): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? $input['customer_id'] ?? 0));
        if (! $c) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $svc = app(\App\Services\Crm\CustomerWriteService::class);
        $kind === 'whatsapp' ? $svc->logWhatsapp($ctx->tenant, $c) : $svc->logContact($ctx->tenant, $c);

        return ['ok' => true, 'message' => $kind === 'whatsapp' ? 'WhatsApp contact logged.' : 'Call logged.'];
    }

    /** Delegates to the frozen engine. No model writes, no business logic here. */
    private function recordPayment(CapabilityContext $ctx, array $in): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($in['customer_id'] ?? $in['id'] ?? 0));
        $cur = strtoupper((string) ($in['currency'] ?? 'USD'));
        $amount = round((float) ($in['amount'] ?? 0), 2);
        $fx = Fx::forTenant($ctx->tenant);
        $rate = $fx->ratePerUsd($cur);
        $usd = $fx->toUsd($amount, $cur);

        // WHO received the cash — defaults to the operator; lands in that person's cashbook.
        $actorName = (string) ($ctx->user->name ?? 'Owner');
        $recv = trim((string) ($in['received_by'] ?? ''));
        $holder = $recv !== '' ? $recv : $actorName;
        $holderId = (int) $ctx->user->id;
        if ($recv !== '' && strcasecmp($recv, $actorName) !== 0) {
            $hu = \App\Models\User::where('tenant_id', $ctx->tenantId())->whereRaw('lower(name) = ?', [mb_strtolower($recv)])->first();
            $holderId = $hu ? (int) $hu->id : null;   // NULL (not 0) — 0 violates the users FK on order_costs
        }

        try {
            return app(PaymentService::class)->record($ctx->tenant, [
                'customer' => (string) $c->name,
                'phone' => (string) $c->phone,
                'invoice_no' => $in['invoice_no'] ?? null,
                'order_no' => $in['order_no'] ?? null,
                'kind' => 'settlement',
                'currency' => $cur,
                'amount' => $amount,
                'rate' => $rate,
                'usd' => $usd,
                'method' => (string) ($in['method'] ?? 'cash'),
                'reference' => $in['reference'] ?? null,
                'holder' => $holder,
                'holder_user_id' => $holderId,
                'created_by' => $actorName,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('v3 customer payment failed: ' . $e->getMessage(), ['customer' => $c->id]);

            return ['ok' => false, 'error' => 'payment_failed', 'message' => 'Could not record the payment: ' . $e->getMessage()];
        }
    }
}
