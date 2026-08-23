<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY report: products that were SOLD in completed orders but have no cost_price.
 *
 * These are why gross margin reads unrealistically high after the COGS remediation — a sale with no
 * recorded cost posts zero COGS, so its full revenue drops to gross profit. This lists the offenders
 * ranked by REVENUE IMPACT (Σ price×qty), so the highest-leverage products to cost are at the top.
 * It writes nothing — set the missing cost_price values in the panel, then re-run cogs:reconcile.
 */
class CogsMissingCostCommand extends Command
{
    protected $signature = 'cogs:missing-cost
        {--tenant= : limit to one tenant id or slug}
        {--limit=5000 : max orders to scan per tenant}
        {--top=40 : how many rows to print (CSV always holds all)}
        {--csv= : write the full list to this CSV path (else a default under storage/app)}
        {--json : also print the summary as JSON}';

    protected $description = 'READ-ONLY: products sold in completed orders with no cost_price, ranked by revenue impact.';

    public function handle(): int
    {
        $only = (string) $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));
        $top = max(1, (int) $this->option('top'));

        $q = Tenant::query()->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        $rows = [];
        foreach ($q->get() as $t) {
            $hasConfirmed = Schema::hasColumn('orders', 'customer_confirmed_at');
            $orderIds = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where(function ($w) use ($hasConfirmed) {
                    $w->whereNotNull('delivered_at');
                    if ($hasConfirmed) { $w->orWhereNotNull('customer_confirmed_at'); }
                })
                ->orderByDesc('id')->limit($limit)->pluck('id');
            if ($orderIds->isEmpty()) { continue; }

            $items = OrderItem::withoutGlobalScopes()->whereIn('order_id', $orderIds)
                ->get(['order_id', 'product_id', 'name', 'price', 'qty']);
            $pids = $items->pluck('product_id')->filter()->unique();
            if ($pids->isEmpty()) { continue; }

            // Products sold here that have no usable cost.
            $noCost = Product::withoutGlobalScopes()->whereIn('id', $pids)->get(['id', 'name', 'cost_price'])
                ->filter(fn ($p) => (float) ($p->cost_price ?? 0) <= 0)->keyBy('id');
            if ($noCost->isEmpty()) { continue; }

            $agg = [];
            foreach ($items as $it) {
                if (! isset($noCost[$it->product_id])) { continue; }
                $k = $it->product_id;
                if (! isset($agg[$k])) { $agg[$k] = ['units' => 0.0, 'revenue' => 0.0, 'orders' => []]; }
                $agg[$k]['units']   += (float) $it->qty;
                $agg[$k]['revenue'] += (float) $it->price * (float) $it->qty;
                $agg[$k]['orders'][$it->order_id] = true;
            }

            foreach ($agg as $pid => $a) {
                $p = $noCost[$pid];
                $rows[] = [
                    'tenant_id' => (int) $t->id,
                    'product_id' => (int) $pid,
                    'product' => (string) ($p->name ?: ($items->firstWhere('product_id', $pid)->name ?? '?')),
                    'units_sold' => round($a['units'], 2),
                    'orders' => count($a['orders']),
                    'revenue' => round($a['revenue'], 2),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $this->line('');
        $this->info('Products sold in completed orders with NO cost_price (READ ONLY)');
        $this->line('Products : ' . count($rows));
        $this->line('Revenue with untracked cost : ' . number_format(array_sum(array_column($rows, 'revenue')), 2));
        $this->line('');
        printf("  %-7s %-40s %10s %8s %14s\n", 'id', 'product', 'units', 'orders', 'revenue');
        foreach (array_slice($rows, 0, $top) as $r) {
            printf("  %-7d %-40s %10s %8d %14s\n",
                $r['product_id'], mb_substr($r['product'], 0, 40),
                rtrim(rtrim(number_format($r['units_sold'], 2), '0'), '.'), $r['orders'], number_format($r['revenue'], 2));
        }

        $csv = (string) ($this->option('csv') ?: storage_path('app/cogs_missing_cost_' . now()->format('Ymd_His') . '.csv'));
        $fh = @fopen($csv, 'w');
        if ($fh) {
            fputcsv($fh, ['tenant_id', 'product_id', 'product', 'units_sold', 'orders', 'revenue']);
            foreach ($rows as $r) { fputcsv($fh, $r); }
            fclose($fh);
            $this->line('');
            $this->info('CSV: ' . $csv);
        } else {
            $this->warn('Could not write CSV to ' . $csv);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['products' => count($rows),
                'revenue_untracked' => round(array_sum(array_column($rows, 'revenue')), 2)], JSON_PRETTY_PRINT));
        }

        $this->line('');
        $this->comment('Set cost_price (UGX) on these products, then re-run: php artisan cogs:reconcile --tenant=' . ($only ?: '<id>'));
        return self::SUCCESS;
    }
}
