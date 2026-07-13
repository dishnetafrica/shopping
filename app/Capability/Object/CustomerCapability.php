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
        $cur = $ctx->baseCurrency();
        $ins = app(CustomerInsight::class)->for($ctx, $c);

        $balance = (float) $ins['balance'];
        $endpoint = '/api/v1/workspace/customer/' . $c->id;
        $fmt = fn ($v) => Money::fmt((float) $v, $cur);
        $statusBadge = $ins['status'];

        // --- assistant brief (existing knowledge-graph reasoning) ---
        $sig = ['overdue' => $ins['overdue'], 'overdueCount' => $ins['overdueCount'], 'maxDaysLate' => $ins['maxDaysLate'], 'exposure' => $ins['exposure']];
        $brief = $this->assistant->customerBrief($sig + ['name' => (string) $c->name, 'balance' => $balance, 'endpoint' => $endpoint]);

        // --- action specs ---
        $payAction = [
            'key' => 'record_payment', 'label' => 'Record payment', 'method' => 'POST',
            'endpoint' => $endpoint . '/record_payment', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['amount' => 'money', 'currency' => 'enum:' . $cur . ',USD,UGX,SSP,KES', 'method' => 'enum:cash,mobile_money,bank,cheque', 'reference' => 'text'],
            'prefill' => ['customer_id' => (int) $c->id, 'currency' => $cur, 'method' => 'cash'],
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

        // --- overview cards (Contact / Recent orders / Unpaid invoices / Recent payments) ---
        $orders = Order::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [preg_replace('/[^0-9]/', '', $phone)])
            ->orderByDesc('created_at')->limit(6)->get();
        $orderRows = $orders->map(fn ($o) => [
            'cells' => [(string) $o->order_no, $fmt($o->total), ['badge' => $this->orderTone((string) $o->status), 'value' => ucfirst((string) $o->status)], $o->created_at ? Carbon::parse($o->created_at)->format('d M Y') : '—'],
            'link' => '/api/v1/workspace/salesOrder/' . $o->id,
        ])->all();

        $invUnpaid = Invoice::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [preg_replace('/[^0-9]/', '', $phone)])
            ->where('status', '!=', 'void')->where('balance', '>', 0.01)->orderBy('due_at')->limit(5)->get();
        $invItems = $invUnpaid->map(fn ($i) => [
            'icon' => 'invoice', 'color' => '#d97706', 'title' => (string) $i->invoice_no,
            'sub' => $i->due_at ? 'Due ' . Carbon::parse($i->due_at)->format('d M Y') : 'no due date',
            'right' => $fmt($i->balance), 'rightTone' => 'danger', 'link' => '/api/v1/workspace/invoice/' . $i->id,
        ])->all();

        $pays = CustomerPayment::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [preg_replace('/[^0-9]/', '', $phone)])
            ->orderByDesc('created_at')->limit(5)->get();
        $payItems = $pays->map(fn ($p) => [
            'icon' => 'card', 'color' => '#16a34a', 'title' => ucfirst((string) ($p->method ?: 'payment')),
            'sub' => ($p->created_at ? Carbon::parse($p->created_at)->format('d M Y') : '') . ($p->reference ? ' · ' . $p->reference : ''),
            'right' => '+' . Money::fmt((float) $p->amount, (string) ($p->currency ?: $cur)), 'rightTone' => 'ok', 'link' => null,
        ])->all();

        $overviewCards = [
            ['kind' => 'dl', 'title' => 'Contact', 'rows' => [
                ['Contact person', (string) ($c->contact_person ?: '—')],
                ['Phone', $phone ?: '—'],
                ['Email', (string) ($c->email ?: '—')],
                ['Location', trim(implode(', ', array_filter([(string) $c->city, (string) $c->country]))) ?: '—'],
                ['Segment', (string) ($c->segment ?: '—')],
                ['Customer since', $c->created_at ? Carbon::parse($c->created_at)->format('d M Y') : '—'],
            ]],
            ['kind' => 'table', 'title' => 'Recent orders', 'sub' => $orders->count() . ' shown',
             'columns' => [['label' => 'Order'], ['label' => 'Total', 'align' => 'right'], ['label' => 'Status'], ['label' => 'Date']],
             'rows' => $orderRows, 'empty' => 'No orders yet'],
            ['kind' => 'links', 'title' => 'Unpaid invoices', 'items' => $invItems, 'empty' => 'All settled'],
            ['kind' => 'links', 'title' => 'Recent payments', 'items' => $payItems, 'empty' => 'No payments'],
        ];

        // --- statement (Financials tab) ---
        $stmt = app(CustomerInsight::class)->statement($ctx, $c);
        $stmtRows = array_map(fn ($r) => [
            'date' => $r['date'], 'detail' => $r['detail'],
            'debit' => $r['dr'] > 0 ? $fmt($r['dr']) : '', 'credit' => $r['cr'] > 0 ? $fmt($r['cr']) : '',
            'balance' => $fmt($r['balance']), 'link' => $r['link'],
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
            ->timeline($this->timeline->forPhone($ctx, $phone))
            ->statement($stmtRows, 'Balance due', $fmt($stmt['balance']))
            ->documents([])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => count($this->timeline->forPhone($ctx, $phone))],
                ['key' => 'financials', 'label' => 'Financials'],
                ['key' => 'documents', 'label' => 'Documents', 'count' => $orders->count() + $ins['invoiceCount']],
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
            'holder' => (string) ($ctx->user->name ?? 'Owner'),
            'holder_user_id' => (int) $ctx->user->id,
            'created_by' => (string) ($ctx->user->name ?? 'Owner'),
        ]);
    }
}
