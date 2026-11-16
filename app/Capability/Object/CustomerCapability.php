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
        // Sales team (Internet-provider businesses): another salesperson's customer is not opened.
        if (\App\Services\Sales\SalesOwnership::enabled($ctx->tenant) && ! \App\Services\Sales\SalesOwnership::canManage($ctx->user)
            && $c->owner_user_id && (int) $c->owner_user_id !== (int) $ctx->user->id) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(CustomerProfile::class, [(int) $id]);
        }
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
        // `phone` rides along so the brief can offer a real statement send on an overdue account
        // instead of the no-op reminder it used to offer (v4.52).
        $brief = $this->assistant->customerBrief($sig + ['name' => (string) $c->name, 'balance' => $balance, 'endpoint' => $endpoint, 'phone' => (string) $c->phone]);

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
        // v4.52 — "Send reminder" is gone. It answered "Reminder sent." and sent nothing, for as long
        // as it existed. The honest version of chasing a customer is the statement below, which puts
        // the actual document in their hand; a button that lies about a send is worse than no button.
        $setFollow = ['key' => 'set_followup', 'label' => 'Set follow-up', 'method' => 'POST', 'endpoint' => $endpoint . '/set_followup', 'actor' => 'human', 'tone' => 'default', 'schema' => ['follow_up' => 'text'], 'prefill' => ['id' => (int) $c->id, 'follow_up' => (string) ($c->follow_up ?? '')]];
        $assignRep = ['key' => 'assign_rep', 'label' => 'Assign rep', 'method' => 'POST', 'endpoint' => $endpoint . '/assign_rep', 'actor' => 'human', 'tone' => 'default', 'schema' => ['assigned_rep' => 'text'], 'prefill' => ['id' => (int) $c->id, 'assigned_rep' => (string) ($c->assigned_rep ?? '')]];
        // Statement: download the PDF (GET href → doc endpoint) and send that same PDF to the customer
        // on WhatsApp. Both need a phone; hidden for phone-less prospects.
        //
        // v4.52 — the send now goes to /papi/statement-send, the panel's proven sender, exactly as the
        // invoice's "Send on WhatsApp" does. It attaches the real statement PDF. What it replaced sent
        // a plain-text summary built from the accounting ledger whose lines read "• Invoice: USD 1,234"
        // with no invoice number in them — unusable for a customer trying to work out what to pay — and
        // refused outright to send anything to a customer who was paid up.
        $hasPhone = preg_replace('/[^0-9]/', '', (string) $phone) !== '';   // $digits isn't computed until later
        $dlStatement = ['key' => 'statement_pdf', 'label' => 'Download statement (PDF)', 'href' => '/api/v1/doc/customer-statement?phone=' . rawurlencode($phone)];
        $sendStatement = ['key' => 'send_statement', 'label' => 'Send statement on WhatsApp', 'method' => 'POST', 'endpoint' => '/papi/statement-send', 'actor' => 'human', 'tone' => 'default', 'body' => ['phone' => $phone], 'confirm' => 'Send the account statement to ' . ($c->name ?: 'this customer') . ' on WhatsApp?'];
        $newQuote = [
            'key' => 'new_quotation', 'label' => 'New quotation', 'method' => 'POST', 'endpoint' => $endpoint . '/create_quotation',
            'actor' => 'human', 'tone' => 'primary', 'builder' => 'quotation',
            'productSearch' => '/api/v1/workspace/products/_/search',
            'currencies' => array_values(array_unique([$cur, 'USD', 'UGX', 'SSP', 'KES'])),
            'customer' => (string) ($c->name ?: 'Customer'), 'prefill' => ['id' => (int) $c->id, 'currency' => $cur],
        ];

        // "New task" — assign a follow-up/collection task tied to THIS customer. Amount is optional and,
        // when given, is folded into the task title (e.g. "Collect USD 68 from Mehul Patel").
        $staffOpts = \App\Models\User::where('tenant_id', $ctx->tenantId())->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($x) => '#' . $x->id . ' ' . str_replace(',', ' ', $x->name ?: ('User ' . $x->id)))
            ->all();
        $newTask = [
            'key' => 'assign_task', 'label' => 'New task', 'method' => 'POST',
            'endpoint' => $endpoint . '/assign_task', 'actor' => 'human', 'tone' => 'default',
            'submitLabel' => 'Create task',
            'schema' => [
                'assignee' => 'enum:' . implode(',', $staffOpts ?: ['#0 No staff']),
                'title'    => 'text',
                'amount'   => 'money',
                'currency' => 'enum:' . $cur . ',USD,UGX,SSP,KES',
                'due_at'   => 'datetime',
                'priority' => 'enum:Normal,High,Low',
                'attachment' => 'file:image/*,application/pdf',
            ],
            'labels' => ['assignee' => 'Assign to', 'title' => 'Task', 'amount' => 'Amount to collect (optional)', 'due_at' => 'Due (optional)', 'priority' => 'Priority', 'attachment' => 'Photo or document (optional)'],
            'prefill' => ['id' => (int) $c->id, 'title' => 'Collect from ' . ($c->name ?: 'customer'), 'currency' => $cur, 'priority' => 'Normal'],
        ];

        // A customer who owes NOTHING is exactly the one who pays in advance — and that was the single
        // case where "Record payment" got demoted out of the header into the rail, where nobody looks.
        // It is now always in the header: it leads when there is a balance to settle, and sits beside
        // New quotation when there is not. An advance from a settled customer is held as credit on
        // account (PaymentService::record → resolveTarget), so this button is the entry point for it.
        $primary = $balance > 0 ? $payAction : $newQuote;
        $secondary = $balance > 0 ? $newQuote : array_merge($payAction, ['tone' => 'default']);

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

        // This customer's tasks (collection / follow-up), newest & still-open first.
        $custTasks = \App\Models\OrderTask::where('tenant_id', $ctx->tenantId())
            ->where('customer_id', $c->id)
            ->orderByRaw("(status = 'completed')")   // open (false) before done (true)
            ->orderByDesc('id')->limit(50)->get();
        $taskNames = app(\App\Services\Ops\TaskEngine::class)->assigneeNames($ctx->tenant);
        $taskRows = $custTasks->map(function ($tk) use ($taskNames) {
            $s = (string) $tk->status;
            $badge = $s === 'completed' ? 'ok' : ($s === 'in_progress' ? 'info' : ($s === 'exception' ? 'danger' : 'warn'));
            $label = $s === 'completed' ? 'Done' : ($s === 'in_progress' ? 'In progress' : ($s === 'exception' ? 'Blocked' : 'Open'));
            return ['cells' => [
                '#' . ($tk->ref_no ?: $tk->id),
                (string) $tk->title,
                $taskNames[$tk->owner_user_id] ?? '—',
                $tk->due_at ? Carbon::parse($tk->due_at)->format('d M Y') : '—',
                ['badge' => $badge, 'value' => $label],
            ], 'ts' => $tk->created_at ? Carbon::parse($tk->created_at)->timestamp : 0, 'link' => null];
        })->all();
        $openTaskCount = $custTasks->where('status', '!=', 'completed')->count();

        $tabSections = [
            'tasks' => [['kind' => 'table', 'title' => 'Tasks', 'sub' => $custTasks->count() . ' total', 'searchable' => true,
                'columns' => [['label' => '#'], ['label' => 'Task'], ['label' => 'Assignee'], ['label' => 'Due'], ['label' => 'Status']],
                'rows' => $taskRows, 'empty' => 'No tasks yet — use “New task” above']],
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

        // V4.0 Relationship card — the machine's memory, visible and correctable.
        if ((bool) $ctx->tenant->setting('relationship_memory', false)) {
            $rrows = [
                ['Stage', (string) ($c->stage ?: 'NEW')
                    .($c->stage_changed_at ? '  (since '.Carbon::parse($c->stage_changed_at)->format('d M').')' : '')],
                ['Health', $c->health !== null
                    ? $c->health.'/100'.($c->health_prev !== null && (int) $c->health_prev !== (int) $c->health
                        ? ((int) $c->health > (int) $c->health_prev ? ' ↑' : ' ↓').' (was '.$c->health_prev.')' : '')
                    : 'not scored yet'],
                ['Flags', implode(' · ', (array) ($c->health_flags ?? [])) ?: '—'],
            ];
            foreach ((array) ($c->facts ?? []) as $fk => $fv) {
                if (count($rrows) >= 11) break;
                if (is_array($fv)) $fv = implode(', ', array_map('strval', $fv));
                $rrows[] = [ucfirst(str_replace('_', ' ', (string) $fk)), mb_substr((string) $fv, 0, 110)];
            }
            $rrows[] = ['AI summary', (string) ($c->ai_summary ?: 'Builds itself from conversations — nothing yet.')];
            $overviewCards[] = ['kind' => 'dl', 'title' => 'Relationship (AI memory)', 'rows' => $rrows];
        }

        // v4.53 — money of theirs that we are holding: an overpayment they asked us to keep, or a
        // deposit taken before delivery. Shown in the currency it was received in, never converted,
        // because that is the money they will expect back.
        $heldRows = \App\Services\Money\CreditOnAccount::rowsFor($ctx->tenant, $phone);
        $held     = \App\Services\Money\CreditOnAccount::heldFor($ctx->tenant, $phone);
        $heldText = $held ? \App\Services\Money\CreditOnAccount::describe($held) : '';

        // v4.56 — what this customer does not buy that customers like them do. Every row carries the
        // raw counts rather than a score, because "7 of the 9 customers who buy Aluminium Foil also
        // buy Cling Film" can be argued with and "78% confidence" cannot.
        $gaps = [];
        try {
            $gaps = app(\App\Services\Growth\CrossSellService::class)->forCustomer($ctx->tenant, $phone);
        } catch (\Throwable $e) {
            $gaps = [];   // a suggestion is never worth breaking the customer record for
        }

        // Account statement tab = running ledger + any credit notes + any credit we are sitting on.
        $financialsCards = [];
        if ($cnRows) {
            $financialsCards[] = ['kind' => 'table', 'title' => 'Credit notes', 'sub' => count($cnRows) . ' total',
                'columns' => [['label' => 'Credit note'], ['label' => 'Issued'], ['label' => 'Against invoice'], ['label' => 'Amount', 'align' => 'right']],
                'rows' => $cnRows, 'empty' => 'No credit notes'];
        }
        // Where the credit came from, so the headline figure never has to be taken on trust.
        if ($heldRows->isNotEmpty()) {
            $financialsCards[] = ['kind' => 'table', 'title' => 'Credit on account',
                'sub' => 'We are holding ' . $heldText . ' of this customer\'s money',
                'columns' => [['label' => 'Receipt'], ['label' => 'Received'], ['label' => 'How'], ['label' => 'Amount', 'align' => 'right']],
                'rows' => $heldRows->map(fn ($p) => [
                    (string) $p->receipt_no,
                    optional($p->created_at)->format('d M Y'),
                    CustomerInsight::methodWords((string) $p->method),
                    strtoupper((string) $p->currency) . ' ' . number_format((float) $p->amount, strtoupper((string) $p->currency) === 'USD' ? 2 : 0),
                ])->all(),
                'empty' => 'None'];
        }

        // --- statement (Financials tab) ---
        $stmt = app(CustomerInsight::class)->statement($ctx, $c);
        $stmtRows = array_map(fn ($r) => [
            'date' => $r['date'], 'detail' => $r['detail'],
            'debit' => $r['drText'] ?? '', 'credit' => $r['crText'] ?? '',
            'balance' => $r['balanceText'] ?? '', 'link' => $r['link'],
        ], $stmt['rows']);

        if ($gaps) {
            $overviewCards[] = ['kind' => 'table', 'title' => 'Not buying yet',
                'sub' => 'Bought by customers who buy what they buy — worth asking about',
                'columns' => [['label' => 'Product'], ['label' => 'Why'], ['label' => 'Price', 'align' => 'right']],
                'rows' => array_map(fn ($g) => [
                    (string) $g['product'],
                    (string) $g['sentence'],
                    $fmt((float) $g['value']),
                ], $gaps),
                'empty' => 'Nothing obvious'];
        }

        // --- next best action ---
        if ($balance > 0 && $heldText !== '') {
            // Both at once is the case worth catching. Chasing someone for money while sitting on
            // their money is the kind of thing that loses a customer for a reason nobody writes down.
            $nbaText = ($c->name ?: 'This customer') . ' has ' . $fmt($balance) . ' outstanding, and we are holding '
                . $heldText . ' of theirs. Put the credit against an open invoice before asking for anything.';
            $nbaAction = $payAction;
        } elseif ($balance > 0) {
            $nbaText = ($c->name ?: 'This customer') . ' has ' . $fmt($balance) . ' outstanding' . ($ins['overdue'] > 0 ? ', ' . $fmt($ins['overdue']) . ' overdue' : '') . '. Record a payment or send them their statement.';
            $nbaAction = $payAction;
        } elseif ($heldText !== '') {
            $nbaText = 'Nothing owing, but we are holding ' . $heldText . ' of their money. It will go against their next invoice.';
            $nbaAction = $newQuote;
        } elseif ($gaps) {
            $nbaText = ($c->name ?: 'This customer') . ' does not buy ' . $gaps[0]['product'] . '. '
                . $gaps[0]['sentence'] . ' Worth raising on the next call.';
            $nbaAction = $newQuote;
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
            ->financials(array_values(array_filter([
                ['key' => 'balance', 'label' => 'Current balance', 'value' => $fmt($balance), 'tone' => $balance > 0 ? 'danger' : 'ok'],
                // Only when there is some. A permanent "Credit held: none" tile trains everyone to
                // stop reading the row, which defeats the point of showing it at all.
                $heldText !== '' ? ['key' => 'held', 'label' => 'Credit we hold', 'value' => $heldText, 'tone' => 'ok'] : null,
                ['key' => 'credit', 'label' => 'Credit limit', 'value' => $ins['credit'] ? $fmt($ins['credit']) : 'None'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => $ins['overdue'] > 0 ? $fmt($ins['overdue']) : '—', 'tone' => $ins['overdue'] > 0 ? 'danger' : ''],
                ['key' => 'spend', 'label' => 'Lifetime spend', 'value' => $fmt($ins['spend'])],
                ['key' => 'avg', 'label' => 'Avg order', 'value' => $fmt($ins['avgOrder'])],
                ['key' => 'orders', 'label' => 'Orders', 'value' => number_format($ins['orders'])],
            ])))
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
                ['key' => 'tasks', 'label' => 'Tasks', 'count' => $openTaskCount],
                ['key' => 'financials', 'label' => 'Account statement'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => count($timelineEvents)],
            ])
            ->actions(array_values(array_filter([$primary, $secondary, $newTask, ['key' => 'bos_timeline', 'label' => '🕑 Timeline', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/bos.timeline/' . $c->id, 'actor' => 'human', 'tone' => 'default'],
                $this->ownerAction($ctx, $c, $endpoint)])))
            ->more(array_values(array_filter([
                $editAction,
                (bool) $ctx->tenant->setting('relationship_memory', false) ? [
                    'key' => 'set_stage', 'label' => 'Set relationship stage', 'method' => 'POST',
                    'endpoint' => $endpoint . '/set_stage', 'actor' => 'human', 'submitLabel' => 'Set stage',
                    'schema' => ['stage' => 'enum:' . implode(',', \App\Services\Relationship\RelationshipExtractor::STAGES)],
                    'labels' => ['stage' => 'Stage (LOST and VIP are yours to decide — the AI never sets them)'],
                    'prefill' => ['id' => (int) $c->id, 'stage' => (string) ($c->stage ?: 'NEW')],
                ] : null,
                (bool) $ctx->tenant->setting('relationship_memory', false) ? [
                    'key' => 'edit_summary', 'label' => 'Edit AI summary', 'method' => 'POST',
                    'endpoint' => $endpoint . '/edit_summary', 'actor' => 'human', 'submitLabel' => 'Save summary',
                    'schema' => ['summary' => 'textarea'],
                    'prefill' => ['id' => (int) $c->id, 'summary' => (string) ($c->ai_summary ?: '')],
                ] : null,
                (bool) $ctx->tenant->setting('relationship_memory', false) ? [
                    'key' => 'correct_fact', 'label' => 'Correct a fact', 'method' => 'POST',
                    'endpoint' => $endpoint . '/correct_fact', 'actor' => 'human', 'submitLabel' => 'Save fact',
                    'schema' => ['fact_key' => 'combo:' . (implode('|', array_map('strval', array_keys((array) ($c->facts ?? [])))) ?: 'usual_products'),
                                 'fact_value' => 'text'],
                    'labels' => ['fact_key' => 'Fact', 'fact_value' => 'Correct value (leave EMPTY to delete the fact)'],
                    'prefill' => ['id' => (int) $c->id],
                ] : null,
                $hasPhone ? $dlStatement : null,
                $hasPhone ? $sendStatement : null,
                $setFollow, $assignRep,
            ])))
            ->meta(['capability' => 'customer', 'ttl' => 60, 'layout' => 'record', 'permissions' => ['menu:customers']]);
    }

    /**
     * Create a follow-up / collection task tied to this customer. A given amount is folded into the
     * title ("Collect USD 68 from Mehul Patel"); the task then flows through the normal Tasks screen,
     * the assignee's WhatsApp nudge, and — on completion — the done-confirmation to owner + assignee.
     */
    private function assignTask(CapabilityContext $ctx, array $input): array
    {
        $u = $ctx->user;
        if (! ($u->isOwnerLike() || $u->role === 'manager')) {
            return ['ok' => false, 'error' => 'Only a manager or owner can assign tasks.'];
        }

        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $c) return ['ok' => false, 'error' => 'Customer not found.'];

        // Assignee arrives as "#id Name" from the enum; accept a raw id too.
        $raw = (string) ($input['assignee'] ?? $input['assignee_id'] ?? '');
        $assignee = preg_match('/#?(\d+)/', $raw, $m)
            ? \App\Models\User::where('tenant_id', $ctx->tenantId())->find((int) $m[1])
            : null;
        if (! $assignee) return ['ok' => false, 'error' => 'Pick a staff member to assign the task to.'];

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') $title = 'Follow up with ' . ($c->name ?: 'customer');

        // Fold an optional "amount to collect" into the title so it reads naturally and shows everywhere.
        $amount = (float) ($input['amount'] ?? 0);
        if ($amount > 0) {
            $curr  = strtoupper(trim((string) ($input['currency'] ?? '')));
            $amt   = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');   // 68 / 68.5
            $money = trim(($curr ? $curr . ' ' : '') . $amt);
            if (preg_match('/^collect from /i', $title)) {
                $title = 'Collect ' . $money . ' from ' . mb_substr($title, strlen('Collect from '));
            } else {
                $title = $title . ' (' . $money . ')';
            }
        }

        $due = ! empty($input['due_at']) ? Carbon::parse($input['due_at']) : null;
        $prioMap  = ['low' => 0, 'normal' => 1, 'high' => 2];
        $priority = $prioMap[strtolower(trim((string) ($input['priority'] ?? 'normal')))] ?? 1;
        // Optional photo/PDF attached in the form (inline base64) → stored, its URL rides on the task.
        $attach = \App\Support\Base64Upload::store($ctx->tenantId(), (string) ($input['attachment'] ?? ''));

        $res = app(\App\Services\Ops\TaskEngine::class)
            ->assignAdHoc($ctx->tenant, $assignee, $title, $due, $priority, $u, 0, (int) $c->id, $attach);
        if (! empty($res['ok'])) $res['message'] = 'Task assigned to ' . ($assignee->name ?: 'staff') . ' — ' . $title;
        return $res;
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
            'set_stage', 'edit_summary', 'correct_fact' => $this->relationshipUpdate($ctx, $action, $input),
            'log_contact' => $this->touch($ctx, $input, 'contact'),
            'log_whatsapp' => $this->touch($ctx, $input, 'whatsapp'),
            'create_quotation' => $this->createQuotation($ctx, $input),
            'assign_task' => $this->assignTask($ctx, $input),
            'reassign_owner' => $this->reassignOwner($ctx, $input),
            // v4.52 — send_statement now posts straight to /papi/statement-send (see actions above),
            // and send_reminder is gone rather than left answering "Reminder sent." to a send that
            // never happened. Anything still calling either name gets an honest unknown_action.
            'timeline' => $this->bosTimeline($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /**
     * Sales team (Internet-provider businesses): who handles this customer, and — for an owner or a
     * manager — the control to move them to another salesperson (logged, reason required).
     */
    private function ownerAction(CapabilityContext $ctx, CustomerProfile $c, string $endpoint): ?array
    {
        if (! \App\Services\Sales\SalesOwnership::enabled($ctx->tenant) || ! \App\Services\Sales\SalesOwnership::canManage($ctx->user)) {
            return null;
        }
        $staff = \App\Models\User::where('tenant_id', $ctx->tenantId())->whereIn('role', ['sales', 'manager', 'owner', 'admin'])->orderBy('name')->get(['id', 'name']);
        $current = $c->owner_user_id ? (string) ($staff->firstWhere('id', (int) $c->owner_user_id)?->name ?? '') : '';

        return [
            'key' => 'reassign_owner', 'label' => $current !== '' ? 'Salesperson: ' . $current : 'Assign salesperson', 'method' => 'POST',
            'endpoint' => $endpoint . '/reassign_owner', 'actor' => 'human', 'tone' => 'default', 'submitLabel' => 'Assign',
            'schema' => ['owner' => 'combo:' . $staff->map(fn ($u) => $u->id . ' — ' . $u->name)->implode('|'), 'reason' => 'text'],
            'labels' => ['owner' => 'Salesperson', 'reason' => 'Why (recorded)'],
            'prefill' => ['customer_id' => (int) $c->id],
        ];
    }

    private function reassignOwner(CapabilityContext $ctx, array $input): array
    {
        if (! \App\Services\Sales\SalesOwnership::enabled($ctx->tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? $input['customer_id'] ?? 0));
        if (! $c) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $to = (int) preg_replace('/\D.*$/s', '', trim((string) ($input['owner'] ?? '')));   // "12 — Name" → 12
        $r = app(\App\Services\Sales\SalesOwnership::class)->reassign($ctx->tenant, (string) $c->phone, $to, $ctx->user, (string) ($input['reason'] ?? ''));
        if (! $r['ok']) {
            return $r + ['message' => match ($r['error'] ?? '') {
                'forbidden' => 'Only an owner or a manager can change a customer\'s salesperson.',
                'reason_required' => 'Please say why the customer is moving.',
                'unknown_staff' => 'Choose someone from your own team.',
                default => 'The salesperson was not changed.',
            }];
        }

        return ['ok' => true, 'message' => 'Salesperson changed'];
    }

    /**
     * BOS Slice 2 — the Customer Timeline: this customer's whole history as one stream, read from the
     * event spine (bos_events). Pure read; no writes. The outstanding total comes from live invoices.
     */
    private function bosTimeline(CapabilityContext $ctx, array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find($id);
        if (! $c) return ['ok' => false, 'error' => 'not_found'];

        $outstanding = 0.0;
        try {
            $outstanding = (float) \App\Models\Invoice::where('tenant_id', $ctx->tenantId())
                ->where('customer_phone', $c->phone)->where('status', '!=', 'void')->sum('balance');
        } catch (\Throwable $e) { /* invoices optional */ }

        // Modern Customer-360 strip — all REAL (no mocked figures): lifetime paid, last order, credit standing, score.
        $lifetime = 0.0;
        try { $lifetime = (float) \App\Models\CustomerPayment::where('tenant_id', $ctx->tenantId())
            ->where('customer_phone', $c->phone)->sum('usd_amount'); } catch (\Throwable $e) {}
        $lastOrder = null;
        try { $lastOrder = \App\Models\Order::where('tenant_id', $ctx->tenantId())
            ->where('customer_phone', $c->phone)->max('created_at'); } catch (\Throwable $e) {}
        $limit = (float) ($c->credit_limit ?? 0);
        $standing = 'good';
        if ($limit > 0 && $outstanding > $limit)           $standing = 'over';
        elseif ($limit > 0 && $outstanding > 0.8 * $limit) $standing = 'watch';
        // Relationship score 1–5 from REAL behaviour: pays (has lifetime), owes nothing now, within credit.
        $score = 3;
        if ($lifetime > 0)         $score++;
        if ($outstanding <= 0.5)   $score++;
        if ($standing === 'watch') $score--;
        if ($standing === 'over')  $score -= 2;
        $score = max(1, min(5, $score));

        $lines = [];
        try { $lines = \App\BOS\Bos::timeline('customer', (int) $c->id, (int) $ctx->tenantId(), 200); }
        catch (\Throwable $e) { $lines = []; }

        // BOS Slice 4 — Order-to-Cash journey: where this customer's latest deal stands + the next step.
        $journey = null;
        try { $journey = (new \App\BOS\Process\SalesJourney((int) $ctx->tenantId(), (int) $c->id))->build(); }
        catch (\Throwable $e) { $journey = null; }

        return [
            'ok' => true,
            'customer' => [
                'id' => (int) $c->id, 'name' => (string) ($c->name ?: 'Customer'),
                'phone' => (string) $c->phone, 'city' => (string) ($c->city ?? ''),
                'outstanding' => round($outstanding, 2),
                'lifetime' => round($lifetime, 2),
                'last_order' => $lastOrder ? \Illuminate\Support\Carbon::parse($lastOrder)->format('d M') : null,
                'credit' => ['limit' => round($limit, 2), 'standing' => $standing],
                'score' => (int) $score,
            ],
            'journey' => $journey,
            'timeline' => $lines,
        ];
    }

    /** V4.0 — humans own the memory: stage overrides (incl. LOST — sticky, see stages cron),
     *  summary edits, fact corrections/deletions. */
    private function relationshipUpdate(CapabilityContext $ctx, string $action, array $input): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $c) return ['ok' => false, 'error' => 'not_found'];

        if ($action === 'set_stage') {
            $stage = strtoupper(trim((string) ($input['stage'] ?? '')));
            if (! in_array($stage, \App\Services\Relationship\RelationshipExtractor::STAGES, true)) {
                return ['ok' => false, 'error' => 'bad_stage'];
            }
            $c->stage = $stage;
            $c->stage_changed_at = now();
            $c->save();
            return ['ok' => true, 'message' => 'Stage set to '.$stage];
        }
        if ($action === 'edit_summary') {
            $c->ai_summary = mb_substr(trim((string) ($input['summary'] ?? '')), 0, 1200) ?: null;
            $c->save();
            return ['ok' => true, 'message' => 'Summary saved'];
        }
        // correct_fact
        $k = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['fact_key'] ?? ''))), 0, 40);
        if ($k === '') return ['ok' => false, 'error' => 'empty_key'];
        $facts = (array) ($c->facts ?? []);
        $v = trim((string) ($input['fact_value'] ?? ''));
        if ($v === '') { unset($facts[$k]); $msg = 'Fact removed'; }
        else { $facts[$k] = mb_substr($v, 0, 160); $msg = 'Fact saved'; }
        $c->facts = $facts;
        $c->save();

        return ['ok' => true, 'message' => $msg];
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
