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

        $totRequested = 0.0; $totPurchased = 0.0; $pending = 0; $partial = 0; $purchased = 0; $notAvail = 0;
        $rows = [];
        foreach ($lines as $l) {
            $req = (float) $l->requested_qty;
            $got = (float) $l->qty_purchased;
            $totRequested += $req;
            $totPurchased += min($req, $got);
            $st = (string) $l->line_status;
            if ($st === 'pending') { $pending++; }
            elseif ($st === 'partial') { $partial++; }
            elseif ($st === 'purchased') { $purchased++; }
            elseif ($st === 'not_available') { $notAvail++; }

            $btns = [];
            if ($canEdit && $isOpen) {
                $btns[] = ['icon' => 'edit', 'title' => 'Edit plan figures', 'action' => [
                    'key' => 'edit_line', 'label' => 'Edit — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/edit_line', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['current_stock' => 'number', 'expected_sales' => 'number', 'requested_qty' => 'number'],
                    'labels' => ['current_stock' => 'Current stock (Kampala + Juba)', 'expected_sales' => 'Expected sales', 'requested_qty' => 'Required quantity'],
                    'prefill' => ['current_stock' => (float) $l->current_stock, 'expected_sales' => (float) $l->expected_sales, 'requested_qty' => $req],
                    'body' => ['line_id' => $l->id],
                ]];
                $btns[] = ['icon' => 'cart', 'title' => 'Record procurement', 'action' => [
                    'key' => 'record_procurement', 'label' => 'Procure — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/record_procurement', 'actor' => 'human', 'tone' => 'primary',
                    'schema' => ['qty_available' => 'number', 'qty_purchased' => 'number', 'supplier' => $supplierCombo, 'expected_delivery' => 'text', 'line_status' => 'enum:pending,partial,purchased,not_available', 'remarks' => 'text'],
                    'labels' => ['qty_available' => 'Qty available at supplier', 'qty_purchased' => 'Qty purchased', 'supplier' => 'Supplier', 'expected_delivery' => 'Expected delivery (YYYY-MM-DD)', 'line_status' => 'Line status', 'remarks' => 'Remarks'],
                    'prefill' => ['qty_purchased' => $got, 'qty_available' => $l->qty_available !== null ? (float) $l->qty_available : '', 'supplier' => (string) ($l->supplier_name ?: ''), 'expected_delivery' => $l->expected_delivery ? Carbon::parse($l->expected_delivery)->format('Y-m-d') : '', 'line_status' => $st, 'remarks' => (string) ($l->remarks ?: '')],
                    'body' => ['line_id' => $l->id],
                ]];
                if ((float) $l->qty_purchased > 0) {
                    $btns[] = ['icon' => 'download', 'title' => 'Receive in Juba', 'action' => [
                        'key' => 'receive_goods', 'label' => 'Receive in Juba — ' . $l->name, 'method' => 'POST', 'endpoint' => $endpoint . '/receive_goods', 'actor' => 'human', 'tone' => 'primary',
                        'schema' => ['received_qty' => 'number', 'damaged_qty' => 'number', 'remarks' => 'text'],
                        'labels' => ['received_qty' => 'Total received in Juba', 'damaged_qty' => 'Damaged (of received)', 'remarks' => 'Note'],
                        'prefill' => ['received_qty' => (float) $l->received_qty, 'damaged_qty' => (float) $l->damaged_qty, 'remarks' => (string) ($l->remarks ?: '')],
                        'body' => ['line_id' => $l->id],
                    ]];
                }
                $btns[] = ['icon' => 'trash', 'title' => 'Remove line', 'tone' => 'danger', 'post' => $endpoint . '/remove_line', 'body' => ['line_id' => $l->id], 'confirm' => 'Remove ' . $l->name . ' from this plan?'];
            }

            $shortfall = max(0.0, $req - $got);
            $rows[] = [
                'product'  => ['value' => (string) $l->name, 'sub' => $l->product_id ? ('#' . $l->product_id) : 'ad-hoc', 'avatar' => false],
                'current'  => ['value' => $this->qty($l->current_stock), 'sort' => (float) $l->current_stock],
                'transit'  => ['value' => (float) $l->in_transit > 0 ? $this->qty($l->in_transit) : '—', 'sort' => (float) $l->in_transit],
                'expected' => ['value' => $this->qty($l->expected_sales), 'sort' => (float) $l->expected_sales],
                'suggested'=> ['value' => $this->qty($l->suggested_qty), 'sort' => (float) $l->suggested_qty],
                'requested'=> ['value' => $this->qty($req), 'sort' => $req],
                'purchased'=> ['value' => $got > 0 ? $this->qty($got) : '—', 'sort' => $got, 'tone' => ($got >= $req && $req > 0) ? 'ok' : ($got > 0 ? 'warn' : 'default')],
                'received' => ['value' => (float) $l->received_qty > 0 ? $this->qty($l->received_qty) : '—', 'sort' => (float) $l->received_qty, 'tone' => ((float) $l->received_qty >= $got && $got > 0) ? 'ok' : ((float) $l->received_qty > 0 ? 'warn' : 'default')],
                'shortfall'=> ['value' => $shortfall > 0 && $st !== 'not_available' ? $this->qty($shortfall) : '—', 'sort' => $shortfall, 'tone' => $shortfall > 0 && $st !== 'not_available' ? 'danger' : 'default'],
                'supplier' => (string) ($l->supplier_name ?: '—'),
                'status'   => ['value' => $this->lineLabel($st), 'badge' => $this->lineBadge($st)],
                'act'      => ['buttons' => $btns],
            ];
        }

        $columns = [
            ['key' => 'product',  'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'current',  'label' => 'Stock', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'transit',  'label' => 'In transit', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'expected', 'label' => 'Expected', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'suggested','label' => 'Suggested', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'requested','label' => 'Required', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'purchased','label' => 'Purchased', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'received', 'label' => 'Received', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'shortfall','label' => 'Short', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'supplier', 'label' => 'Supplier', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status', 'type' => 'badge', 'sortable' => true],
            ['key' => 'act',      'label' => '', 'type' => 'action', 'align' => 'right'],
        ];

        $pct = $totRequested > 0 ? (int) round(min(100, max(0, $totPurchased / $totRequested * 100))) : 0;

        // header actions by state
        $headerActions = [];
        if ($canEdit) {
            if ($plan->status === 'draft') {
                $headerActions[] = ['key' => 'submit', 'label' => 'Submit as Procurement Request', 'method' => 'POST', 'endpoint' => $endpoint . '/submit', 'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Submit this plan as a Procurement Request for purchasing?'];
            }
            if ($isOpen) {
                $headerActions[] = ['key' => 'add_line', 'label' => 'Add product', 'method' => 'POST', 'endpoint' => $endpoint . '/add_line', 'actor' => 'human', 'tone' => 'default',
                    'schema' => ['name' => 'text', 'requested_qty' => 'number'], 'labels' => ['name' => 'Product / item name', 'requested_qty' => 'Required quantity'], 'prefill' => []];
            }
        }
        $more = [];
        if ($canEdit && $isOpen) {
            $more[] = ['key' => 'complete', 'label' => 'Mark plan completed', 'method' => 'POST', 'endpoint' => $endpoint . '/complete', 'confirm' => 'Mark this procurement plan as completed?'];
            $more[] = ['key' => 'cancel', 'label' => 'Cancel plan', 'method' => 'POST', 'endpoint' => $endpoint . '/cancel', 'tone' => 'danger', 'confirm' => 'Cancel this plan? Lines are kept for reference.'];
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'demandPlan', 'id' => (string) $plan->id, 'ref' => (string) ($plan->plan_no ?: $plan->id),
                'title' => (string) ($plan->plan_no ?: ('Plan ' . $plan->id)),
                'subtitle' => trim(implode(' · ', array_filter([(string) $plan->title, $plan->shipment_ref ? ('Shipment ' . $plan->shipment_ref) : '']))),
                'status' => $this->planLabel((string) $plan->status),
            ])
            ->headline($pct . '% purchased', $lines->count() . ' lines · ' . $this->qty($totRequested) . ' required', $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : 'default'))
            ->financials([
                ['key' => 'lines', 'label' => 'Lines', 'value' => $lines->count(), 'foot' => 'products on plan'],
                ['key' => 'pending', 'label' => 'To buy', 'value' => $pending + $partial, 'tone' => ($pending + $partial) > 0 ? 'warn' : 'ok', 'foot' => 'pending / partial'],
                ['key' => 'purchased', 'label' => 'Purchased', 'value' => $purchased, 'tone' => 'ok', 'foot' => 'lines fully bought'],
                ['key' => 'notavail', 'label' => 'Not available', 'value' => $notAvail, 'tone' => $notAvail > 0 ? 'danger' : 'ok', 'foot' => 'sourcing gap'],
            ])
            ->tabs([['key' => 'lines', 'label' => 'Lines', 'count' => $lines->count()]])
            ->tabSections(['lines' => [[
                'kind' => 'table', 'title' => 'Procurement lines', 'sub' => $this->planLabel((string) $plan->status) . ' · ' . $stockNote,
                'searchable' => true, 'columns' => $columns, 'rows' => $rows,
                'empty' => 'No lines yet — use “Add product”, or create the plan with auto-suggest on.',
            ]]])
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
            'receive_goods' => $this->receiveGoods($ctx, $input),
            'remove_line' => $this->removeLine($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
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
            return ['ok' => false, 'error' => 'bad_input', 'message' => 'Enter a product name.'];
        }
        DemandPlanLine::create([
            'tenant_id' => $ctx->tenantId(), 'demand_plan_id' => $plan->id,
            'name' => $name, 'requested_qty' => round((float) ($in['requested_qty'] ?? 0), 3),
            'line_status' => 'pending',
        ]);

        return ['ok' => true, 'message' => 'Added ' . $name . '.'];
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
        $prev = (float) $l->received_qty;
        $now = array_key_exists('received_qty', $in) && $in['received_qty'] !== '' ? max(0.0, round((float) $in['received_qty'], 3)) : $prev;
        $delta = round($now - $prev, 3);

        $l->received_qty = $now;
        if (array_key_exists('damaged_qty', $in) && $in['damaged_qty'] !== '') { $l->damaged_qty = max(0.0, round((float) $in['damaged_qty'], 3)); }
        if (array_key_exists('remarks', $in) && trim((string) $in['remarks']) !== '') { $l->remarks = trim((string) $in['remarks']); }
        $l->received_at = now();
        $l->save();

        $intoStock = false;
        if ($l->product_id && $delta > 0.0001) {
            try {
                \App\Services\Inventory\StockLedger::forTenant($ctx->tenant)->receive([
                    'product_id' => (int) $l->product_id,
                    'sku'        => null,
                    'location'   => \App\Services\Inventory\StockLedger::LOC_JUBA,
                    'qty'        => $delta,
                    'ref_type'   => 'procurement_receive',
                    'ref_id'     => (int) $l->id,
                    'note'       => 'Received in Juba: ' . $l->name,
                    'created_by' => (string) ($ctx->user->name ?? ''),
                ]);
                $intoStock = true;
            } catch (\Throwable $e) {
                // never lose the receipt record over a ledger error
            }
        }

        return ['ok' => true, 'message' => 'Received ' . $this->qty($now) . ' of ' . $l->name . ($intoStock ? ' — added to Juba stock.' : ($l->product_id ? '.' : ' (ad-hoc line — not linked to a product, so not added to stock).'))];
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
