<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Money\Fx;
use App\Services\Money\PaymentService;
use Illuminate\Support\Carbon;

/**
 * Invoice 360 — the billing record shell. Read side projects the invoice into a pipeline
 * (Order → Delivered → Invoiced → Paid), payment allocation and the customer/order links.
 * Write side records payments through the FROZEN PaymentService (auto-allocated to this invoice_no).
 */
final class InvoiceCapability implements ObjectCapability
{
    public function key(): string { return 'invoice'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'invoice'])->meta(['capability' => 'invoice']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $i = Invoice::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        // Currency-aware. The invoice's own `currency` tag is UNRELIABLE — the issuer stamps it with the
        // tenant base (e.g. USD) even though the amounts are the local operating currency (UGX). Derive
        // the TRUE transaction currency from the linked order, so a UGX balance never prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $cur = \App\Services\Money\SalesBaseCurrency::txnCurrency($i, $ctx->tenant);
        $idate = $i->issued_at ? Carbon::parse($i->issued_at)->format('Y-m-d') : null;
        $fmt = fn ($v) => $rc->text((float) $v, $cur, null, $idate);
        $ep = '/api/v1/workspace/invoice/' . $i->id;

        $total = (float) $i->total;
        $paid = (float) $i->amount_paid;
        $balance = (float) $i->balance;
        $due = $i->due_at ? Carbon::parse($i->due_at) : null;
        $overdue = $due && $due->isPast() && $balance > 0.01;
        $daysOver = $overdue ? (int) $due->diffInDays(Carbon::now()) : 0;

        $order = $i->order_id ? Order::where('tenant_id', $ctx->tenantId())->find($i->order_id) : null;
        $delivered = $order && $order->delivered_at !== null;
        $iPhone = preg_replace('/[^0-9]/', '', (string) $i->customer_phone);
        $profile = $iPhone === '' ? null : CustomerProfile::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$iPhone])->first();

        $pays = CustomerPayment::where('tenant_id', $ctx->tenantId())
            ->where('invoice_no', $i->invoice_no)->orderByDesc('created_at')->limit(50)->get();

        // --- actions ---
        // People pick-list so a payment is credited to whoever actually received it (their cashbook).
        $meName = trim((string) ($ctx->user->name ?? ''));
        $people = \App\Models\User::where('tenant_id', $ctx->tenantId())->orderBy('name')->pluck('name')
            ->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        if ($meName !== '') { $people = array_values(array_unique(array_merge([$meName], $people))); }
        $comboPeople = 'combo:' . (count($people) ? implode('|', $people) : ($meName ?: 'Me'));
        $recordPay = [
            'key' => 'record_payment', 'label' => 'Record payment', 'method' => 'POST', 'endpoint' => $ep . '/record_payment',
            'actor' => 'human', 'tone' => 'primary',
            'schema' => ['amount' => 'money', 'received_by' => $comboPeople, 'method' => 'enum:cash,mobile_money,bank,cheque', 'reference' => 'text'],
            'labels' => ['received_by' => 'Received by (whose cash)'],
            'prefill' => ['id' => (int) $i->id, 'amount' => round($balance, 2), 'received_by' => $meName, 'method' => 'cash'],
        ];
        $isVoid = strtolower((string) $i->status) === 'void';
        // Real WhatsApp send via the panel's proven endpoint (replaces the old no-op "Send reminder" stub).
        $sendInvoice = ! $isVoid ? ['key' => 'send_invoice', 'label' => 'Send on WhatsApp', 'method' => 'POST', 'endpoint' => '/papi/invoice-send', 'actor' => 'human', 'tone' => 'default', 'body' => ['id' => (int) $i->id], 'confirm' => 'Send invoice ' . $i->invoice_no . ' to ' . ($i->customer_name ?: 'the customer') . ' on WhatsApp?'] : null;
        // Void (kept for records) via the panel's owner/manager-gated endpoint.
        $voidInvoice = ! $isVoid ? ['key' => 'void_invoice', 'label' => 'Void invoice', 'method' => 'POST', 'endpoint' => '/papi/invoice-void', 'actor' => 'human', 'tone' => 'danger', 'body' => ['id' => (int) $i->id], 'confirm' => 'Void invoice ' . $i->invoice_no . '? It stays on record but is marked void and excluded from balances.'] : null;
        // Download via the panel's proven streaming route (same session, correct headers).
        $downloadPdf = ['key' => 'invoice_pdf', 'label' => 'Download PDF', 'href' => '/papi/invoice-pdf?id=' . $i->id, 'actor' => 'human', 'tone' => 'default'];
        $openCustomer = $profile ? ['key' => 'open_customer', 'label' => 'Open customer', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customer/' . $profile->id, 'actor' => 'human', 'tone' => 'default'] : null;
        $openOrder = $order ? ['key' => 'open_order', 'label' => 'Open order', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesOrder/' . $order->id, 'actor' => 'human', 'tone' => 'default'] : null;

        $primary = (! $isVoid && $balance > 0.01) ? $recordPay : $downloadPdf;
        $quick = array_values(array_filter([(! $isVoid && $balance > 0.01) ? $recordPay : null, $downloadPdf, $sendInvoice, $openOrder, $openCustomer]));

        // --- pipeline ---
        $st = fn ($done, $now) => $done ? 'done' : ($now ? 'now' : 'wait');
        $pipeline = [
            ['label' => 'Order', 'value' => $order ? (string) $order->order_no : '—', 'state' => 'done', 'icon' => 'cart'],
            ['label' => 'Delivered', 'value' => $delivered ? Carbon::parse($order->delivered_at)->format('d M Y') : ($order ? 'Pending' : '—'), 'state' => $st($delivered || ! $order, $order && ! $delivered), 'icon' => 'truck'],
            ['label' => 'Invoiced', 'value' => (string) $i->invoice_no, 'state' => 'done', 'icon' => 'invoice'],
            ['label' => 'Paid', 'value' => $balance <= 0.01 ? 'Paid' : ($paid > 0 ? $fmt($balance) . ' left' : 'Unpaid'), 'state' => $st($balance <= 0.01, true), 'icon' => 'card'],
        ];

        // --- overview cards ---
        $relItems = [];
        $relItems[] = ['icon' => 'user', 'color' => '#16a34a', 'title' => (string) ($i->customer_name ?: 'Customer'),
            'sub' => $profile && ($profile->city || $profile->country) ? trim(implode(', ', array_filter([(string) $profile->city, (string) $profile->country]))) : (string) $i->customer_phone,
            'right' => '', 'link' => $profile ? '/api/v1/workspace/customer/' . $profile->id : null];
        if ($order) {
            $relItems[] = ['icon' => 'cart', 'color' => '#4f46e5', 'title' => 'Order ' . $order->order_no, 'sub' => $fmt($order->total) . ' · ' . ucfirst((string) $order->status), 'right' => '', 'link' => '/api/v1/workspace/salesOrder/' . $order->id];
        }

        $lineRows = [];
        if ($order) {
            foreach ($order->items()->get() as $it) {
                $qty = (float) $it->qty; $price = (float) $it->price;
                $lineRows[] = ['cells' => [(string) $it->name, rtrim(rtrim(number_format($qty, 2), '0'), '.'), $fmt($price), $fmt($qty * $price)],
                    'link' => $it->product_id ? '/api/v1/workspace/product/' . $it->product_id : null];
            }
        }

        $overviewCards = [
            ['kind' => 'links', 'title' => 'Related', 'items' => $relItems],
            ['kind' => 'table', 'title' => 'Line items', 'sub' => $order ? ('from order ' . $order->order_no) : '',
             'columns' => [['label' => 'Product'], ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit price', 'align' => 'right'], ['label' => 'Amount', 'align' => 'right']],
             'rows' => $lineRows, 'empty' => $order ? 'No line items on the source order' : 'No linked order'],
        ];

        // --- payments tab (allocation table) ---
        $payRows = $pays->map(fn ($p) => [
            'cells' => [
                (string) ($p->receipt_no ?: '—'),
                (string) ($p->holder ?: $p->created_by ?: '—'),   // who received the cash
                ucfirst((string) ($p->method ?: 'cash')),
                (string) ($p->reference ?: '—'),
                ['value' => $rc->text((float) $p->amount, (string) ($p->currency ?: $native), (float) ($p->usd_amount ?? 0) ?: null, $p->created_at ? Carbon::parse($p->created_at)->format('Y-m-d') : null), 'align' => 'right'],
                $p->created_at ? Carbon::parse($p->created_at)->format('d M Y') : '—',
            ], 'link' => null,
        ])->all();
        $financialsCards = [[
            'kind' => 'table', 'title' => 'Payment allocation', 'sub' => $pays->count() . ' payment' . ($pays->count() === 1 ? '' : 's'),
            'columns' => [['label' => 'Receipt'], ['label' => 'Received by'], ['label' => 'Method'], ['label' => 'Reference'], ['label' => 'Amount', 'align' => 'right'], ['label' => 'Date']],
            'rows' => $payRows, 'empty' => 'No payments recorded yet',
        ]];

        // --- timeline ---
        $tl = [];
        $tl[] = ['title' => 'Invoice ' . $i->invoice_no . ' issued', 'at' => $i->issued_at ? Carbon::parse($i->issued_at)->format('d M Y') : Carbon::parse($i->created_at)->format('d M Y'), 'actor' => $order ? ('from order ' . $order->order_no) : ''];
        foreach ($pays as $p) {
            $tl[] = ['title' => 'Payment received · ' . $rc->text((float) $p->amount, (string) ($p->currency ?: $native), (float) ($p->usd_amount ?? 0) ?: null, $p->created_at ? Carbon::parse($p->created_at)->format('Y-m-d') : null), 'at' => $p->created_at ? Carbon::parse($p->created_at)->format('d M Y') : '', 'actor' => (string) ($p->method ?: '')];
        }
        if ($overdue) {
            $tl[] = ['title' => 'Became overdue', 'at' => $due->format('d M Y'), 'actor' => $daysOver . ' days past due'];
        }

        // --- documents ---
        $docs = [];
        if ($i->pdf_path) { $docs[] = ['type' => 'invoice', 'title' => 'Invoice ' . $i->invoice_no . ' (PDF)', 'url' => $i->pdf_path]; }

        $statusLabel = $overdue ? 'overdue' : strtolower((string) ($i->status ?: ($balance <= 0.01 ? 'paid' : 'sent')));

        $rail = [
            ['key' => 'balance', 'label' => 'Balance due', 'value' => $fmt($balance), 'tone' => $balance > 0.01 ? 'danger' : 'ok'],
            ['key' => 'total', 'label' => 'Invoice total', 'value' => $fmt($total)],
            ['key' => 'paid', 'label' => 'Paid', 'value' => $fmt($paid), 'tone' => $paid > 0 ? 'ok' : ''],
            ['key' => 'due', 'label' => 'Due date', 'value' => $due ? $due->format('d M Y') : '—', 'tone' => $overdue ? 'danger' : ''],
            ['key' => 'order', 'label' => 'From order', 'value' => $order ? (string) $order->order_no : '—'],
        ];

        $nbaText = $balance > 0.01
            ? (($overdue ? 'Overdue by ' . $daysOver . ' days. ' : '') . $fmt($balance) . ' outstanding from ' . ($i->customer_name ?: 'the customer') . '. Record a payment or send the invoice on WhatsApp.')
            : 'Fully paid — nothing outstanding on this invoice.';

        return (new WorkspacePayload)
            ->object([
                'type' => 'invoice', 'id' => (string) $i->id, 'ref' => (string) $i->invoice_no,
                'title' => 'Invoice ' . $i->invoice_no,
                'subtitle' => trim('To ' . ($i->customer_name ?: 'customer') . ($i->issued_at ? ' · issued ' . Carbon::parse($i->issued_at)->format('d M Y') : '') . ($due ? ' · due ' . $due->format('d M Y') : '')),
                'status' => $statusLabel,
            ])
            ->headline($fmt($balance), 'of ' . $fmt($total) . ' due', $balance > 0.01 ? ($overdue ? 'danger' : 'warn') : 'ok')
            ->brief($this->invoiceBrief($i, $balance, $paid, $overdue, $daysOver, $fmt))
            ->financials($rail)
            ->nba($nbaText, $primary)
            ->quickActions($quick)
            ->pipeline($pipeline)
            ->stats([
                ['label' => 'Invoice total', 'value' => $fmt($total), 'icon' => 'invoice'],
                ['label' => 'Paid', 'value' => $fmt($paid), 'icon' => 'check', 'color' => '#16a34a', 'wash' => 'var(--brand-wash)'],
                ['label' => 'Balance', 'value' => $fmt($balance), 'icon' => 'card', 'tone' => $balance > 0.01 ? 'danger' : 'ok', 'color' => $balance > 0.01 ? '#dc2626' : '#16a34a', 'wash' => $balance > 0.01 ? 'var(--danger-wash)' : 'var(--brand-wash)'],
            ])
            ->overviewCards($overviewCards)
            ->financialsCards($financialsCards)
            ->timeline(array_reverse($tl))
            ->documents($docs)
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => count($tl)],
                ['key' => 'financials', 'label' => 'Payments', 'count' => $pays->count()],
                ['key' => 'documents', 'label' => 'Documents', 'count' => count($docs)],
            ])
            ->actions(array_values(array_filter([$primary])))
            ->more(array_values(array_filter([$primary === $downloadPdf ? null : $downloadPdf, $sendInvoice, $openOrder, $openCustomer, $voidInvoice])))
            ->meta(['capability' => 'invoice', 'ttl' => 30, 'layout' => 'record', 'listKey' => 'invoices', 'permissions' => ['menu:finance']]);
    }

    private function invoiceBrief(Invoice $i, float $balance, float $paid, bool $overdue, int $daysOver, callable $fmt): Brief
    {
        if ($balance <= 0.01) {
            return new Brief('low', 'Invoice fully paid.', 'Settled in full — nothing outstanding on this one.', ['tone' => 'ok', 'label' => 'Paid']);
        }
        if ($overdue) {
            return new Brief('high', 'Invoice ' . $i->invoice_no . ' is overdue.', $fmt($balance) . ' is ' . $daysOver . ' days past due. Follow up to collect before it ages further.', ['tone' => 'danger', 'label' => $daysOver . 'd overdue']);
        }
        if ($paid > 0) {
            return new Brief('med', 'Part-paid.', $fmt($paid) . ' received; ' . $fmt($balance) . ' still due. Follow up to close the balance.', ['tone' => 'warn', 'label' => 'Part-paid']);
        }

        return new Brief('med', 'Awaiting payment.', $fmt($balance) . ' due from ' . ($i->customer_name ?: 'the customer') . '. Record the payment here when it arrives.', ['tone' => 'warn', 'label' => 'Unpaid']);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'record_payment' => $this->recordPayment($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Record a payment against this invoice via the FROZEN PaymentService (auto-allocates by invoice_no). */
    private function recordPayment(CapabilityContext $ctx, array $in): array
    {
        $i = Invoice::where('tenant_id', $ctx->tenantId())->find((int) ($in['id'] ?? 0));
        if (! $i) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        // The invoice's own currency tag is unreliable; the amount is recorded in the invoice's TRUE
        // transaction currency (derived from the linked order) unless the caller passes one explicitly.
        $cur = strtoupper((string) ($in['currency'] ?? '')) ?: \App\Services\Money\SalesBaseCurrency::txnCurrency($i, $ctx->tenant);
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'bad_amount', 'message' => 'Enter an amount greater than zero.'];
        }
        // WHO received the cash — defaults to the operator; lands in that person's cashbook.
        $actorName = (string) ($ctx->user->name ?? 'Owner');
        $recv = trim((string) ($in['received_by'] ?? ''));
        $holder = $recv !== '' ? $recv : $actorName;
        $holderId = (int) $ctx->user->id;
        if ($recv !== '' && strcasecmp($recv, $actorName) !== 0) {
            $hu = \App\Models\User::where('tenant_id', $ctx->tenantId())->whereRaw('lower(name) = ?', [mb_strtolower($recv)])->first();
            $holderId = $hu ? (int) $hu->id : 0;
        }
        $fx = Fx::forTenant($ctx->tenant);

        $res = app(PaymentService::class)->record($ctx->tenant, [
            'customer' => (string) $i->customer_name,
            'phone' => (string) $i->customer_phone,
            'invoice_no' => (string) $i->invoice_no,
            'kind' => 'settlement',
            'currency' => $cur,
            'amount' => $amount,
            'rate' => $fx->ratePerUsd($cur),
            'usd' => $fx->toUsd($amount, $cur),
            'method' => (string) ($in['method'] ?? 'cash'),
            'reference' => $in['reference'] ?? null,
            'holder' => $holder,
            'holder_user_id' => $holderId,
            'created_by' => $actorName,
        ]);

        if (is_array($res) && array_key_exists('ok', $res)) {
            return $res;
        }

        return ['ok' => true, 'message' => 'Payment of ' . Money::fmt($amount, $cur) . ' recorded on ' . $i->invoice_no . '.'];
    }
}
