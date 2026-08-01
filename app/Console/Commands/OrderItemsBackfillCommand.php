<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Services\Sales\OrderItemSync;
use Illuminate\Console\Command;

/**
 * One-time repair for history (v4.6.4): every order that carries an items_json blob but has ZERO
 * order_items rows gets its rows built by OrderItemSync — after which the V3 order screen shows
 * the lines, COGS/margin computes, and analytics/demand planning finally see those sales.
 *
 * Affected creation paths (before v4.6.4): quotation conversion, panel save-order, AI-bot orders.
 * POS and legacy BotBrain orders already had rows and are naturally skipped (rows exist).
 *
 * Safe: dry-run unless --write; touches ONLY orders with zero existing rows (never rebuilds rows
 * that BotBrain wrote with restaurant modifiers); idempotent — a second run finds nothing.
 *
 *   php artisan orders:items-backfill --tenant=17            # preview
 *   php artisan orders:items-backfill --tenant=17 --write    # apply
 */
class OrderItemsBackfillCommand extends Command
{
    protected $signature = 'orders:items-backfill {--tenant= : Tenant id (required)} {--write : Apply (default: dry-run preview)}';

    protected $description = 'Build missing order_items rows from items_json for orders that have none. Dry-run unless --write.';

    public function handle(): int
    {
        $tid = (int) $this->option('tenant');
        if ($tid <= 0) {
            $this->error('Pass --tenant=<id> (e.g. --tenant=17).');

            return self::FAILURE;
        }
        $tenant = Tenant::find($tid);
        if (! $tenant) {
            $this->error("Tenant {$tid} not found.");

            return self::FAILURE;
        }
        $write = (bool) $this->option('write');
        $sync  = app(OrderItemSync::class);

        $withRows = OrderItem::withoutGlobalScopes()->where('tenant_id', $tid)
            ->distinct()->pluck('order_id')->all();

        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tid)
            ->whereNotNull('items_json')
            ->when($withRows, fn ($q) => $q->whereNotIn('id', $withRows))
            ->orderBy('id')
            ->get();

        $fixed = 0; $empty = 0; $rows = 0;
        foreach ($orders as $o) {
            $lines = is_array($o->items_json) ? $o->items_json : (json_decode((string) $o->items_json, true) ?: []);
            $count = count(array_filter($lines, fn ($l) => is_array($l) && trim((string) ($l['name'] ?? '')) !== ''));
            if ($count === 0) {
                $empty++;
                continue;
            }
            $this->line(sprintf('%-10s %-12s %2d line(s)  %s', $write ? 'BUILT' : 'WOULD BUILD', (string) $o->order_no, $count, mb_substr((string) $o->items_text, 0, 60)));
            if ($write) {
                try {
                    $rows += $sync->sync($tenant, $o);
                } catch (\Throwable $e) {
                    $this->warn('  failed on ' . $o->order_no . ': ' . $e->getMessage());
                    continue;
                }
            }
            $fixed++;
        }

        $this->info(($write ? 'Built rows for ' : 'Would build rows for ') . $fixed . ' order(s)'
            . ($write ? " ({$rows} line rows written)" : '') . '; ' . $empty . ' had an empty blob; orders that already have rows were skipped.');
        if (! $write && $fixed > 0) {
            $this->comment('Preview only — re-run with --write to apply.');
        }

        return self::SUCCESS;
    }
}
