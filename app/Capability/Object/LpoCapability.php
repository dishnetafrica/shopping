<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Purchasing\LpoPdfService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Carbon;

/**
 * Local Purchase Order (LPO) record — one ordering document to one supplier. Add lines (ordered qty +
 * expected unit cost), send it to the supplier (optionally as a WhatsApp PDF), then receive goods
 * line-by-line as they arrive (full or partial). Received quantities post the delta into the real
 * stock ledger (the frozen StockLedger writer — never a second writer). The document holds no money;
 * the supplier bill/spend still posts through the existing order_costs path on the supplier screen.
 */
final class LpoCapability implements ObjectCapability
{
    public function key(): string { return 'lpo'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'lpo'])->meta(['capability' => 'lpo']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $po = PurchaseOrder::where('tenant_id', $t)->findOrFail((int) $id);
        $lines = PurchaseOrderLine::where('tenant_id', $t)->where('purchase_order_id', $po->id)->orderBy('name')->get();

        $canEdit = $ctx->can('menu:purchases');
        $status = (string) $po->status;
        $isOpen = ! in_array($status, ['cancelled', 'closed'], true);
        $cur = strtoupper((string) ($po->currency ?: $ctx->nativeCurrency()));
        $endpoint = '/api/v1/workspace/lpo/' . $po->id;

        // product pick-list so "Add item" selects from the real catalogue (pipe-separated combo).
        $productNames = \App\Models\Product::where('tenant_id', $t)->whereNull('archived_at')
            ->orderBy('name')->limit(1000)->pluck('name')
            ->map(fn ($n) => str_replace('|', ' ', trim((string) $n)))->filter()->unique()->values()->all();
        $productCombo = 'combo:' . (count($productNames) ? implode('|', $productNames) : 'Item');

        $totOrdered = 0.0; $totRecv = 0.0; $value = 0.0;
        $pending = 0; $partial = 0; $received = 0;
        $rows = []; $bulkSchema = []; $bulkLabels = []; $prefRecv = [];

        foreach ($lines as $l) {
            $ord = (float) $l->ordered_qty;
            $recv = (float) $l->received_qty;
            $unit = (float) $l->unit_cost;
            $st = (string) $l->line_status;
            $short = max(0.0, $ord - $recv);
            $totOrdered += $ord; $totRecv += min($ord, $recv); $value += $ord * $unit;
            if ($st === 'pending') { $pending++; }
            elseif ($st === 'partial') { $partial++; }
            elseif ($st === 'received') { $received++; }

            // one bulk-receive field per line (single-screen fast entry). Default a still-empty line to
            // its ordered qty (one click = "everything arrived as ordered"); keep an already-partial
            // figure so the owner only adjusts what actually changed.
            $bk = 'r_' . $l->id;
            $bulkSchema[$bk] = 'number';
            $bulkLabels[$bk] = mb_strlen((string) $l->name) > 44 ? (mb_substr((string) $l->name, 0, 44) . '…') : (string) $l->name;
            $prefRecv[$bk] = $recv > 0 ? $recv : $ord;

            $lineBtns = [];
            if ($canEdit && $isOpen) {
                $lineBtns[] = ['icon' => 'download', 'title' => 'Receive', 'action' => [
                    'key' => 'receive_line', 'label' => 'Receive — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/receive_line', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['received_qty' => 'number', 'remarks' => 'text'],
                    'labels' => ['received_qty' => 'Total received so far', 'remarks' => 'Note (optional)'],
                    'prefill' => ['received_qty' => $recv > 0 ? $recv : $ord, 'remarks' => (string) ($l->remarks ?: '')],
                    'body' => ['line_id' => $l->id],
                ]];
                $lineBtns[] = ['icon' => 'edit', 'title' => 'Edit item', 'action' => [
                    'key' => 'edit_line', 'label' => 'Edit — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/edit_line', 'actor' => 'human', 'tone' => 'default',
                    'schema' => ['ordered_qty' => 'number', 'unit_cost' => 'money', 'remarks' => 'text'],
                    'labels' => ['ordered_qty' => 'Ordered quantity', 'unit_cost' => 'Unit cost (' . $cur . ', optional)', 'remarks' => 'Note'],
                    'prefill' => ['ordered_qty' => $ord, 'unit_cost' => $unit > 0 ? round($unit, 2) : '', 'remarks' => (string) ($l->remarks ?: '')],
                    'body' => ['line_id' => $l->id],
                ]];
                $lineBtns[] = ['icon' => 'trash', 'title' => 'Remove', 'tone' => 'danger', 'post' => $endpoint . '/remove_line', 'body' => ['line_id' => $l->id], 'confirm' => 'Remove ' . $l->name . ' from this order?'];
            }

            $rows[] = ['cells' => [
                ['value' => (string) $l->name . ($l->product_id ? '' : ' (ad-hoc)')],
                ['value' => $this->qty($ord), 'align' => 'right'],
                ['value' => $unit > 0 ? number_format($unit, 2, '.', ',') : '—', 'align' => 'right'],
                ['value' => $recv > 0 ? $this->qty($recv) : '—', 'align' => 'right', 'tone' => ($recv >= $ord && $ord > 0) ? 'ok' : ($recv > 0 ? 'warn' : null)],
                ['value' => $short > 0 ? $this->qty($short) : '—', 'align' => 'right', 'tone' => $short > 0 ? 'danger' : null],
                ['value' => $this->lineLabel($st), 'badge' => $this->lineBadge($st)],
                ['buttons' => $lineBtns],
            ]];
        }

        $cols = [
            ['label' => 'Item'], ['label' => 'Ordered', 'align' => 'right'], ['label' => 'Unit cost', 'align' => 'right'],
            ['label' => 'Received', 'align' => 'right'], ['label' => 'Short', 'align' => 'right'], ['label' => 'Status'], ['label' => ''],
        ];

        $pct = $totOrdered > 0 ? (int) round(min(100, max(0, $totRecv / $totOrdered * 100))) : 0;
        $hasLines = $lines->count() > 0;
        $supPhone = preg_replace('/[^0-9]/', '', (string) optional($this->supplier($ctx, $po))->phone);

        // --- actions ---
        $addLine = ['key' => 'add_line', 'label' => 'Add item', 'method' => 'POST', 'endpoint' => $endpoint . '/add_line', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['name' => $productCombo, 'ordered_qty' => 'number', 'unit_cost' => 'money'],
            'labels' => ['name' => 'Item (pick from catalogue, or type a new one)', 'ordered_qty' => 'Ordered quantity', 'unit_cost' => 'Unit cost (' . $cur . ', optional)'],
            'prefill' => ['ordered_qty' => 1]];
        $sendSupplier = ['key' => 'send_supplier', 'label' => 'Send to supplier (WhatsApp)', 'method' => 'POST', 'endpoint' => $endpoint . '/send_supplier', 'actor' => 'human', 'tone' => 'primary',
            'confirm' => 'Send ' . ($po->po_no ?: 'this LPO') . ' as a WhatsApp PDF to ' . ($supPhone !== '' ? $po->supplier_name : 'the owner number') . '?'];
        $bulkReceive = ['key' => 'bulk_receive', 'label' => 'Receive goods', 'method' => 'POST', 'endpoint' => $endpoint . '/bulk_receive', 'actor' => 'human', 'tone' => 'primary',
            'schema' => $bulkSchema, 'labels' => $bulkLabels, 'prefill' => $prefRecv];
        $downloadPdf = ['key' => 'lpo_pdf', 'label' => 'View / download PDF', 'href' => '/api/v1/doc/lpo/' . $po->id, 'actor' => 'human', 'tone' => 'default'];
        $editOrder = ['key' => 'edit_order', 'label' => 'Edit details', 'method' => 'POST', 'endpoint' => $endpoint . '/edit_order', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['expected_date' => 'date', 'delivery_address' => 'text', 'terms' => 'text', 'notes' => 'text'],
            'labels' => ['expected_date' => 'Expected delivery (YYYY-MM-DD)', 'delivery_address' => 'Deliver to', 'terms' => 'Terms & conditions (shown on the PDF)', 'notes' => 'Notes'],
            'prefill' => ['expected_date' => $po->expected_date ? Carbon::parse($po->expected_date)->format('Y-m-d') : '', 'delivery_address' => (string) ($po->delivery_address ?: ''), 'terms' => (string) ($po->terms ?: ''), 'notes' => (string) ($po->notes ?: '')]];
        $markSent = ['key' => 'mark_sent', 'label' => 'Mark as sent', 'method' => 'POST', 'endpoint' => $endpoint . '/mark_sent', 'actor' => 'human', 'confirm' => 'Mark ' . ($po->po_no ?: 'this LPO') . ' as sent to the supplier?'];
        $markReceived = ['key' => 'mark_received', 'label' => 'Mark all received', 'method' => 'POST', 'endpoint' => $endpoint . '/mark_received', 'actor' => 'human', 'confirm' => 'Mark every item as fully received (as ordered)?'];
        $close = ['key' => 'close', 'label' => 'Close order', 'method' => 'POST', 'endpoint' => $endpoint . '/close', 'confirm' => 'Close this order? It stays for reference but is treated as done.'];
        $cancel = ['key' => 'cancel', 'label' => 'Cancel order', 'method' => 'POST', 'endpoint' => $endpoint . '/cancel', 'tone' => 'danger', 'confirm' => 'Cancel this order? Lines are kept for reference.'];

        $header = []; $more = [$downloadPdf];
        if ($canEdit && $isOpen) { $more[] = $editOrder; }
        if ($canEdit && $isOpen) {
            if ($status === 'draft') {
                if ($hasLines) { $header[] = $sendSupplier; }
                $header[] = $addLine;
            } else {
                if ($hasLines) { $header[] = $bulkReceive; }
                $header[] = $sendSupplier;
                $header[] = $addLine;
            }
            if ($status === 'draft') { $more[] = $markSent; }
            if ($hasLines) { $more[] = $markReceived; }
            $more[] = $close;
            $more[] = $cancel;
        }

        $subtitle = trim(implode(' · ', array_filter([
            (string) $po->supplier_name,
            $po->order_date ? Carbon::parse($po->order_date)->format('D d M Y') : '',
            $po->expected_date ? ('ETA ' . Carbon::parse($po->expected_date)->format('d M')) : '',
        ])));

        return (new WorkspacePayload)
            ->object([
                'type' => 'lpo', 'id' => (string) $po->id, 'ref' => (string) ($po->po_no ?: $po->id),
                'title' => (string) ($po->po_no ?: ('LPO ' . $po->id)),
                'subtitle' => $subtitle,
                'status' => $this->statusLabel($status),
            ])
            ->headline($pct . '% received', $lines->count() . ' items · ' . $this->qty($totOrdered) . ' ordered · ' . $cur . ' ' . number_format(round($value, 2), 2, '.', ','), $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : 'default'))
            ->financials([
                ['key' => 'supplier', 'label' => 'Supplier', 'value' => (string) ($po->supplier_name ?: '—')],
                ['key' => 'value', 'label' => 'Order value', 'value' => $cur . ' ' . number_format(round($value, 2), 2, '.', ','), 'foot' => 'expected (ordered × cost)'],
                ['key' => 'toreceive', 'label' => 'To receive', 'value' => $pending + $partial, 'tone' => ($pending + $partial) > 0 ? 'warn' : 'ok', 'foot' => 'items not fully in'],
                ['key' => 'received', 'label' => 'Received', 'value' => $received, 'tone' => 'ok', 'foot' => 'items fully delivered'],
                ['key' => 'eta', 'label' => 'Expected', 'value' => $po->expected_date ? Carbon::parse($po->expected_date)->format('d M Y') : '—'],
            ])
            ->tabSections(['items' => [[
                'kind' => 'table', 'title' => 'Order items', 'sub' => 'ordered vs received — receive each line as goods arrive',
                'searchable' => true, 'columns' => $cols, 'rows' => $rows,
                'empty' => 'No items yet — use “Add item”.',
            ]]])
            ->tabs([['key' => 'items', 'label' => 'Items', 'count' => $lines->count()]])
            ->actions($header)
            ->more($more)
            ->meta(['capability' => 'lpo', 'ttl' => 20, 'layout' => 'record', 'permissions' => ['menu:purchases']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if (! $ctx->can('menu:purchases')) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return match ($action) {
            'edit_order'    => $this->editOrder($ctx, $input),
            'add_line'      => $this->addLine($ctx, $input),
            'edit_line'     => $this->editLine($ctx, $input),
            'receive_line'  => $this->receiveLine($ctx, $input),
            'remove_line'   => $this->removeLine($ctx, $input),
            'bulk_receive'  => $this->bulkReceive($ctx, $input),
            'send_supplier' => $this->sendSupplier($ctx, $input),
            'mark_sent'     => $this->markSent($ctx, $input),
            'mark_received' => $this->markReceived($ctx, $input),
            'close'         => $this->setStatus($ctx, $input, 'closed'),
            'cancel'        => $this->setStatus($ctx, $input, 'cancelled'),
            default         => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function editOrder(CapabilityContext $ctx, array $in): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        if (array_key_exists('expected_date', $in)) {
            $raw = trim((string) $in['expected_date']);
            if ($raw === '') { $po->expected_date = null; }
            else { try { $po->expected_date = Carbon::parse($raw)->toDateString(); } catch (\Throwable $e) {} }
        }
        if (array_key_exists('delivery_address', $in)) { $po->delivery_address = trim((string) $in['delivery_address']) ?: null; }
        if (array_key_exists('terms', $in)) { $po->terms = trim((string) $in['terms']) ?: null; }
        if (array_key_exists('notes', $in)) { $po->notes = trim((string) $in['notes']) ?: null; }
        $po->save();

        return ['ok' => true, 'message' => 'Order details updated.'];
    }

    private function addLine(CapabilityContext $ctx, array $in): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'bad_input', 'message' => 'Pick an item or enter a name.'];
        }
        $t = $ctx->tenantId();

        // Resolve to a real catalogue product when the name matches — so a receipt feeds the stock ledger.
        $pid = null; $cost = null;
        $prod = \App\Models\Product::where('tenant_id', $t)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($prod) {
            $pid = (int) $prod->id;
            $name = (string) $prod->name;
            if ($prod->cost_price !== null) { $cost = (float) $prod->cost_price; }
        }
        $unit = array_key_exists('unit_cost', $in) && $in['unit_cost'] !== '' ? round((float) preg_replace('/[^0-9.]/', '', (string) $in['unit_cost']), 4) : $cost;

        PurchaseOrderLine::create([
            'tenant_id' => $t, 'purchase_order_id' => $po->id,
            'product_id' => $pid, 'name' => $name,
            'ordered_qty' => max(0.0, round((float) ($in['ordered_qty'] ?? 1), 3)),
            'unit_cost' => $unit,
            'received_qty' => 0, 'line_status' => 'pending',
        ]);

        return ['ok' => true, 'message' => 'Added ' . $name . ($pid ? ' (catalogue item).' : ' (ad-hoc item).')];
    }

    private function editLine(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) { return ['ok' => false, 'error' => 'not_found']; }
        if (array_key_exists('ordered_qty', $in) && $in['ordered_qty'] !== '') { $l->ordered_qty = max(0.0, round((float) $in['ordered_qty'], 3)); }
        if (array_key_exists('unit_cost', $in)) {
            $raw = trim((string) $in['unit_cost']);
            $l->unit_cost = $raw === '' ? null : round((float) preg_replace('/[^0-9.]/', '', $raw), 4);
        }
        if (array_key_exists('remarks', $in)) { $l->remarks = trim((string) $in['remarks']) ?: null; }
        $l->line_status = $l->deriveStatus();
        $l->save();
        $this->refreshStatus($ctx, $l->purchase_order_id);

        return ['ok' => true, 'message' => 'Updated ' . $l->name . '.'];
    }

    private function receiveLine(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) { return ['ok' => false, 'error' => 'not_found']; }
        if (array_key_exists('remarks', $in) && trim((string) $in['remarks']) !== '') { $l->remarks = trim((string) $in['remarks']); }
        $newTotal = array_key_exists('received_qty', $in) && $in['received_qty'] !== '' ? (float) $in['received_qty'] : (float) $l->received_qty;
        $delta = $this->receiveTo($ctx, $l, $newTotal);
        $this->refreshStatus($ctx, $l->purchase_order_id);
        $intoStock = $l->product_id && $delta > 0.0001;

        return ['ok' => true, 'message' => 'Received ' . $this->qty($l->received_qty) . ' of ' . $l->name . ($intoStock ? ' — added to stock.' : ($l->product_id ? '.' : ' (ad-hoc line — not linked to a product, so not added to stock).'))];
    }

    private function bulkReceive(CapabilityContext $ctx, array $in): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        $t = $ctx->tenantId();
        $n = 0;
        foreach ($in as $k => $v) {
            if (! is_string($k) || strncmp($k, 'r_', 2) !== 0) { continue; }
            if ($v === '' || $v === null) { continue; }
            $lid = (int) substr($k, 2);
            if ($lid <= 0) { continue; }
            $l = PurchaseOrderLine::where('tenant_id', $t)->where('purchase_order_id', $po->id)->find($lid);
            if (! $l) { continue; }
            $this->receiveTo($ctx, $l, max(0.0, round((float) $v, 3)));
            $n++;
        }
        $this->refreshStatus($ctx, $po->id);

        return ['ok' => true, 'message' => 'Received quantities updated for ' . $n . ' item(s).'];
    }

    private function markReceived(CapabilityContext $ctx, array $in): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        $lines = PurchaseOrderLine::where('tenant_id', $ctx->tenantId())->where('purchase_order_id', $po->id)->get();
        foreach ($lines as $l) {
            $this->receiveTo($ctx, $l, (float) $l->ordered_qty);
        }
        $this->refreshStatus($ctx, $po->id);

        return ['ok' => true, 'message' => 'All items marked received as ordered.'];
    }

    private function removeLine(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) { return ['ok' => false, 'error' => 'not_found']; }
        $name = (string) $l->name; $poId = (int) $l->purchase_order_id;
        $l->delete();
        $this->refreshStatus($ctx, $poId);

        return ['ok' => true, 'message' => 'Removed ' . $name . '.'];
    }

    private function markSent(CapabilityContext $ctx, array $in): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        if (in_array((string) $po->status, ['cancelled', 'closed'], true)) {
            return ['ok' => false, 'message' => 'This order is ' . $po->status . '.'];
        }
        if ((string) $po->status === 'draft') { $po->status = 'sent'; }
        if ($po->sent_at === null) { $po->sent_at = now(); }
        $po->save();

        return ['ok' => true, 'message' => ($po->po_no ?: 'LPO') . ' marked as sent.'];
    }

    /** Build the LPO PDF and send it to the supplier's WhatsApp (public URL — works on both drivers). */
    private function sendSupplier(CapabilityContext $ctx, array $in): array
    {
        $t = $ctx->tenant;
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        if (! PurchaseOrderLine::where('tenant_id', $ctx->tenantId())->where('purchase_order_id', $po->id)->exists()) {
            return ['ok' => false, 'message' => 'Add at least one item before sending.'];
        }

        $svc = app(LpoPdfService::class);
        if (! $svc->available()) { return ['ok' => false, 'message' => 'The PDF engine is not available on this server.']; }
        $doc = $svc->generate($t, $po);
        if (! $doc || empty($doc['url'])) { return ['ok' => false, 'message' => 'Could not build the LPO PDF (or its public URL).']; }

        $gw = app(WhatsAppManager::class)->forTenant($t);
        if (! method_exists($gw, 'sendDocument')) { return ['ok' => false, 'message' => 'WhatsApp document sending is not available on this connection.']; }
        $instance = $t->whatsapp_instance ?? null;

        $sup = $this->supplier($ctx, $po);
        $supPhone = preg_replace('/[^0-9]/', '', (string) optional($sup)->phone);
        $target = $supPhone !== '' ? $supPhone : null;
        $toWho = $target ? (string) $po->supplier_name : 'the owner';
        if (! $target && method_exists($t, 'ownerAlertNumbers')) { $nums = $t->ownerAlertNumbers(); $target = $nums[0] ?? null; }
        if (! $instance || ! $target) {
            return ['ok' => false, 'message' => 'No WhatsApp number to send to — add a phone to this supplier or set an owner alert number.'];
        }

        $caption = '🧾 Purchase Order ' . ($po->po_no ?: ('LPO-' . $po->id)) . ' from ' . $t->name
            . '. Total ' . strtoupper((string) $doc['currency']) . ' ' . number_format((float) $doc['total'], 2) . '.';
        try {
            // Pass the public URL (not base64): the Meta Cloud API driver requires a URL, and the
            // Evolution driver accepts one too — so this one call works on whichever driver the tenant uses.
            $gw->sendDocument($instance, $target, (string) $doc['url'], (string) $doc['fileName'], $caption);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'WhatsApp send failed — try again shortly.'];
        }

        // Sending IS issuing the order: a draft becomes 'sent'.
        if ((string) $po->status === 'draft') { $po->status = 'sent'; }
        if ($po->sent_at === null) { $po->sent_at = now(); }
        $po->save();

        return ['ok' => true, 'message' => 'LPO sent on WhatsApp to ' . $toWho . '.'];
    }

    private function setStatus(CapabilityContext $ctx, array $in, string $status): array
    {
        $po = $this->order($ctx, $in);
        if (! $po) { return ['ok' => false, 'error' => 'not_found']; }
        $po->status = $status;
        if ($status === 'closed') { $po->closed_at = now(); }
        $po->save();

        return ['ok' => true, 'message' => 'Order ' . $this->statusLabel($status) . '.'];
    }

    /**
     * Receive a line to a new cumulative total: posts only the delta into the stock ledger at this
     * shop's own warehouse. Best-effort on the ledger (a receipt is never lost to a ledger hiccup).
     * Returns the delta received.
     */
    private function receiveTo(CapabilityContext $ctx, PurchaseOrderLine $l, float $newTotal): float
    {
        $prev = (float) $l->received_qty;
        $now = max(0.0, round($newTotal, 3));
        $delta = round($now - $prev, 3);
        $l->received_qty = $now;
        $l->received_at = now();
        $l->line_status = $l->deriveStatus();
        $l->save();

        if ($l->product_id && $delta > 0.0001) {
            try {
                $sl = \App\Services\Inventory\StockLedger::forTenant($ctx->tenant);
                $sl->receive([
                    'product_id' => (int) $l->product_id, 'sku' => null,
                    'location' => $sl->homeLocation(),
                    'qty' => $delta, 'ref_type' => 'lpo_receive', 'ref_id' => (int) $l->id,
                    'note' => 'LPO receipt: ' . $l->name, 'created_by' => (string) ($ctx->user->name ?? ''),
                ]);
            } catch (\Throwable $e) {
            }
        }

        return $delta;
    }

    /** Recompute the header status from the lines (draft/sent → partial → received; never touches closed/cancelled). */
    private function refreshStatus(CapabilityContext $ctx, int $poId): void
    {
        $po = PurchaseOrder::where('tenant_id', $ctx->tenantId())->find($poId);
        if (! $po || in_array((string) $po->status, ['cancelled', 'closed'], true)) { return; }
        $lines = PurchaseOrderLine::where('tenant_id', $ctx->tenantId())->where('purchase_order_id', $poId)->get();
        if ($lines->isEmpty()) { return; }

        $anyRecv = false; $allRecv = true;
        foreach ($lines as $l) {
            $r = (float) $l->received_qty; $o = (float) $l->ordered_qty;
            if ($r > 0) { $anyRecv = true; }
            if (! ($o > 0 && $r + 0.0001 >= $o)) { $allRecv = false; }
        }

        if ($allRecv && $anyRecv) {
            $po->status = 'received';
        } elseif ($anyRecv) {
            $po->status = 'partial';
        } else {
            $po->status = (string) $po->status === 'draft' ? 'draft' : 'sent';
        }
        $po->save();
    }

    private function order(CapabilityContext $ctx, array $in): ?PurchaseOrder
    {
        return PurchaseOrder::where('tenant_id', $ctx->tenantId())->find((int) ($in['id'] ?? 0));
    }

    private function line(CapabilityContext $ctx, array $in): ?PurchaseOrderLine
    {
        $poId = (int) ($in['id'] ?? 0);
        $lineId = (int) ($in['line_id'] ?? 0);

        return PurchaseOrderLine::where('tenant_id', $ctx->tenantId())->where('purchase_order_id', $poId)->find($lineId);
    }

    private function supplier(CapabilityContext $ctx, PurchaseOrder $po): ?\App\Models\Supplier
    {
        if (! $po->supplier_id) { return null; }

        return \App\Models\Supplier::where('tenant_id', $ctx->tenantId())->find((int) $po->supplier_id);
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }

    private function lineLabel(string $s): string
    {
        return match ($s) {
            'pending' => 'Awaiting',
            'partial' => 'Partial',
            'received' => 'Received',
            default => ucfirst($s ?: 'pending'),
        };
    }

    private function lineBadge(string $s): string
    {
        return match ($s) {
            'received' => 'ok',
            'partial' => 'warn',
            default => 'default',
        };
    }

    private function statusLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'Draft',
            'sent' => 'Sent',
            'partial' => 'Part-received',
            'received' => 'Received',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled',
            default => ucfirst($s ?: 'draft'),
        };
    }
}
