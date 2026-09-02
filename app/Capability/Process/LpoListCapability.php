<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * Local Purchase Orders (LPO) — the list of ordering documents to suppliers. A PO starts as a draft
 * (add lines), is sent to the supplier (optionally on WhatsApp), and is worked to "received" as goods
 * arrive (full or partial per line). Read-only projection; the only write here is creating a new PO.
 * No money or stock moves on this screen.
 */
final class LpoListCapability implements ProcessCapability
{
    private const MAX_ROWS = 300;

    public function key(): string { return 'lpos'; }

    public function permission(): string { return 'menu:purchases'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $orders = PurchaseOrder::where('tenant_id', $t)
            ->withCount('lines')
            ->orderByRaw('order_date DESC NULLS LAST')->orderByDesc('id')->limit(self::MAX_ROWS)->get();

        // one grouped query for per-order fulfilment (ordered vs received) + expected value
        $progress = PurchaseOrderLine::where('tenant_id', $t)
            ->selectRaw('purchase_order_id')
            ->selectRaw('COALESCE(SUM(ordered_qty),0) as ordered')
            ->selectRaw('COALESCE(SUM(LEAST(ordered_qty, received_qty)),0) as got')
            ->selectRaw('COALESCE(SUM(ordered_qty * COALESCE(unit_cost,0)),0) as value')
            ->groupBy('purchase_order_id')->get()->keyBy('purchase_order_id');

        $draft = 0; $open = 0; $received = 0;
        $rows = [];
        foreach ($orders as $o) {
            $pr = $progress[$o->id] ?? null;
            $ordered = (float) ($pr->ordered ?? 0);
            $got = (float) ($pr->got ?? 0);
            $pct = $ordered > 0 ? (int) round(min(100, max(0, $got / $ordered * 100))) : 0;

            $status = (string) $o->status;
            if ($status === 'draft') { $draft++; }
            if (in_array($status, ['sent', 'partial'], true)) { $open++; }
            if ($status === 'received') { $received++; }

            $od = $o->order_date ? Carbon::parse($o->order_date) : ($o->created_at ? Carbon::parse($o->created_at) : null);
            $rows[] = [
                'date'     => ['value' => $od ? $od->format('d M Y') : '—', 'sort' => $od ? $od->timestamp : 0],
                'po'       => ['value' => (string) ($o->po_no ?: ('LPO-' . $o->id)), 'avatar' => false],
                'supplier' => (string) ($o->supplier_name ?: '—'),
                'lines'    => ['value' => (string) $o->lines_count, 'sort' => (int) $o->lines_count],
                'received' => ['value' => $pct . '%', 'sort' => $pct, 'tone' => $pct >= 100 ? 'ok' : ($pct > 0 ? 'warn' : 'default')],
                'status'   => ['value' => $this->statusLabel($status), 'badge' => $this->statusBadge($status)],
                'link'     => '/api/v1/workspace/lpo/' . $o->id,
            ];
        }

        $columns = [
            ['key' => 'date',     'label' => 'Date',     'sortable' => true],
            ['key' => 'po',       'label' => 'LPO',      'type' => 'avatar', 'sortable' => true],
            ['key' => 'supplier', 'label' => 'Supplier', 'sortable' => true],
            ['key' => 'lines',    'label' => 'Lines',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'received', 'label' => 'Received', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',   'label' => 'Status',   'type' => 'badge', 'sortable' => true],
        ];

        // supplier pick-list for the "New LPO" form (pick existing or type a new one)
        $suppliers = Supplier::where('tenant_id', $t)->orderBy('name')->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
        $supplierCombo = 'combo:' . (count($suppliers) ? implode('|', $suppliers) : 'Supplier');
        $defCur = $ctx->nativeCurrency();

        return (new WorkspacePayload)
            ->object(['type' => 'list:lpos', 'title' => 'Purchase Orders', 'subtitle' => $orders->count() . ' LPOs'])
            ->financials([
                ['key' => 'draft', 'label' => 'Draft', 'value' => $draft, 'tone' => $draft > 0 ? 'warn' : 'ok', 'foot' => 'not sent yet'],
                ['key' => 'open', 'label' => 'Awaiting delivery', 'value' => $open, 'tone' => $open > 0 ? 'warn' : 'ok', 'foot' => 'sent / part-received'],
                ['key' => 'received', 'label' => 'Received', 'value' => $received, 'tone' => 'ok', 'foot' => 'fully delivered'],
                ['key' => 'total', 'label' => 'LPOs', 'value' => $orders->count(), 'foot' => 'all time'],
            ])
            ->table($columns, $rows, [['key' => 'status', 'label' => 'Status']])
            ->actions([[
                'key' => 'create', 'label' => 'New LPO', 'method' => 'POST',
                'endpoint' => '/api/v1/workspace/lpos/_/create', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['supplier' => $supplierCombo, 'order_date' => 'date', 'expected_date' => 'date', 'currency' => 'enum:UGX,USD,SSP', 'delivery_address' => 'text', 'notes' => 'text'],
                'labels' => ['supplier' => 'Supplier (pick or type a new one)', 'order_date' => 'Order date (YYYY-MM-DD)', 'expected_date' => 'Expected delivery (optional)', 'currency' => 'Currency', 'delivery_address' => 'Deliver to (optional)', 'notes' => 'Notes (optional)'],
                'prefill' => ['order_date' => now()->format('Y-m-d'), 'currency' => $defCur],
            ]])
            ->meta(['capability' => 'lpos', 'ttl' => 30, 'permissions' => ['menu:purchases']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();

        return [
            'open' => PurchaseOrder::where('tenant_id', $t)->whereIn('status', ['draft', 'sent', 'partial'])->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'create' => $this->create($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function create(CapabilityContext $ctx, array $in): array
    {
        if (! $ctx->can('menu:purchases')) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $t = $ctx->tenantId();

        $supplierName = trim((string) ($in['supplier'] ?? ''));
        if ($supplierName === '') {
            return ['ok' => false, 'error' => 'supplier_required', 'message' => 'Pick or add a supplier for the order.'];
        }
        $sup = $this->resolveSupplier($ctx, $supplierName);

        $orderDate = now();
        $raw = trim((string) ($in['order_date'] ?? ''));
        if ($raw !== '') {
            try { $orderDate = Carbon::parse($raw); } catch (\Throwable $e) { $orderDate = now(); }
        }
        $expected = null;
        $rawExp = trim((string) ($in['expected_date'] ?? ''));
        if ($rawExp !== '') {
            try { $expected = Carbon::parse($rawExp)->toDateString(); } catch (\Throwable $e) { $expected = null; }
        }
        $cur = strtoupper(trim((string) ($in['currency'] ?? ''))) ?: $ctx->nativeCurrency();

        $po = new PurchaseOrder();
        $po->tenant_id     = $t;
        $po->supplier_id   = $sup ? (int) $sup->id : null;
        $po->supplier_name = $sup ? (string) $sup->name : $supplierName;
        $po->status        = 'draft';
        $po->order_date    = $orderDate->toDateString();
        $po->expected_date = $expected;
        $po->currency      = $cur;
        $po->delivery_address = trim((string) ($in['delivery_address'] ?? '')) ?: null;
        $po->notes         = trim((string) ($in['notes'] ?? '')) ?: null;
        $po->terms         = trim((string) $ctx->tenant->setting('lpo_terms', '')) ?: null;
        $po->created_by    = (string) ($ctx->user->name ?? '');
        $po->save();
        $po->po_no = 'LPO-' . $orderDate->format('Y') . '-' . str_pad((string) $po->id, 4, '0', STR_PAD_LEFT);
        $po->save();

        return ['ok' => true, 'id' => $po->id, 'message' => 'Created ' . $po->po_no . ' for ' . $po->supplier_name . '. Add items to it.'];
    }

    /** Find a supplier by name, or create it inline (add a supplier straight from the New LPO form). */
    private function resolveSupplier(CapabilityContext $ctx, string $name): ?Supplier
    {
        $name = trim($name);
        if ($name === '') { return null; }
        $t = $ctx->tenantId();
        $sup = Supplier::where('tenant_id', $t)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if (! $sup) {
            try {
                $sup = new Supplier();
                $sup->tenant_id = $t;
                $sup->name = $name;
                $sup->active = true;
                $sup->save();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $sup;
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

    private function statusBadge(string $s): string
    {
        return match ($s) {
            'received', 'closed' => 'ok',
            'sent', 'partial' => 'warn',
            'cancelled' => 'danger',
            default => 'default',
        };
    }
}
