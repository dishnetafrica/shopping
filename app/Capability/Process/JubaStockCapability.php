<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Product;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Juba Stock — Ashish's live position, per product, in three states:
 *   • In Juba (available)  = real on-hand at the Juba location (receipts − sales) from the stock ledger
 *   • In transit           = sent from Kampala, not yet received (open shipments' consignment lines)
 *   • Purchased (to send)   = bought by Jayesh but not yet loaded onto a shipment (unallocated PR lines)
 * Read-only projection — it derives, never writes. Accurate once the stock ledger is on (Settings →
 * Stock tracking), because Juba sales only draw the ledger down when the ledger is being maintained.
 */
final class JubaStockCapability implements ProcessCapability
{
    public function key(): string { return 'juba.stock'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $juba    = $this->safeMap(fn () => StockLedger::forTenant($ctx->tenant)->onHandMap(StockLedger::LOC_JUBA));
        $kampala = $this->safeMap(fn () => StockLedger::forTenant($ctx->tenant)->onHandMap(StockLedger::LOC_KAMPALA));
        $transit = $this->transitMap($t);
        $toSend  = $this->purchasedToSendMap($t);

        // Union of every product that appears in any state, plus the catalogue for names.
        $ids = array_values(array_unique(array_merge(array_keys($juba), array_keys($transit), array_keys($toSend), array_keys($kampala))));
        $names = [];
        if ($ids) {
            $names = Product::where('tenant_id', $t)->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        $inJubaSkus = 0; $transitSkus = 0; $toSendSkus = 0; $outSkus = 0;
        $rows = [];
        foreach ($ids as $pid) {
            $av = round((float) ($juba[$pid] ?? 0), 3);
            $tr = round((float) ($transit[$pid] ?? 0), 3);
            $ts = round((float) ($toSend[$pid] ?? 0), 3);
            if ($av <= 0 && $tr <= 0 && $ts <= 0) { continue; }
            $pipeline = round($av + $tr + $ts, 3);

            if ($av > 0) { $inJubaSkus++; } elseif ($tr > 0 || $ts > 0) { $outSkus++; }
            if ($tr > 0) { $transitSkus++; }
            if ($ts > 0) { $toSendSkus++; }

            $rows[] = [
                'product'  => ['value' => (string) ($names[$pid] ?? ('#' . $pid)), 'sub' => 'Kampala: ' . $this->qty($kampala[$pid] ?? 0), 'avatar' => false],
                'juba'     => ['value' => $av > 0 ? $this->qty($av) : '0', 'sort' => $av, 'tone' => $av > 0 ? 'ok' : ($tr > 0 || $ts > 0 ? 'danger' : 'default')],
                'transit'  => ['value' => $tr > 0 ? $this->qty($tr) : '—', 'sort' => $tr, 'tone' => $tr > 0 ? 'warn' : 'default'],
                'tosend'   => ['value' => $ts > 0 ? $this->qty($ts) : '—', 'sort' => $ts, 'tone' => $ts > 0 ? 'warn' : 'default'],
                'pipeline' => ['value' => $this->qty($pipeline), 'sort' => $pipeline],
            ];
        }
        // Most relevant first: lowest available in Juba but with stock in the pipeline (at-risk), then by pipeline.
        usort($rows, fn ($a, $b) => [$a['juba']['sort'], -$b['pipeline']['sort']] <=> [$b['juba']['sort'], -$a['pipeline']['sort']]);

        $columns = [
            ['key' => 'product',  'label' => 'Product', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'juba',     'label' => 'In Juba (avail.)', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'transit',  'label' => 'In transit', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'tosend',   'label' => 'Purchased (to send)', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'pipeline', 'label' => 'Total pipeline', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $ledgerOn = (bool) $ctx->tenant->setting('stock_ledger', false);
        $note = $ledgerOn
            ? 'Available = real on-hand in Juba (receipts − sales) from the stock ledger.'
            : 'Turn on Settings → Stock tracking to make “In Juba” reflect real on-hand (receipts − sales).';

        return (new WorkspacePayload)
            ->object(['type' => 'process:juba.stock', 'title' => 'Juba Stock', 'subtitle' => $note])
            ->financials([
                ['key' => 'avail', 'label' => 'Available in Juba', 'value' => $inJubaSkus, 'tone' => 'ok', 'foot' => 'products in stock'],
                ['key' => 'transit', 'label' => 'In transit', 'value' => $transitSkus, 'tone' => $transitSkus > 0 ? 'warn' : 'ok', 'foot' => 'products on the way'],
                ['key' => 'tosend', 'label' => 'Purchased to send', 'value' => $toSendSkus, 'tone' => $toSendSkus > 0 ? 'warn' : 'ok', 'foot' => 'bought, not yet shipped'],
                ['key' => 'out', 'label' => 'Out in Juba', 'value' => $outSkus, 'tone' => $outSkus > 0 ? 'danger' : 'ok', 'foot' => 'zero on-hand, in pipeline'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['inTransit' => array_sum($this->transitMap($ctx->tenantId()))];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** Sent to Juba but not yet received, on open (not-arrived) shipments. @return array<int,float> */
    private function transitMap(int $tid): array
    {
        return $this->safeMap(function () use ($tid) {
            $rows = DB::table('consignment_stock')
                ->join('shipments', 'shipments.id', '=', 'consignment_stock.shipment_id')
                ->where('consignment_stock.tenant_id', $tid)
                ->whereNull('shipments.arrived_at')->whereNull('shipments.cancelled_at')
                ->whereNotNull('consignment_stock.product_id')
                ->select('consignment_stock.product_id', DB::raw('SUM(consignment_stock.qty_sent - consignment_stock.qty_received) as qty'))
                ->groupBy('consignment_stock.product_id')->get();
            $map = [];
            foreach ($rows as $r) { $map[(int) $r->product_id] = max(0.0, (float) $r->qty); }

            return $map;
        });
    }

    /** Purchased on a plan line but not yet loaded/sent (bought − sent, floored at 0). @return array<int,float> */
    private function purchasedToSendMap(int $tid): array
    {
        return $this->safeMap(function () use ($tid) {
            $rows = DB::table('demand_plan_lines')
                ->where('tenant_id', $tid)
                ->where('qty_purchased', '>', 0)
                ->whereNotNull('product_id')
                ->select('product_id', DB::raw('SUM(GREATEST(qty_purchased - qty_sent, 0)) as qty'))
                ->groupBy('product_id')->get();
            $map = [];
            foreach ($rows as $r) { $map[(int) $r->product_id] = max(0.0, (float) $r->qty); }

            return $map;
        });
    }

    /** @return array<int,float> */
    private function safeMap(callable $fn): array
    {
        try { return $fn() ?: []; } catch (\Throwable $e) { return []; }
    }

    private function qty($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }
}
