<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Sales\SaleFxAudit;
use CloudBSS\Kernel\Money\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY audit for the historical multi-currency REVENUE overstatement (sibling of cogs:reconcile).
 *
 * For each completed order, it reads the ACTUAL net revenue the kernel posted (cloudbss_events →
 * DeliveryCompleted payload `revenue`), reconstructs the native amount, converts it to the base
 * currency at the order's date, and reports the per-leg overstatement (Sales Revenue 4000, VAT Output
 * 2200, Accounts Receivable 1100). It writes nothing — remediation is a separate, gated command.
 *
 * The VAT rate mirrors the posting policy (UgandaVatSalesPolicy, 18% = 1800 bp). Missing historical FX
 * is flagged REQUIRES_RECONCILIATION and never invented.
 */
class SalesReconcileCommand extends Command
{
    /** Output VAT applied by UgandaVatSalesPolicy — keep in sync with the booted policy. */
    private const VAT_BP = 1800;

    protected $signature = 'sales:reconcile
        {--tenant= : limit to one tenant id or slug}
        {--limit=5000 : max orders to scan per tenant}
        {--top=30 : how many rows to print (CSV always holds all)}
        {--csv= : write the per-order report to this CSV path (else a default under storage/app)}
        {--json : also print the summary as JSON}';

    protected $description = 'READ-ONLY audit of historical REVENUE currency overstatement (writes nothing).';

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
            if (! (bool) $t->setting('ledger_enabled', false)) { continue; }

            $base   = strtoupper((string) $t->setting('currency', 'UGX'));
            $factor = Currency::of($base)->subunit();
            $fx     = Fx::forTenant($t);
            $ledgerTenant = (string) $t->setting('ledger_tenant', 't_' . $t->id);
            $ledgerEntity = (string) $t->setting('ledger_entity', 'e1');

            $hasConfirmed = Schema::hasColumn('orders', 'customer_confirmed_at');
            $cols = ['id', 'order_no', 'sell_currency', 'created_at', 'delivered_at'];
            if ($hasConfirmed) { $cols[] = 'customer_confirmed_at'; }
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where(function ($w) use ($hasConfirmed) {
                    $w->whereNotNull('delivered_at');
                    if ($hasConfirmed) { $w->orWhereNotNull('customer_confirmed_at'); }
                })
                ->orderByDesc('id')->limit($limit)->get($cols);
            if ($orders->isEmpty()) { continue; }

            $postedByOrder = $this->readPostedRevenue($ledgerTenant, $ledgerEntity, $orders->pluck('id')->all());
            if ($postedByOrder === null) {
                $this->warn("  Tenant {$t->id}: could not read the kernel event log (cloudbss_events). Skipped.");
                continue;
            }

            foreach ($orders as $o) {
                $date = ($o->delivered_at ?: $o->created_at) ? Carbon::parse($o->delivered_at ?: $o->created_at)->format('Y-m-d') : null;
                $toBaseMinor = $this->minorConverter($fx, $base, $factor, $date);
                $row = SaleFxAudit::assess([
                    'order_id' => (int) $o->id, 'order_no' => (string) $o->order_no, 'date' => $date,
                    'base_currency' => $base, 'sell_currency' => (string) $o->sell_currency,
                    'base_factor' => $factor, 'vat_bp' => self::VAT_BP,
                    'posted_net_minor' => $postedByOrder[$o->id] ?? null,
                ], $toBaseMinor);
                $row['tenant_id'] = (int) $t->id;
                $rows[] = $row;
            }
        }

        $by = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));
        $affected = array_values(array_filter($rows, fn ($r) => $r['status'] === SaleFxAudit::REMEDIABLE));
        $netOver   = round(array_sum(array_map(fn ($r) => (float) $r['net_overstatement'], $affected)), 2);
        $grossOver = round(array_sum(array_map(fn ($r) => (float) $r['gross_overstatement'], $affected)), 2);

        $this->line('');
        $this->info('REVENUE reconciliation — READ ONLY (nothing was written)');
        $this->line('Orders scanned            : ' . count($rows));
        $this->line('Remediable (overstated)   : ' . count($affected));
        $this->line('Requires reconciliation   : ' . $by(SaleFxAudit::REQUIRES) . '  (missing FX — never invented)');
        $this->line('Not affected              : ' . $by(SaleFxAudit::NOT_AFFECTED));
        $this->line('Not posted to kernel      : ' . $by(SaleFxAudit::NOT_POSTED));
        $this->line('Net revenue overstatement : ' . number_format($netOver, 2) . '  (account 4000)');
        $this->line('Gross / AR overstatement  : ' . number_format($grossOver, 2) . '  (account 1100; VAT 2200 = the difference)');
        $this->line('');

        usort($affected, fn ($a, $b) => ($b['net_over_minor'] ?? 0) <=> ($a['net_over_minor'] ?? 0));
        foreach (array_slice($affected, 0, $top) as $r) {
            $this->line(sprintf('  %-10s  %s  posted net %-14s → correct %-10s  (over net %s / vat %s / gross %s)',
                $r['order_no'], $r['sell_currency'],
                number_format((float) $r['posted_net'], 2), number_format((float) $r['correct_net'], 2),
                number_format((float) $r['net_overstatement'], 2), number_format((float) $r['vat_overstatement'], 2),
                number_format((float) $r['gross_overstatement'], 2)));
        }

        $csv = (string) ($this->option('csv') ?: storage_path('app/sales_reconcile_' . now()->format('Ymd_His') . '.csv'));
        $this->writeCsv($csv, $rows);
        $this->line('');
        $this->info('CSV report: ' . $csv);

        if ($this->option('json')) {
            $this->line(json_encode(['scanned' => count($rows), 'remediable' => count($affected),
                'net_overstatement' => $netOver, 'gross_overstatement' => $grossOver], JSON_PRETTY_PRINT));
        }

        $this->line('');
        $this->comment('Read-only. Compensating entries (Dr 4000 / Dr 2200 / Cr 1100) are a separate gated command, run only after a staging check.');
        return self::SUCCESS;
    }

    private function minorConverter(Fx $fx, string $base, int $factor, ?string $date): callable
    {
        return function (float $amt, string $from) use ($fx, $base, $factor, $date): ?int {
            if (strtoupper($from) === strtoupper($base)) { return (int) round($amt * $factor); }
            $rFrom = $fx->ratePerUsd($from, $date);
            $rBase = $fx->ratePerUsd($base, $date);
            if ($rFrom <= 0 || $rBase <= 0) { return null; }
            return (int) round($amt / $rFrom * $rBase * $factor);
        };
    }

    /** @return array<int,int>|null  order_id => posted net revenue (minor, base); null if the log can't be read. */
    private function readPostedRevenue(string $ledgerTenant, string $ledgerEntity, array $orderIds): ?array
    {
        try {
            $ids = array_map('strval', $orderIds);
            $sales = DB::connection('pgsql')->table('cloudbss_events')
                ->where('tenant_id', $ledgerTenant)->where('entity_id', $ledgerEntity)
                ->where('name', 'DeliveryCompleted')->whereIn('aggregate_id', $ids)
                ->get(['aggregate_id', 'payload']);
            $out = [];
            foreach ($sales as $r) {
                $p = json_decode((string) $r->payload, true) ?: [];
                if (! array_key_exists('revenue', $p)) { continue; }
                $out[(int) $r->aggregate_id] = (int) $p['revenue'];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function writeCsv(string $path, array $rows): void
    {
        $cols = ['tenant_id', 'order_id', 'order_no', 'date', 'sell_currency', 'posted_net', 'correct_net',
            'net_overstatement', 'vat_overstatement', 'gross_overstatement', 'correction_aggregate',
            'source_sale_aggregate', 'version', 'status'];
        $fh = @fopen($path, 'w');
        if (! $fh) { $this->warn('Could not write CSV to ' . $path); return; }
        fputcsv($fh, $cols);
        foreach ($rows as $r) { fputcsv($fh, array_map(fn ($c) => $r[$c] ?? '', $cols)); }
        fclose($fh);
    }
}
