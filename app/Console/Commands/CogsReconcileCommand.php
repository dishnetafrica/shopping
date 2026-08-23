<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Sales\CogsAudit;
use App\Services\Sales\OrderCosting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Phase 3A — READ-ONLY audit for the historical multi-currency COGS bug.
 *
 * Reconstructs, per delivered/ledger-posted order, what the old OrderLedgerService booked (cost_price×qty
 * as base, unconverted) vs the correct base-currency COGS, and reports the overstatement. It NEVER writes
 * to journals, orders, invoices, inventory, payments or balances — it only prints a report and (optionally)
 * exports a CSV/JSON for independent review. Remediation is the SEPARATE cogs:remediate command (Phase 3B).
 */
class CogsReconcileCommand extends Command
{
    protected $signature = 'cogs:reconcile
        {--tenant= : limit to one tenant id or slug}
        {--limit=5000 : max orders to scan per tenant}
        {--csv= : write the per-order report to this CSV path (else a default under storage/app)}
        {--json : also print the summary as JSON}';

    protected $description = 'READ-ONLY audit of historical COGS currency overstatement (writes nothing).';

    public function handle(): int
    {
        $only = (string) $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));

        $q = Tenant::query()->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        $allRows = [];
        foreach ($q->get() as $t) {
            $base = strtoupper((string) $t->setting('currency', 'UGX'));
            $costCur = OrderCosting::COST_CURRENCY;
            $fx = Fx::forTenant($t);

            // Complete (delivered or customer-confirmed) orders — the ones OrderLedgerService would have posted.
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where(fn ($w) => $w->whereNotNull('delivered_at')->orWhereNotNull('customer_confirmed_at'))
                ->orderByDesc('id')->limit($limit)
                ->get(['id', 'order_no', 'total', 'sell_currency', 'created_at', 'delivered_at', 'customer_confirmed_at']);
            if ($orders->isEmpty()) { continue; }

            // COGS native per order (cost_price × qty), batched.
            $items = OrderItem::withoutGlobalScopes()->whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'product_id', 'qty']);
            $cost = Product::withoutGlobalScopes()->whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('cost_price', 'id');
            $cogsByOrder = [];
            foreach ($items as $it) {
                $cogsByOrder[$it->order_id] = ($cogsByOrder[$it->order_id] ?? 0.0) + (float) ($cost[$it->product_id] ?? 0) * (float) $it->qty;
            }

            foreach ($orders as $o) {
                $date = ($o->delivered_at ?: $o->created_at) ? Carbon::parse($o->delivered_at ?: $o->created_at)->format('Y-m-d') : null;
                // Rate resolver — null when a rate can't be established (never invents one).
                $toBase = function (float $amt, string $from) use ($fx, $base, $date): ?float {
                    if (strtoupper($from) === strtoupper($base)) { return round($amt, 2); }
                    $rFrom = $fx->ratePerUsd($from, $date);
                    $rBase = $fx->ratePerUsd($base, $date);
                    if ($rFrom <= 0 || $rBase <= 0) { return null; }
                    return round($amt / $rFrom * $rBase, 2);
                };
                $row = CogsAudit::assess([
                    'id' => (int) $o->id, 'order_no' => (string) $o->order_no, 'date' => $date, 'delivered' => (string) $o->delivered_at,
                    'sell_currency' => (string) $o->sell_currency, 'base_currency' => $base, 'cost_currency' => $costCur,
                    'sales' => (float) $o->total, 'cogs_native' => (float) ($cogsByOrder[$o->id] ?? 0),
                ], $toBase);
                $row['tenant_id'] = (int) $t->id;
                $allRows[] = $row;
            }
        }

        // --- summary ---
        $affected = array_values(array_filter($allRows, fn ($r) => $r['status'] === CogsAudit::REMEDIABLE));
        $requires = array_values(array_filter($allRows, fn ($r) => $r['status'] === CogsAudit::REQUIRES));
        $overTotal = round(array_sum(array_map(fn ($r) => (float) ($r['cogs_overstatement'] ?? 0), $affected)), 2);
        $postedCogs = round(array_sum(array_map(fn ($r) => (float) $r['posted_cogs_base'], $affected)), 2);
        $correctCogs = round(array_sum(array_map(fn ($r) => (float) ($r['correct_cogs_base'] ?? 0), $affected)), 2);

        $this->line('');
        $this->info('COGS reconciliation — READ ONLY (nothing was written)');
        $this->line('Orders scanned            : ' . count($allRows));
        $this->line('Automatically remediable  : ' . count($affected));
        $this->line('Requires reconciliation   : ' . count($requires) . '  (missing historical FX — never invented)');
        $this->line('Not affected              : ' . (count($allRows) - count($affected) - count($requires)));
        $this->line('Already corrected         : 0  (remediation not yet run — Phase 3B)');
        $this->line('Posted COGS (affected)    : ' . number_format($postedCogs, 2));
        $this->line('Correct COGS (affected)   : ' . number_format($correctCogs, 2));
        $this->line('COGS overstatement        : ' . number_format($overTotal, 2) . '  = expected profit correction');
        $this->line('');

        // top offenders
        usort($affected, fn ($a, $b) => ($b['cogs_overstatement'] ?? 0) <=> ($a['cogs_overstatement'] ?? 0));
        foreach (array_slice($affected, 0, 15) as $r) {
            $this->line(sprintf('  %-10s  sales %s %-10s  cost %s %-14s  → COGS %s vs posted %s  (over %s)  margin %s%%',
                $r['order_no'], $r['sell_currency'], number_format($r['sales'], 2),
                $r['cost_currency'], number_format($r['cost_native']),
                number_format((float) $r['correct_cogs_base'], 2), number_format((float) $r['incorrect_cogs_base'], 2),
                number_format((float) $r['cogs_overstatement'], 2), $r['correct_gross_margin_pct'] ?? '—'));
        }
        if ($requires) {
            $this->line('');
            $this->warn('  ' . count($requires) . ' order(s) flagged REQUIRES_RECONCILIATION (excluded from auto-remediation).');
        }

        // --- machine-readable export (a report file only; not business data) ---
        $csvPath = (string) ($this->option('csv') ?: storage_path('app/cogs_reconcile_' . now()->format('Ymd_His') . '.csv'));
        $this->writeCsv($csvPath, $allRows);
        $this->line('');
        $this->info('CSV report: ' . $csvPath);

        if ($this->option('json')) {
            $this->line(json_encode([
                'scanned' => count($allRows), 'remediable' => count($affected), 'requires' => count($requires),
                'cogs_overstatement' => $overTotal, 'posted_cogs' => $postedCogs, 'correct_cogs' => $correctCogs,
            ], JSON_PRETTY_PRINT));
        }

        $this->line('');
        $this->comment('Read-only. To correct the books, review this report, then run the separate cogs:remediate --confirm (Phase 3B).');
        return self::SUCCESS;
    }

    /** Write the per-order report to CSV. A report file — no business data is modified. */
    private function writeCsv(string $path, array $rows): void
    {
        $cols = ['tenant_id', 'order_id', 'order_no', 'date', 'delivered', 'sell_currency', 'sales',
            'posted_revenue_base', 'correct_revenue_base', 'cost_currency', 'cost_native',
            'incorrect_cogs_base', 'correct_cogs_base', 'cogs_overstatement', 'correct_gross_profit',
            'correct_gross_margin_pct', 'journal_ref', 'requires_compensating', 'fx_available', 'status'];
        $fh = @fopen($path, 'w');
        if (! $fh) { $this->warn('Could not write CSV to ' . $path); return; }
        fputcsv($fh, $cols);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($c) => $r[$c] ?? '', $cols));
        }
        fclose($fh);
    }
}
