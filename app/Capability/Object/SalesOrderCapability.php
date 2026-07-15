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
        // The order's native currency: its own sell_currency, else the engine's local currency (UGX on
        // a converted basis) — NOT the display base, so a converted UGX total isn't mislabelled as USD.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $ocur = (string) ($o->sell_currency ?: $ctx->nativeCurrency());
        $odate = $o->created_at ? Carbon::parse($o->created_at)->format('Y-m-d') : null;
        $cur = $rc->isOriginal() ? $ocur : $rc->consolidationCurrency();
        // Format any order amount by converting from its native currency to the report display currency.
        $fmt = fn ($v) => $rc->text((float) $v, $ocur, null, $odate);
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

        // --- CRUD actions ---
        // Order status change goes through a v3 command (the panel's update-status route is GET-only);
        // the model save still fires the OrderObserver, so customer status notifications are preserved.
        $statusChoices = ['New', 'Accepted', 'Preparing', 'Ready', 'Dispatched', 'Delivered', 'Cancelled'];
        $changeStatus = ['key' => 'change_status', 'label' => 'Change status', 'method' => 'POST', 'endpoint' => $ep . '/change_status', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['status' => 'enum:' . implode(',', $statusChoices)], 'prefill' => ['id' => (int) $o->id, 'status' => (string) $o->status]];
        $cancelOrder = ! $cancelled ? ['key' => 'cancel_order', 'label' => 'Cancel order', 'method' => 'POST', 'endpoint' => $ep . '/cancel_order', 'actor' => 'human', 'tone' => 'danger', 'confirm' => 'Cancel Order ' . $o->order_no . '? Stock is released and the order is closed.'] : null;
        // Record a payment against the order via the panel's proven /papi/record-payment endpoint.
        // The /papi/record-payment endpoint records the amount in the order's NATIVE currency (it adds
        // straight into amount_paid). To keep the user in the report currency (USD) they see everywhere,
        // the field is entered + prefilled in the DISPLAY currency and the shell multiplies it back to
        // the order's native currency on submit (submitConvert). So the user types 165 (USD) and the
        // endpoint correctly receives ~603,075 (UGX) — no one has to think in the local currency.
        $dispCur = $rc->consolidationCurrency();
        $dispBalance = $balance > 0 ? round((float) $rc->consolidate([['amount' => $balance, 'currency' => $ocur, 'date' => $odate]])['amount'], 2) : 0.0;
        $needConv = strtoupper($ocur) !== strtoupper($dispCur) && $dispBalance > 0;
        $factor = $needConv ? round($balance / $dispBalance, 6) : 1.0;   // native units per 1 display unit
        $recordPayment = ! $cancelled ? array_filter([
            'key' => 'record_payment', 'label' => 'Record payment', 'method' => 'POST', 'endpoint' => '/papi/record-payment', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['amount' => 'money', 'method' => 'enum:cash,mobile_money,bank,cheque', 'note' => 'text'], 'body' => ['order' => (int) $o->id],
            'labels' => ['amount' => 'Amount (' . $dispCur . ')'],
            'prefill' => ['amount' => $dispBalance > 0 ? $dispBalance : '', 'method' => 'cash'],
            'submitConvert' => $needConv ? ['field' => 'amount', 'factor' => $factor] : null,
        ], fn ($v) => $v !== null) : null;

        $quick = array_values(array_filter([
            (! $delivered && ! $cancelled) ? $markDelivered : null,
            ($delivered && ! $inv) ? $createInvoice : null,
            $openInvoice, $recordPayment, $changeStatus, $openCustomer,
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
            ->more(array_values(array_filter([$changeStatus, $recordPayment, $openCustomer, $openInvoice, $cancelOrder])))
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
            'change_status' => $this->changeStatus($ctx, $input),
            'cancel_order' => $this->cancelOrder($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Set the order status (validated). The model save fires the OrderObserver — same path the panel uses. */
    private function changeStatus(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));
        $status = trim((string) ($input['status'] ?? ''));
        $allowed = ['New', 'Accepted', 'Preparing', 'Ready', 'Dispatched', 'Delivered', 'Customer confirmed', 'Cancelled', 'Rejected'];
        if (! in_array($status, $allowed, true)) {
            return ['ok' => false, 'error' => 'bad_status', 'message' => 'Unknown order status.'];
        }
        if ($status === $o->status) {
            return ['ok' => true, 'message' => 'Order is already ' . $status . '.'];
        }
        $o->status = $status;
        $o->save();

        return ['ok' => true, 'message' => 'Order ' . $o->order_no . ' set to ' . $status . '.'];
    }

    /** Cancel an order (reversible status flip). Blocked once it carries a live invoice — void that first. */
    private function cancelOrder(CapabilityContext $ctx, array $input): array
    {
        $o = Order::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($input['id'] ?? 0));
        if (in_array(strtolower((string) $o->status), ['cancelled', 'canceled', 'rejected'], true)) {
            return ['ok' => true, 'message' => 'Order already cancelled.'];
        }
        $hasInvoice = Invoice::where('tenant_id', $ctx->tenantId())->where('order_id', $o->id)->where('status', '!=', 'void')->exists();
        if ($hasInvoice) {
            return ['ok' => false, 'error' => 'invoiced', 'message' => 'This order is invoiced — void the invoice before cancelling.'];
        }
        $o->status = 'Cancelled';
        $o->save();

        return ['ok' => true, 'message' => 'Order ' . $o->order_no . ' cancelled.'];
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
