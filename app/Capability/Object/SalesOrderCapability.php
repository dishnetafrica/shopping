<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Carbon;

/**
 * Sales Order 360 — the record shell for the sales loop. Read side projects the live order into a
 * pipeline (Order → Confirmed → Delivered → Invoiced → Paid), line items, profitability and the
 * customer/invoice links. Write side stays on the FROZEN path: flipping status to Delivered lets the
 * OrderObserver stamp delivered_at + auto-issue the invoice; explicit invoicing calls InvoiceService.
 */
final class SalesOrderCapability implements ObjectCapability
{
    public function key(): string { return 'salesOrder'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'salesOrder'])->meta(['capability' => 'salesOrder']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $inv = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->where('status', '!=', 'void')->first();
        $cur = (string) ($o->sell_currency ?: $ctx->baseCurrency());
        $fmt = fn ($v) => Money::fmt((float) $v, $cur);
        $ep = '/api/v1/workspace/salesOrder/' . $o->id;

        $cancelled = in_array(strtolower((string) $o->status), ['cancelled', 'canceled', 'rejected'], true);
        $delivered = $o->delivered_at !== null;
        $accepted = $o->accepted_at !== null || $delivered;
        $total = (float) $o->total;
        $paid = (float) $o->amount_paid;
        $balance = max(0.0, $total - $paid);
        $invPaid = $inv && (float) $inv->balance <= 0.01;

        // Line items (real, from OrderItem) + best-effort COGS from product cost.
        $items = $o->items()->get();
        $lineRows = []; $cogs = 0.0; $costKnown = $items->count() > 0;
        foreach ($items as $it) {
            $qty = (float) $it->qty; $price = (float) $it->price; $amount = $qty * $price;
            $c = $it->product_id ? (float) (Product::where('tenant_id', $ctx->tenantId())->whereKey($it->product_id)->value('cost_price') ?? 0) : 0.0;
            if ($c > 0) { $cogs += $c * $qty; } else { $costKnown = false; }
            $lineRows[] = ['cells' => [(string) $it->name, rtrim(rtrim(number_format($qty, 2), '0'), '.'), $fmt($price), $fmt($amount)],
                'link' => $it->product_id ? '/api/v1/workspace/product/' . $it->product_id : null];
        }
        $grossPct = ($costKnown && $total > 0) ? round(($total - $cogs) / $total * 100, 1) : null;

        // Customer link (profile lookup by phone, if saved). Blank phone → no match (never a stray one).
        $oPhone = preg_replace('/[^0-9]/', '', (string) $o->customer_phone);
        $profile = $oPhone === '' ? null : CustomerProfile::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$oPhone])->first();

        // --- pipeline ---
        $st = fn ($done, $now) => $done ? 'done' : ($now ? 'now' : 'wait');
        $pipeline = [
            ['label' => 'Order', 'value' => (string) $o->order_no, 'state' => 'done', 'icon' => 'cart'],
            ['label' => 'Confirmed', 'value' => $accepted ? 'Yes' : ($cancelled ? 'Cancelled' : 'Pending'), 'state' => $st($accepted, ! $accepted && ! $cancelled), 'icon' => 'check'],
            ['label' => 'Delivered', 'value' => $delivered ? Carbon::parse($o->delivered_at)->format('d M Y') : 'Pending', 'state' => $st($delivered, $accepted && ! $cancelled), 'icon' => 'truck'],
            ['label' => 'Invoiced', 'value' => $inv ? (string) $inv->invoice_no : 'Not raised', 'state' => $st((bool) $inv, $delivered), 'icon' => 'invoice'],
            ['label' => 'Paid', 'value' => $invPaid ? 'Paid' : ($inv ? $fmt($inv->balance) . ' due' : '—'), 'state' => $st($invPaid, (bool) $inv), 'icon' => 'card'],
        ];

        // --- actions ---
        $markDelivered = ['key' => 'mark_delivered', 'label' => 'Mark delivered', 'method' => 'POST', 'endpoint' => $ep . '/mark_delivered', 'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Mark Order ' . $o->order_no . ' as delivered? An invoice is issued automatically.'];
        $createInvoice = ['key' => 'create_invoice', 'label' => 'Create invoice', 'method' => 'POST', 'endpoint' => $ep . '/create_invoice', 'actor' => 'human', 'tone' => 'primary'];
        $openInvoice = $inv ? ['key' => 'open_invoice', 'label' => 'Open invoice', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/invoice/' . $inv->id, 'actor' => 'human', 'tone' => 'primary'] : null;
        $openCustomer = $profile ? ['key' => 'open_customer', 'label' => 'Open customer', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customer/' . $profile->id, 'actor' => 'human', 'tone' => 'default'] : null;

        if (! $delivered && ! $cancelled) {
            $primary = $markDelivered;
            $nbaText = 'Mark this order delivered when the goods arrive — an invoice is issued automatically to ' . ($o->customer_name ?: 'the customer') . '.';
            $nbaAction = $markDelivered;
        } elseif ($delivered && ! $inv) {
            $primary = $createInvoice;
            $nbaText = 'Delivered but not yet billed. Create the invoice to bill ' . ($o->customer_name ?: 'the customer') . ' ' . $fmt($total) . '.';
            $nbaAction = $createInvoice;
        } elseif ($inv && ! $invPaid) {
            $primary = $openInvoice;
            $nbaText = 'Invoiced — ' . $fmt($inv->balance) . ' still due. Open the invoice to record a payment.';
            $nbaAction = $openInvoice;
        } else {
            $primary = $openInvoice ?: ($openCustomer ?: null);
            $nbaText = $cancelled ? 'This order was cancelled — no further action needed.' : 'Complete — delivered, invoiced and paid in full.';
            $nbaAction = $primary;
        }

        $quick = array_values(array_filter([
            (! $delivered && ! $cancelled) ? $markDelivered : null,
            ($delivered && ! $inv) ? $createInvoice : null,
            $openInvoice, $openCustomer,
        ]));

        // --- overview cards ---
        $relItems = [];
        $relItems[] = ['icon' => 'user', 'color' => '#16a34a', 'title' => (string) ($o->customer_name ?: 'Customer'),
            'sub' => trim(($profile && ($profile->city || $profile->country)) ? implode(', ', array_filter([(string) $profile->city, (string) $profile->country])) : (string) $o->customer_phone) ?: '—',
            'right' => '', 'link' => $profile ? '/api/v1/workspace/customer/' . $profile->id : null];
        $relItems[] = $inv
            ? ['icon' => 'invoice', 'color' => '#d97706', 'title' => 'Invoice ' . $inv->invoice_no, 'sub' => 'Balance ' . $fmt($inv->balance), 'right' => ucfirst((string) $inv->status), 'rightTone' => ((float) $inv->balance > 0.01 ? 'danger' : 'ok'), 'link' => '/api/v1/workspace/invoice/' . $inv->id]
            : ['icon' => 'invoice', 'color' => '#98a1ad', 'title' => 'Not invoiced yet', 'sub' => $delivered ? 'Ready to invoice' : 'Invoice on delivery', 'right' => '', 'link' => null];

        $overviewCards = [
            ['kind' => 'links', 'title' => 'Related', 'items' => $relItems],
            ['kind' => 'table', 'title' => 'Line items', 'sub' => count($lineRows) . ' products',
             'columns' => [['label' => 'Product'], ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit price', 'align' => 'right'], ['label' => 'Amount', 'align' => 'right']],
             'rows' => $lineRows, 'empty' => 'No line items recorded'],
            ['kind' => 'dl', 'title' => 'Totals', 'rows' => array_values(array_filter([
                ['Order total', $fmt($total)],
                $paid > 0 ? ['Paid', $fmt($paid)] : null,
                $balance > 0 ? ['Balance', $fmt($balance)] : null,
            ]))],
        ];

        // --- profitability (Financials tab) ---
        $financialsCards = [[
            'kind' => 'dl', 'title' => 'Profitability', 'rows' => [
                ['Revenue', $fmt($total)],
                ['Cost of goods', $costKnown ? $fmt($cogs) : 'Not recorded'],
                ['Gross profit', $grossPct !== null ? $fmt($total - $cogs) . ' (' . $grossPct . '%)' : '—'],
                ['Invoiced', $inv ? $fmt($inv->total) : 'Not yet'],
                ['Collected', $inv ? $fmt($inv->amount_paid) : '—'],
            ],
        ]];

        // --- timeline (real timestamps) ---
        $tl = [];
        if ($o->created_at) { $tl[] = ['title' => 'Order created', 'at' => Carbon::parse($o->created_at)->format('d M Y'), 'actor' => (string) ($o->created_by ?? 'Sales')]; }
        if ($o->accepted_at) { $tl[] = ['title' => 'Order confirmed', 'at' => Carbon::parse($o->accepted_at)->format('d M Y')]; }
        if ($o->ready_at) { $tl[] = ['title' => 'Picked & packed', 'at' => Carbon::parse($o->ready_at)->format('d M Y'), 'actor' => 'Warehouse']; }
        if ($o->delivered_at) { $tl[] = ['title' => 'Delivered', 'at' => Carbon::parse($o->delivered_at)->format('d M Y'), 'actor' => 'Dispatch']; }
        if ($inv) { $tl[] = ['title' => 'Invoice ' . $inv->invoice_no . ' created', 'at' => $inv->issued_at ? Carbon::parse($inv->issued_at)->format('d M Y') : Carbon::parse($inv->created_at)->format('d M Y')]; }
        $tl = array_reverse($tl);

        $rail = [
            ['key' => 'total', 'label' => 'Order total', 'value' => $fmt($total)],
            ['key' => 'paid', 'label' => 'Paid', 'value' => $fmt($paid), 'tone' => $paid > 0 ? 'ok' : ''],
            ['key' => 'balance', 'label' => 'Balance', 'value' => $balance > 0 ? $fmt($balance) : '—', 'tone' => $balance > 0 ? 'danger' : ''],
            ['key' => 'cogs', 'label' => 'Cost of goods', 'value' => $costKnown ? $fmt($cogs) : '—'],
            ['key' => 'margin', 'label' => 'Gross margin', 'value' => $grossPct !== null ? $grossPct . '%' : '—', 'tone' => ($grossPct !== null && $grossPct < 12) ? 'warn' : ''],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'salesOrder', 'id' => (string) $o->id, 'ref' => (string) $o->order_no,
                'title' => 'Order ' . $o->order_no,
                'subtitle' => trim(implode(' · ', array_filter([(string) $o->customer_name, $o->created_at ? Carbon::parse($o->created_at)->format('d M Y') : null]))),
                'status' => $cancelled ? 'cancelled' : ($delivered ? 'delivered' : strtolower((string) ($o->status ?: 'pending'))),
            ])
            ->headline($fmt($total), $delivered ? 'delivered' : ($cancelled ? 'cancelled' : 'in progress'), $cancelled ? 'danger' : ($delivered ? 'ok' : 'default'))
            ->brief($this->orderBrief($o, $inv, $delivered, $cancelled, $balance, $fmt))
            ->financials($rail)
            ->nba($nbaText, $nbaAction ?: ['key' => 'noop', 'label' => 'OK', 'method' => 'GET', 'endpoint' => $ep, 'actor' => 'human', 'tone' => 'default'])
            ->quickActions($quick)
            ->pipeline($pipeline)
            ->overviewCards($overviewCards)
            ->financialsCards($financialsCards)
            ->timeline($tl)
            ->documents([])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => count($tl)],
                ['key' => 'financials', 'label' => 'Financials'],
                ['key' => 'documents', 'label' => 'Documents'],
            ])
            ->actions(array_values(array_filter([$primary])))
            ->more(array_values(array_filter([$openCustomer, $openInvoice])))
            ->meta(['capability' => 'salesOrder', 'ttl' => 30, 'layout' => 'record', 'listKey' => 'salesOrders', 'permissions' => ['menu:orders']]);
    }

    private function orderBrief(Order $o, ?Invoice $inv, bool $delivered, bool $cancelled, float $balance, callable $fmt): \App\Capability\Dto\Brief
    {
        if ($cancelled) {
            return new \App\Capability\Dto\Brief('low', 'This order was cancelled.', 'No further action needed — duplicate it if the customer re-orders.', ['tone' => 'default', 'label' => 'Cancelled']);
        }
        if (! $delivered) {
            return new \App\Capability\Dto\Brief('med', 'Order ' . $o->order_no . ' is in progress.', 'Stock is committed to this order. Mark it delivered on arrival to bill the customer automatically.', ['tone' => 'info', 'label' => 'In progress']);
        }
        if (! $inv) {
            return new \App\Capability\Dto\Brief('high', 'Delivered but not invoiced.', 'Revenue is recognised only when invoiced — this is money earned but not yet billed. Create the invoice now.', ['tone' => 'danger', 'label' => 'Not invoiced']);
        }
        if ($balance > 0.01 || (float) $inv->balance > 0.01) {
            return new \App\Capability\Dto\Brief('med', 'Delivered and invoiced.', 'Awaiting payment of ' . $fmt($inv->balance) . ' from ' . ($o->customer_name ?: 'the customer') . '.', ['tone' => 'warn', 'label' => 'Awaiting payment']);
        }

        return new \App\Capability\Dto\Brief('low', 'Order complete.', 'Delivered, invoiced and paid in full — this order is closed.', ['tone' => 'ok', 'label' => 'Complete']);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'mark_delivered' => $this->markDelivered($ctx, $input),
            'create_invoice' => $this->createInvoice($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function markDelivered(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));
        if (in_array(strtolower((string) $o->status), ['cancelled', 'canceled', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'closed', 'message' => 'This order is cancelled and cannot be delivered.'];
        }
        if ($o->delivered_at !== null) {
            return ['ok' => true, 'message' => 'Order already delivered.'];
        }
        $o->status = 'Delivered';
        $o->save();

        return ['ok' => true, 'message' => 'Order ' . $o->order_no . ' marked delivered — invoice issued.'];
    }

    private function createInvoice(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));
        if ($o->delivered_at === null) {
            return ['ok' => false, 'error' => 'not_delivered', 'message' => 'Only delivered orders can be invoiced.'];
        }
        $existing = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->where('status', '!=', 'void')->first();
        if ($existing) {
            return ['ok' => true, 'invoice_no' => (string) $existing->invoice_no, 'message' => 'Invoice already exists.', 'reused' => true];
        }
        $inv = app(InvoiceService::class)->issueForOrder($ctx->tenant, $o, 'v3');

        return ['ok' => true, 'invoice_no' => (string) $inv->invoice_no, 'invoice_id' => (int) $inv->id, 'message' => 'Invoice ' . $inv->invoice_no . ' created.'];
    }
}
