<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\DemandPlan;
use App\Models\DemandPlanLine;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * Demand Plan / Procurement Request record. Ashish plans (current stock, expected sales, requested qty);
 * once submitted it is the Procurement Request Jayesh works line-by-line (available / purchased /
 * supplier / ETA / status). Planning only — real purchases still post through order_costs (Phase 2).
 */
final class DemandPlanCapability implements ObjectCapability
{
    public function key(): string { return 'demandPlan'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'demandPlan'])->meta(['capability' => 'demandPlan']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $plan = DemandPlan::where('tenant_id', $t)->findOrFail((int) $id);
        $lines = DemandPlanLine::where('tenant_id', $t)->where('demand_plan_id', $plan->id)->orderBy('name')->get();

        $canEdit = $ctx->can('menu:purchases');
        $isOpen = ! in_array($plan->status, ['completed', 'cancelled'], true);
        $endpoint = '/api/v1/workspace/demandPlan/' . $plan->id;
        // Stock is real on-hand from the receipts ledger (0 until goods are received) — never the fake
        // catalog number. Ashish confirms any pre-existing opening stock per line.
        $stockNote = 'stock = on-hand from receipts ledger — confirm opening stock per line';

        // supplier pick-list for the procurement form
        $suppliers = Supplier::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        $supplierCombo = 'combo:' . (count($suppliers) ? implode('|', $suppliers) : 'Supplier');

        // product pick-list so "Add product" selects from the real catalogue (pipe-separated combo).
        $productNames = \App\Models\Product::where('tenant_id', $t)->whereNull('archived_at')
            ->orderBy('name')->limit(1000)->pluck('name')
            ->map(fn ($n) => str_replace('|', ' ', trim((string) $n)))->filter()->unique()->values()->all();
        $productCombo = 'combo:' . (count($productNames) ? implode('|', $productNames) : 'Product');

        // people pick-list + rates for booking the purchase into the books (/papi/order-costs).
        $me = trim((string) ($ctx->user->name ?? ''));
        $members = \App\Models\User::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        if ($me !== '') { $members = array_values(array_unique(array_merge([$me], $members))); }
        $comboPeople = 'combo:' . (count($members) ? implode('|', $members) : ($me ?: 'Me'));
        $fx = \App\Services\Money\Fx::forTenant($ctx->tenant);
        $rateMap = ['USD' => 1, 'UGX' => (int) round($fx->ratePerUsd('UGX')), 'SSP' => (int) round($fx->ratePerUsd('SSP'))];
        $defCur = $ctx->nativeCurrency();
        $defRate = $rateMap[$defCur] ?? 1;

        // linked shipment (Phase 2 — the plan's goods belong to one Shipment)
        $shipment = $plan->shipment_id ? \App\Models\Shipment::where('tenant_id', $t)->find($plan->shipment_id) : null;
        $purchasableUnallocated = $lines->filter(fn ($l) => (float) $l->qty_purchased > 0 && $l->product_id && ! $l->consignment_id)->count();

        $totRequested = 0.0; $totPurchased = 0.0; $totSent = 0.0; $totRecv = 0.0;
        $pending = 0; $partial = 0; $purchased = 0; $notAvail = 0;
        $planRows = []; $procRows = [];
        $bulkSchema = []; $bulkLabels = []; $prefReq = []; $prefPur = []; $prefSent = []; $prefRecv = [];

        foreach ($lines as $l) {
            $req = (float) $l->requested_qty;
            $got = (float) $l->qty_purchased;
            $sent = (float) $l->qty_sent;
            $recv = (float) $l->received_qty;
            $st = (string) $l->line_status;
            $short = max(0.0, $req - $got);
            $totRequested += $req; $totPurchased += min($req, $got); $totSent += $sent; $totRecv += $recv;
            if ($st === 'pending') { $pending++; }
            elseif ($st === 'partial') { $partial++; }
            elseif ($st === 'purchased') { $purchased++; }
            elseif ($st === 'not_available') { $notAvail++; }

            // one bulk-entry field per product (single-screen fast entry)
            $bk = 'q_' . $l->id;
            $bulkSchema[$bk] = 'number';
            $bulkLabels[$bk] = mb_strlen((string) $l->name) > 44 ? (mb_substr((string) $l->name, 0, 44) . '…') : (string) $l->name;
            $prefReq[$bk] = $req; $prefPur[$bk] = $got; $prefSent[$bk] = $sent > 0 ? $sent : $got; $prefRecv[$bk] = $recv;

            $nameCell = ['value' => (string) $l->name . ($l->product_id ? '' : ' (ad-hoc)')];
            $statusCell = ['value' => $this->lineLabel($st), 'badge' => $this->lineBadge($st)];

            // --- Plan tab (Ashish: what to request) ---
            $planBtns = [];
            if ($canEdit && $isOpen) {
                $planBtns[] = ['icon' => 'edit', 'title' => 'Edit figures', 'action' => [
                    'key' => 'edit_line', 'label' => 'Edit — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/edit_line', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['current_stock' => 'number', 'expected_sales' => 'number', 'requested_qty' => 'number'],
                    'labels' => ['current_stock' => 'Opening stock in Juba', 'expected_sales' => 'Expected sales', 'requested_qty' => 'Required quantity'],
                    'prefill' => ['current_stock' => (float) $l->current_stock, 'expected_sales' => (float) $l->expected_sales, 'requested_qty' => $req],
                    'body' => ['line_id' => $l->id],
                ]];
            }
            $planRows[] = ['cells' => [
                $nameCell,
                ['value' => $this->qty($l->current_stock)],
                ['value' => $this->qty($l->expected_sales)],
                ['value' => $this->qty($l->suggested_qty)],
                ['value' => $this->qty($req)],
                $statusCell,
                ['buttons' => $planBtns],
            ]];

            // --- Procure & Ship tab (Jayesh: buy → load → receive) ---
            $procBtns = [];
            if ($canEdit && $isOpen) {
                $procBtns[] = ['icon' => 'cart', 'title' => 'Record purchase', 'action' => [
                    'key' => 'record_procurement', 'label' => 'Procure — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/record_procurement', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['qty_available' => 'number', 'qty_purchased' => 'number', 'supplier' => $supplierCombo, 'expected_delivery' => 'text', 'line_status' => 'enum:pending,partial,purchased,not_available', 'remarks' => 'text'],
                    'labels' => ['qty_available' => 'Qty available at supplier', 'qty_purchased' => 'Qty purchased', 'supplier' => 'Supplier', 'expected_delivery' => 'Expected delivery (YYYY-MM-DD)', 'line_status' => 'Line status', 'remarks' => 'Remarks'],
                    'prefill' => ['qty_purchased' => $got, 'qty_available' => $l->qty_available !== null ? (float) $l->qty_available : '', 'supplier' => (string) ($l->supplier_name ?: ''), 'expected_delivery' => $l->expected_delivery ? Carbon::parse($l->expected_delivery)->format('Y-m-d') : '', 'line_status' => $st, 'remarks' => (string) ($l->remarks ?: '')],
                    'body' => ['line_id' => $l->id],
                ]];
                if ($got > 0) {
                    $procBtns[] = ['icon' => 'dispatch', 'title' => 'Loaded / sent', 'action' => [
                        'key' => 'record_sent_one', 'label' => 'Sent — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/record_sent_one', 'actor' => 'human', 'tone' => 'primary',
                        'schema' => ['qty_sent' => 'number'], 'labels' => ['qty_sent' => 'Quantity loaded onto transport'],
                        'prefill' => ['qty_sent' => $sent > 0 ? $sent : $got], 'body' => ['line_id' => $l->id],
                    ]];
                    $procBtns[] = ['icon' => 'download', 'title' => 'Receive in Juba', 'action' => [
                        'key' => 'receive_goods', 'label' => 'Receive in Juba — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/receive_goods', 'actor' => 'human', 'tone' => 'primary',
                        'schema' => ['received_qty' => 'number', 'damaged_qty' => 'number', 'remarks' => 'text'],
                        'labels' => ['received_qty' => 'Total received in Juba', 'damaged_qty' => 'Damaged (of received)', 'remarks' => 'Note'],
                        'prefill' => ['received_qty' => $recv, 'damaged_qty' => (float) $l->damaged_qty, 'remarks' => (string) ($l->remarks ?: '')],
                        'body' => ['line_id' => $l->id],
                    ]];
                    $body = ['stage' => 'purchase', 'product_id' => (int) $l->product_id, 'qty' => (float) $l->qty_purchased];
                    if ($l->supplier_id) { $body['supplier_id'] = (int) $l->supplier_id; }
                    $procBtns[] = ['icon' => 'dollar', 'title' => 'Record bill (book cost)', 'action' => [
                        'key' => 'record_bill', 'label' => 'Record bill — ' . $l->name, 'method' => 'POST', 'endpoint' => '/papi/order-costs', 'actor' => 'human', 'tone' => 'primary',
                        'schema' => ['native_amount' => 'money', 'currency' => 'enum:UGX,USD,SSP', 'rate_to_usd' => 'number', 'pay_status' => 'enum:credit,cash', 'note' => 'text', 'holder' => $comboPeople],
                        'labels' => ['native_amount' => 'Bill amount', 'rate_to_usd' => 'Rate (native per $1)', 'pay_status' => 'Credit (owe) or cash (paid now)', 'note' => 'Item / description', 'holder' => 'Paid by (if cash)'],
                        'prefill' => ['currency' => $defCur, 'rate_to_usd' => $defRate, 'pay_status' => 'credit', 'note' => $l->name . ' × ' . $this->qty($l->qty_purchased), 'holder' => $me],
                        'body' => $body, 'autoRate' => ['field' => 'rate_to_usd', 'source' => 'currency', 'map' => $rateMap],
                    ]];
                }
                $procBtns[] = ['icon' => 'trash', 'title' => 'Remove', 'tone' => 'danger', 'post' => $endpoint . '/remove_line', 'body' => ['line_id' => $l->id], 'confirm' => 'Remove ' . $l->name . ' from this plan?'];
            }
            $procRows[] = ['cells' => [
                $nameCell,
                ['value' => $this->qty($req)],
                ['value' => $got > 0 ? $this->qty($got) : '—', 'tone' => ($got >= $req && $req > 0) ? 'ok' : ($got > 0 ? 'warn' : null)],
                ['value' => $sent > 0 ? $this->qty($sent) : '—', 'tone' => ($sent >= $got && $got > 0) ? 'ok' : ($sent > 0 ? 'warn' : null)],
                ['value' => $recv > 0 ? $this->qty($recv) : '—', 'tone' => ($recv >= $sent && $sent > 0) ? 'ok' : ($recv > 0 ? 'warn' : null)],
                ['value' => $short > 0 && $st !== 'not_available' ? $this->qty($short) : '—', 'tone' => $short > 0 && $st !== 'not_available' ? 'danger' : null],
                ['value' => (string) ($l->supplier_name ?: '—')],
                $statusCell,
                ['buttons' => $procBtns],
            ]];
        }

        $planCols = [
            ['label' => 'Product'], ['label' => 'Stock', 'align' => 'right'], ['label' => 'Expected', 'align' => 'right'],
            ['label' => 'Suggested', 'align' => 'right'], ['label' => 'Required', 'align' => 'right'], ['label' => 'Status'], ['label' => ''],
        ];
        $procCols = [
            ['label' => 'Product'], ['label' => 'Required', 'align' => 'right'], ['label' => 'Purchased', 'align' => 'right'],
            ['label' => 'Sent', 'align' => 'right'], ['label' => 'Received', 'align' => 'right'], ['label' => 'Short', 'align' => 'right'],
            ['label' => 'Supplier'], ['label' => 'Status'], ['label' => ''],
        ];

        $pct = $totRequested > 0 ? (int) round(min(100, max(0, $totPurchased / $totRequested * 100))) : 0;
        $hasLines = count($bulkSchema) > 0;

        // Fast bulk entry: ONE form with a number field per product (beats a popup per row).
        $mkBulk = fn (string $key, string $label, array $pref, string $tone = 'default') => [
            'key' => $key, 'label' => $label, 'method' => 'POST', 'endpoint' => $endpoint . '/' . $key,
            'actor' => 'human', 'tone' => $tone, 'schema' => $bulkSchema, 'labels' => $bulkLabels, 'prefill' => $pref,
        ];
        $addLine = ['key' => 'add_line', 'label' => 'Add product', 'method' => 'POST', 'endpoint' => $endpoint . '/add_line', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['name' => $productCombo, 'requested_qty' => 'number'],
            'labels' => ['name' => 'Product (pick from catalogue, or type a new item)', 'requested_qty' => 'Required quantity'], 'prefill' => []];
        $submit = ['key' => 'submit', 'label' => 'Submit as request', 'method' => 'POST', 'endpoint' => $endpoint . '/submit', 'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Submit this plan as a Procurement Request for purchasing?'];
        $allocate = ['key' => 'allocate_shipment', 'label' => $shipment ? ('Update shipment ' . $shipment->shipment_number) : 'Create shipment', 'method' => 'POST', 'endpoint' => $endpoint . '/allocate_shipment', 'actor' => 'human', 'tone' => 'default', 'confirm' => $shipment ? ('Push loaded items onto shipment ' . $shipment->shipment_number . '?') : 'Create a Kampala → Juba shipment from the loaded items?'];

        $headerActions = []; $more = [];
        if ($canEdit && $isOpen) {
            if ($plan->status === 'draft') {
                if ($hasLines) { $headerActions[] = $mkBulk('bulk_required', 'Set required (all)', $prefReq, 'primary'); }
                $headerActions[] = $submit;
                $headerActions[] = $addLine;
            } else {
                if ($hasLines) { $headerActions[] = $mkBulk('bulk_purchased', 'Record purchases (all)', $prefPur, 'primary'); }
                if ($purchasableUnallocated > 0 || $shipment) { $headerActions[] = $allocate; }
                $headerActions[] = $addLine;
            }
            if ($hasLines) {
                $more[] = $mkBulk('bulk_required', 'Set required — all products', $prefReq);
                $more[] = $mkBulk('bulk_purchased', 'Record purchases — all', $prefPur);
                $more[] = $mkBulk('bulk_sent', 'Record sent / loaded — all', $prefSent);
                $more[] = $mkBulk('bulk_receive', 'Receive in Juba — all', $prefRecv);
            }
            $more[] = ['key' => 'complete', 'label' => 'Mark completed', 'method' => 'POST', 'endpoint' => $endpoint . '/complete', 'confirm' => 'Mark this procurement plan as completed?'];
            $more[] = ['key' => 'cancel', 'label' => 'Cancel plan', 'method' => 'POST', 'endpoint' => $endpoint . '/cancel', 'tone' => 'danger', 'confirm' => 'Cancel this plan? Lines are kept for reference.'];
        }

        // Draft plans open on the Plan tab (Ashish); live plans open on Procure & Ship (Jayesh).
        $planTab = ['key' => 'plan', 'label' => 'Plan', 'count' => $lines->count()];
        $procTab = ['key' => 'procure', 'label' => 'Procure & Ship', 'count' => $lines->count()];
        $tabsList = $plan->status === 'draft' ? [$planTab, $procTab] : [$procTab, $planTab];

        return (new WorkspacePayload)
            ->object([
                'type' => 'demandPlan', 'id' => (string) $plan->id, 'ref' => (string) ($plan->plan_no ?: $plan->id),
                'title' => (string) ($plan->plan_no ?: ('Plan ' . $plan->id)),
                'subtitle' => trim(implode(' · ', array_filter([
                    $plan->plan_date ? Carbon::parse($plan->plan_date)->format('D d M Y') : '',
                    $plan->transport_mode ? ('by ' . ucfirst((string) $plan->transport_mode)) : '',
                    (string) $plan->title,
                    $shipment ? ('Shipment ' . $shipment->shipment_number . ' · ' . ucfirst(str_replace('_', ' ', (string) $shipment->status))) : ($plan->shipment_ref ? ('Shipment ' . $plan->shipment_ref) : ''),
                ]))),
                'status' => $this->planLabel((string) $plan->status),
            ])
            ->headline($pct . '% purchased', $lines->count() . ' products · ' . $this->qty($totRequested) . ' required · ' . $this->qty($totSent) . ' sent', $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : 'default'))
            ->financials([
                ['key' => 'tobuy', 'label' => 'To buy', 'value' => $pending + $partial, 'tone' => ($pending + $partial) > 0 ? 'warn' : 'ok', 'foot' => 'products still to purchase'],
                ['key' => 'purchased', 'label' => 'Purchased', 'value' => $purchased, 'tone' => 'ok', 'foot' => 'lines fully bought'],
                ['key' => 'sent', 'label' => 'Sent', 'value' => $this->qty($totSent), 'tone' => $totSent > 0 ? 'ok' : 'default', 'foot' => 'units loaded to Juba'],
                ['key' => 'received', 'label' => 'Received', 'value' => $this->qty($totRecv), 'tone' => $totRecv > 0 ? 'ok' : 'default', 'foot' => 'units arrived in Juba'],
                ['key' => 'shipment', 'label' => 'Shipment', 'value' => $shipment ? (string) $shipment->shipment_number : '—', 'tone' => $shipment ? 'ok' : 'default', 'foot' => $shipment ? ucfirst(str_replace('_', ' ', (string) $shipment->status)) : 'not allocated yet'],
            ])
            ->tabs($tabsList)
            ->tabSections([
                'plan' => [[
                    'kind' => 'table', 'title' => 'Demand plan', 'sub' => $stockNote,
                    'searchable' => true, 'columns' => $planCols, 'rows' => $planRows,
                    'empty' => 'No products yet — use “Add product”, or create the plan with auto-suggest on.',
                ]],
                'procure' => [[
                    'kind' => 'table', 'title' => 'Purchasing & dispatch', 'sub' => 'buy → load onto ' . ($plan->transport_mode ? ucfirst((string) $plan->transport_mode) : 'transport') . ' → receive in Juba',
                    'searchable' => true, 'columns' => $procCols, 'rows' => $procRows,
                    'empty' => 'No products yet.',
                ]],
            ])
            ->actions($headerActions)
            ->more($more)
            ->meta(['capability' => 'demandPlan', 'ttl' => 20, 'layout' => 'record', 'permissions' => ['menu:purchases']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if (! $ctx->can('menu:purchases')) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return match ($action) {
            'submit'   => $this->setStatus($ctx, $input, 'in_procurement', true),
            'complete' => $this->setStatus($ctx, $input, 'completed', false),
            'cancel'   => $this->setStatus($ctx, $input, 'cancelled', false),
            'add_line' => $this->addLine($ctx, $input),
            'edit_line' => $this->editLine($ctx, $input),
            'record_procurement' => $this->recordProcurement($ctx, $input),
            'record_sent_one' => $this->recordSentOne($ctx, $input),
            'receive_goods' => $this->receiveGoods($ctx, $input),
            'allocate_shipment' => $this->allocateShipment($ctx, $input),
            'remove_line' => $this->removeLine($ctx, $input),
            'bulk_required'  => $this->bulkSet($ctx, $input, 'requested_qty'),
            'bulk_purchased' => $this->bulkSet($ctx, $input, 'qty_purchased'),
            'bulk_sent'      => $this->bulkSet($ctx, $input, 'qty_sent'),
            'bulk_receive'   => $this->bulkSet($ctx, $input, 'received_qty'),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /**
     * Fast bulk entry: the form posts one numeric field per line, keyed q_{lineId}. Applies the value
     * to $field on every matching line in a single request (the single-screen alternative to per-row
     * popups). Received values route through the ledger; purchases advance the plan.
     */
    private function bulkSet(CapabilityContext $ctx, array $in, string $field): array
    {
        $plan = $this->plan($ctx, $in);
        if (! $plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $t = $ctx->tenantId();
        $n = 0;
        foreach ($in as $k => $v) {
            if (! is_string($k) || strncmp($k, 'q_', 2) !== 0) { continue; }
            if ($v === '' || $v === null) { continue; }
            $lid = (int) substr($k, 2);
            if ($lid <= 0) { continue; }
            $l = DemandPlanLine::where('tenant_id', $t)->where('demand_plan_id', $plan->id)->find($lid);
            if (! $l) { continue; }
            $val = max(0.0, round((float) $v, 3));
            $this->applyField($ctx, $l, $field, $val);
            $n++;
        }
        if ($field === 'qty_purchased') { $this->advancePlan($ctx, $plan); }

        $label = match ($field) {
            'requested_qty' => 'Required quantities', 'qty_purchased' => 'Purchased quantities',
            'qty_sent' => 'Sent quantities', 'received_qty' => 'Received quantities', default => 'Values',
        };

        return ['ok' => true, 'message' => $label . ' updated for ' . $n . ' product(s).'];
    }

    /** Apply one field to a line (with the right side-effects per field). */
    private function applyField(CapabilityContext $ctx, DemandPlanLine $l, string $field, float $val): void
    {
        if ($field === 'requested_qty') {
            $l->requested_qty = $val;
            $l->suggested_qty = max(0.0, round((float) $l->expected_sales - (float) $l->current_stock - (float) $l->in_transit, 3));
            $l->line_status = $l->deriveStatus();
            $l->save();
        } elseif ($field === 'qty_purchased') {
            $l->qty_purchased = $val;
            $l->line_status = $l->deriveStatus();
            $l->save();
        } elseif ($field === 'qty_sent') {
            $l->qty_sent = $val;
            $l->sent_at = now();
            $l->save();
            $this->syncConsignmentSent($ctx, $l);
        } elseif ($field === 'received_qty') {
            $this->receiveTo($ctx, $l, $val);
        }
    }

    /** Record how much of a line was loaded onto today's transport (single line). */
    private function recordSentOne(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (array_key_exists('qty_sent', $in)) {
            $l->qty_sent = max(0.0, round((float) $in['qty_sent'], 3));
            $l->sent_at = now();
            $l->save();
            $this->syncConsignmentSent($ctx, $l);
        }

        return ['ok' => true, 'message' => 'Loaded ' . $this->qty($l->qty_sent) . ' of ' . $l->name . ' onto transport.'];
    }

    /** Mirror the loaded quantity onto the linked shipment manifest (display only). */
    private function syncConsignmentSent(CapabilityContext $ctx, DemandPlanLine $l): void
    {
        if (! $l->consignment_id) { return; }
        try {
            $cs = \App\Models\ConsignmentStock::where('tenant_id', $ctx->tenantId())->find((int) $l->consignment_id);
            if ($cs) { $cs->qty_sent = (float) $l->qty_sent; $cs->save(); }
        } catch (\Throwable $e) {
        }
    }

    /** Advance the plan: first activity flips draft/submitted → in_procurement; all resolved → completed. */
    private function advancePlan(CapabilityContext $ctx, DemandPlan $plan): void
    {
        if (in_array($plan->status, ['completed', 'cancelled'], true)) { return; }
        if ($plan->status === 'draft' || $plan->status === 'submitted') {
            $plan->status = 'in_procurement';
            if ($plan->submitted_at === null) { $plan->submitted_at = now(); }
        }
        $open = DemandPlanLine::where('tenant_id', $ctx->tenantId())->where('demand_plan_id', $plan->id)
            ->whereIn('line_status', ['pending', 'partial'])->count();
        if ($open === 0 && $plan->status !== 'draft') { $plan->status = 'completed'; }
        $plan->save();
    }

    /**
     * Receive a line to a new cumulative total: posts only the delta into the Juba stock ledger and
     * keeps the shipment manifest in sync. Returns the delta received. Best-effort on the ledger.
     */
    private function receiveTo(CapabilityContext $ctx, DemandPlanLine $l, float $newTotal): float
    {
        $prev = (float) $l->received_qty;
        $now = max(0.0, round($newTotal, 3));
        $delta = round($now - $prev, 3);
        $l->received_qty = $now;
        $l->received_at = now();
        $l->save();

        if ($l->product_id && $delta > 0.0001) {
            try {
                \App\Services\Inventory\StockLedger::forTenant($ctx->tenant)->receive([
                    'product_id' => (int) $l->product_id, 'sku' => null,
                    'location' => \App\Services\Inventory\StockLedger::LOC_JUBA,
                    'qty' => $delta, 'ref_type' => 'procurement_receive', 'ref_id' => (int) $l->id,
                    'note' => 'Received in Juba: ' . $l->name, 'created_by' => (string) ($ctx->user->name ?? ''),
                ]);
            } catch (\Throwable $e) {
            }
        }
        if ($l->consignment_id) {
            try {
                $cs = \App\Models\ConsignmentStock::where('tenant_id', $ctx->tenantId())->find((int) $l->consignment_id);
                if ($cs) { $cs->qty_received = (float) $l->received_qty; $cs->save(); }
            } catch (\Throwable $e) {
            }
        }

        return $delta;
    }

    private function plan(CapabilityContext $ctx, array $in): ?DemandPlan
    {
        return DemandPlan::where('tenant_id', $ctx->tenantId())->find((int) ($in['id'] ?? 0));
    }

    private function line(CapabilityContext $ctx, array $in): ?DemandPlanLine
    {
        $planId = (int) ($in['id'] ?? 0);
        $lineId = (int) ($in['line_id'] ?? 0);

        return DemandPlanLine::where('tenant_id', $ctx->tenantId())->where('demand_plan_id', $planId)->find($lineId);
    }

    private function setStatus(CapabilityContext $ctx, array $in, string $status, bool $stampSubmitted): array
    {
        $plan = $this->plan($ctx, $in);
        if (! $plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $plan->status = $status;
        if ($stampSubmitted && $plan->submitted_at === null) {
            $plan->submitted_at = now();
        }
        $plan->save();

        return ['ok' => true, 'message' => 'Plan ' . $this->planLabel($status) . '.'];
    }

    private function addLine(CapabilityContext $ctx, array $in): array
    {
        $plan = $this->plan($ctx, $in);
        if (! $plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'bad_input', 'message' => 'Pick a product or enter an item name.'];
        }
        $tid = $ctx->tenantId();

        // Resolve to a real catalogue product when the name matches — so this line links to a product
        // (its receipts feed the stock ledger) and we can seed its real current on-hand.
        $pid = null; $current = 0.0;
        $prod = \App\Models\Product::where('tenant_id', $tid)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($prod) {
            $pid = (int) $prod->id;
            $name = (string) $prod->name;
            try {
                $sl = \App\Services\Inventory\StockLedger::forTenant($ctx->tenant);
                $current = round((float) $sl->onHandFor($pid, null, \App\Services\Inventory\StockLedger::LOC_JUBA)
                    + (float) $sl->onHandFor($pid, null, \App\Services\Inventory\StockLedger::LOC_KAMPALA), 3);
            } catch (\Throwable $e) {
                $current = 0.0;
            }
        }

        DemandPlanLine::create([
            'tenant_id' => $tid, 'demand_plan_id' => $plan->id,
            'product_id' => $pid, 'name' => $name,
            'current_stock' => $current,
            'requested_qty' => round((float) ($in['requested_qty'] ?? 0), 3),
            'line_status' => 'pending',
        ]);

        return ['ok' => true, 'message' => 'Added ' . $name . ($pid ? ' (catalogue product).' : ' (ad-hoc item).')];
    }

    private function editLine(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (array_key_exists('current_stock', $in)) { $l->current_stock = round((float) $in['current_stock'], 3); }
        if (array_key_exists('expected_sales', $in)) { $l->expected_sales = round((float) $in['expected_sales'], 3); }
        if (array_key_exists('requested_qty', $in)) { $l->requested_qty = round((float) $in['requested_qty'], 3); }
        // Recompute the suggestion from the confirmed figures so "Suggested" stays a live reference:
        // suggested = expected sales − real stock on hand − already in transit (never below zero).
        $l->suggested_qty = max(0.0, round((float) $l->expected_sales - (float) $l->current_stock - (float) $l->in_transit, 3));
        $l->line_status = $l->deriveStatus();
        $l->save();

        return ['ok' => true, 'message' => 'Updated ' . $l->name . '.'];
    }

    private function recordProcurement(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (array_key_exists('qty_available', $in) && $in['qty_available'] !== '') { $l->qty_available = round((float) $in['qty_available'], 3); }
        if (array_key_exists('qty_purchased', $in)) { $l->qty_purchased = round((float) $in['qty_purchased'], 3); }
        $supplier = trim((string) ($in['supplier'] ?? ''));
        if ($supplier !== '') {
            $l->supplier_name = $supplier;
            $sup = Supplier::where('tenant_id', $ctx->tenantId())->whereRaw('lower(name) = ?', [mb_strtolower($supplier)])->first();
            $l->supplier_id = $sup ? (int) $sup->id : null;
        }
        $eta = trim((string) ($in['expected_delivery'] ?? ''));
        if ($eta !== '') {
            try { $l->expected_delivery = Carbon::parse($eta)->toDateString(); } catch (\Throwable $e) {}
        }
        if (array_key_exists('remarks', $in)) { $l->remarks = trim((string) $in['remarks']) ?: null; }

        // Explicit status wins (e.g. "not_available"); otherwise derive from quantities.
        $status = (string) ($in['line_status'] ?? '');
        $l->line_status = in_array($status, ['pending', 'partial', 'purchased', 'not_available'], true) ? $status : $l->deriveStatus();
        if ($status !== 'not_available' && $status === '') { $l->line_status = $l->deriveStatus(); }
        $l->save();

        // Move the plan forward: first purchase flips it to in_procurement; all lines resolved → completed.
        $plan = DemandPlan::where('tenant_id', $ctx->tenantId())->find($l->demand_plan_id);
        if ($plan && ! in_array($plan->status, ['completed', 'cancelled'], true)) {
            if ($plan->status === 'draft' || $plan->status === 'submitted') {
                $plan->status = 'in_procurement';
                if ($plan->submitted_at === null) { $plan->submitted_at = now(); }
            }
            $open = DemandPlanLine::where('tenant_id', $ctx->tenantId())->where('demand_plan_id', $plan->id)
                ->whereIn('line_status', ['pending', 'partial'])->count();
            if ($open === 0) { $plan->status = 'completed'; }
            $plan->save();
        }

        return ['ok' => true, 'message' => 'Recorded procurement for ' . $l->name . ' (' . $this->lineLabel($l->line_status) . ').'];
    }

    /**
     * Goods receipt in Juba — records the received quantity AND builds the REAL stock ledger (Juba IN).
     * Only the delta since the last receipt is posted, only for catalogue products, only positive
     * arrivals; the ledger write is best-effort so a receipt is never lost to a ledger hiccup.
     */
    private function receiveGoods(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (array_key_exists('damaged_qty', $in) && $in['damaged_qty'] !== '') { $l->damaged_qty = max(0.0, round((float) $in['damaged_qty'], 3)); }
        if (array_key_exists('remarks', $in) && trim((string) $in['remarks']) !== '') { $l->remarks = trim((string) $in['remarks']); }

        $newTotal = array_key_exists('received_qty', $in) && $in['received_qty'] !== '' ? (float) $in['received_qty'] : (float) $l->received_qty;
        $delta = $this->receiveTo($ctx, $l, $newTotal);   // posts the delta to the Juba ledger + syncs the shipment
        $intoStock = $l->product_id && $delta > 0.0001;

        return ['ok' => true, 'message' => 'Received ' . $this->qty($l->received_qty) . ' of ' . $l->name . ($intoStock ? ' — added to Juba stock.' : ($l->product_id ? '.' : ' (ad-hoc line — not linked to a product, so not added to stock).'))];
    }

    /**
     * Allocate the plan's purchased lines to a Kampala → Juba Shipment (the central object). Creates the
     * shipment via the frozen ShipmentService (packed state, auto-numbered) the first time, then pushes
     * each purchased catalogue line onto it as ConsignmentStock (qty_requested / qty_sent). Idempotent:
     * a line already on the shipment is updated, not duplicated.
     */
    private function allocateShipment(CapabilityContext $ctx, array $in): array
    {
        $plan = $this->plan($ctx, $in);
        if (! $plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $t = $ctx->tenantId();
        $lines = DemandPlanLine::where('tenant_id', $t)->where('demand_plan_id', $plan->id)
            ->whereNotNull('product_id')
            ->where(fn ($q) => $q->where('qty_sent', '>', 0)->orWhere('qty_purchased', '>', 0))
            ->get();
        if ($lines->isEmpty()) {
            return ['ok' => false, 'error' => 'nothing_to_ship', 'message' => 'Nothing loaded yet — record what was purchased and sent first.'];
        }

        // Get or create the shipment (reuse the frozen ShipmentService so numbering + custody are correct).
        $ship = $plan->shipment_id ? \App\Models\Shipment::where('tenant_id', $t)->find($plan->shipment_id) : null;
        if (! $ship) {
            try {
                $ship = app(\App\Services\Logistics\ShipmentService::class)->create([
                    'tenant_id'        => $t,
                    'shipment_number'  => trim((string) $plan->shipment_ref) ?: null,
                    'origin_city'      => 'Kampala',
                    'destination_city' => 'Juba',
                    'transport_company'=> $plan->transport_mode ? ucfirst((string) $plan->transport_mode) : null,
                    'actor_name'       => (string) ($ctx->user->name ?? 'owner'),
                    'notes'            => 'From demand plan ' . ($plan->plan_no ?: $plan->id) . ($plan->plan_date ? (' · ' . Carbon::parse($plan->plan_date)->format('d M Y')) : ''),
                ]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'shipment_failed', 'message' => 'Could not create the shipment: ' . $e->getMessage()];
            }
            $plan->shipment_id = $ship->id;
            if (! $plan->shipment_ref) { $plan->shipment_ref = $ship->shipment_number; }
            $plan->save();
        }

        $added = 0; $updated = 0;
        foreach ($lines as $l) {
            $prod = \App\Models\Product::where('tenant_id', $t)->find((int) $l->product_id);
            $unitCost = $prod && $prod->cost_price !== null ? (float) $prod->cost_price : null;
            $unitPrice = $prod ? (float) $prod->price : null;
            // Ship what was LOADED (qty_sent). If sent wasn't recorded, fall back to purchased so the
            // simple flow (buy → ship) still works without an explicit "load" step.
            $shipQty = (float) $l->qty_sent > 0 ? (float) $l->qty_sent : (float) $l->qty_purchased;

            if ($l->consignment_id) {
                $cs = \App\Models\ConsignmentStock::where('tenant_id', $t)->find((int) $l->consignment_id);
                if ($cs) {
                    $cs->qty_requested = (float) $l->requested_qty;
                    $cs->qty_sent = $shipQty;
                    $cs->qty_received = (float) $l->received_qty;
                    $cs->save();
                    $updated++;
                    continue;
                }
            }

            $cs = \App\Models\ConsignmentStock::create([
                'tenant_id' => $t, 'shipment_id' => $ship->id, 'product_id' => (int) $l->product_id, 'name' => (string) $l->name,
                'qty_requested' => (float) $l->requested_qty, 'qty_sent' => $shipQty,
                'qty_received' => (float) $l->received_qty, 'qty_sold' => 0,
                'unit_cost' => $unitCost, 'unit_price' => $unitPrice,
                'note' => 'From demand plan ' . ($plan->plan_no ?: $plan->id),
            ]);
            $l->shipment_id = $ship->id;
            $l->consignment_id = $cs->id;
            $l->save();
            $added++;
        }

        return ['ok' => true, 'message' => 'Shipment ' . $ship->shipment_number . ' — ' . $added . ' item(s) added' . ($updated ? (', ' . $updated . ' updated') : '') . '. Manage dispatch in Consignments.'];
    }

    private function removeLine(CapabilityContext $ctx, array $in): array
    {
        $l = $this->line($ctx, $in);
        if (! $l) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $name = (string) $l->name;
        $l->delete();

        return ['ok' => true, 'message' => 'Removed ' . $name . '.'];
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }

    private function lineLabel(string $s): string
    {
        return match ($s) {
            'pending' => 'Pending',
            'partial' => 'Partial',
            'purchased' => 'Purchased',
            'not_available' => 'Not available',
            default => ucfirst($s ?: 'pending'),
        };
    }

    private function lineBadge(string $s): string
    {
        return match ($s) {
            'purchased' => 'ok',
            'partial' => 'warn',
            'not_available' => 'danger',
            default => 'default',
        };
    }

    private function planLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'in_procurement' => 'In procurement',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($s ?: 'draft'),
        };
    }
}
