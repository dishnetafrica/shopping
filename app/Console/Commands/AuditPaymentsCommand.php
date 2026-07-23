<?php

namespace App\Console\Commands;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Money\SalesBaseCurrency;
use Illuminate\Console\Command;

/**
 * READ-ONLY audit of settlement payments that were booked in the wrong currency by the pre-fix
 * recordPayment path (a $90 payment stored as "90 UGX" ≈ $0.02), which left invoices stuck on
 * part_paid. Writes NOTHING. It flags each suspect payment, groups them by order so duplicate
 * test-payments are visible, and shows each affected invoice's total vs. what the payments would
 * credit once corrected — so we can design a precise fix (not a blind bulk flip that over-pays).
 *
 *   php artisan cash:audit-payments --tenant=ID
 */
final class AuditPaymentsCommand extends Command
{
    protected $signature = 'cash:audit-payments
        {--tenant= : tenant id (required)}
        {--max-native=20000 : a native-currency payment at/below this is treated as a typed base-currency amount}';

    protected $description = 'READ-ONLY: list payments booked in the wrong currency and the invoices they left stuck (no writes).';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            return self::FAILURE;
        }
        $t = Tenant::find($id);
        if (! $t) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        // Money models (CustomerPayment) + Order/Invoice are tenant-scoped: without an active tenant
        // context the global scope would filter to `tenant_id IS NULL` and this audit would see nothing.
        app(\App\Support\TenantContext::class)->set($t->id);

        $base   = SalesBaseCurrency::baseCurrency($t);          // USD
        $native = SalesBaseCurrency::nativeCurrency($t);        // UGX
        $rate   = (float) (Fx::forTenant($t)->ratePerUsd($native) ?: 1);
        $maxNat = (float) $this->option('max-native');

        $this->newLine();
        $this->info("PAYMENT AUDIT — {$t->name} (tenant {$t->id})   [READ-ONLY]");
        $this->line("  base {$base} · local {$native} · rate " . number_format($rate, 0) . " {$native}/USD");
        $this->line("  A settlement payment is FLAGGED when its currency is {$native} but the amount is ≤ {$maxNat}");
        $this->line("  (real {$native} payments are large; a small one is a USD figure stored as {$native}).");
        $this->newLine();

        $pays = CustomerPayment::where('tenant_id', $t->id)->where('kind', 'settlement')
            ->orderBy('order_no')->orderBy('id')->get();
        if ($pays->isEmpty()) { $this->info('No settlement payments.'); return self::SUCCESS; }

        $flagged = [];
        foreach ($pays as $p) {
            $isNative = strtoupper((string) $p->currency) === $native;
            $suspect  = $isNative && (float) $p->amount > 0 && (float) $p->amount <= $maxNat;
            if ($suspect) $flagged[(string) $p->order_no][] = $p;
        }

        if (! $flagged) { $this->info('  ✓ No mis-booked settlement payments found.'); return self::SUCCESS; }

        $rows = [];
        foreach ($flagged as $orderNo => $ps) {
            $order = $orderNo !== '' ? Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first() : null;
            $inv   = $order ? Invoice::where('tenant_id', $t->id)->where('order_id', $order->id)->first() : null;
            $invCur = $inv ? SalesBaseCurrency::txnCurrency($inv, $t) : $native;
            $invTot = $inv ? (float) $inv->total : 0.0;

            // what these payments credit NOW (as booked) vs if corrected to base currency
            $creditNow = 0.0; $creditFixed = 0.0;
            foreach ($ps as $p) {
                $amt = (float) $p->amount;
                $creditNow   += strtoupper((string) $p->currency) === $invCur ? $amt : (float) $p->usd_amount * ($invCur === $base ? 1 : $rate);
                // corrected: treat the typed amount as base(USD); express in the invoice currency
                $creditFixed += $invCur === $base ? $amt : $amt * $rate;
                $rows[] = [
                    $orderNo ?: '—',
                    'CP-' . $p->id,
                    (string) $p->currency . ' ' . number_format($amt, 0),
                    '$' . number_format((float) $p->usd_amount, 2),
                    optional($p->created_at)->format('d M H:i'),
                    (string) ($p->note ?? ''),
                ];
            }
            $rows[] = ['  ↑ invoice', $inv ? $inv->invoice_no : '(none)',
                'total ' . number_format($invTot, 0) . ' ' . $invCur,
                'now +' . number_format($creditNow, 0),
                'fixed +' . number_format($creditFixed, 0),
                count($ps) > 1 ? ('⚠ ' . count($ps) . ' payments — watch for OVER-credit') : ''];
            $rows[] = ['', '', '', '', '', ''];
        }

        $this->table(['Order', 'Payment', 'Booked', 'USD now', 'When', 'Note'], $rows);
        $this->warn('  ' . count($flagged) . ' order(s) have mis-booked payments. Orders marked ⚠ have duplicates');
        $this->line('  (from testing) that must be de-duplicated, not blindly flipped. Paste this to review the fix.');
        $this->newLine();
        return self::SUCCESS;
    }
}
