<?php

namespace App\Console\Commands;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Money\Fx;
use App\Services\Money\SalesBaseCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Correction counterpart to cash:audit-payments. Repairs settlement payments booked in the wrong
 * currency by the pre-fix recordPayment path (a $90 payment stored as "90 UGX" ≈ $0.02), then
 * re-syncs every affected order's amount_paid and invoice balance from the corrected ledger — so the
 * invoices that were stuck on "due" clear.
 *
 * A payment is treated as mis-booked when its currency is the local currency (e.g. UGX) but the amount
 * is small enough (≤ --max-native) that it was really a base-currency (USD) figure typed in. The fix:
 * currency → base (USD), rate → 1, usd_amount → amount (and the same on the mirrored cashbook OrderCost).
 *
 * DRY RUN by default (writes nothing). With --commit it writes in a transaction and stores every
 * original value in tenant setting `payments_fix_backup`, reversible with --rollback.
 *
 * NOTE: this does NOT delete duplicate test payments (it can't know which are real). The audit flags
 * orders with duplicates (⚠) — delete the extras from the Payments screen, which fully reverses them.
 *
 *   php artisan cash:fix-payments --tenant=ID              # dry run (safe)
 *   php artisan cash:fix-payments --tenant=ID --commit     # apply + save rollback snapshot
 *   php artisan cash:fix-payments --tenant=ID --rollback   # undo the last committed run
 */
final class FixPaymentsCommand extends Command
{
    protected $signature = 'cash:fix-payments
        {--tenant= : tenant id (required)}
        {--max-native=20000 : a local-currency payment at/below this is treated as a typed base-currency amount}
        {--commit : actually write (default is a dry run)}
        {--rollback : restore the values saved by the last committed run}';

    protected $description = 'Repair mis-booked payment currencies and re-sync the affected invoices/orders (dry run unless --commit).';

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

        // CustomerPayment + OrderCost are tenant-scoped: set the active tenant so their global scope
        // resolves to this tenant (otherwise every query below — and the rollback — would see nothing).
        app(\App\Support\TenantContext::class)->set($t->id);

        if ($this->option('rollback')) return $this->rollback($t);

        $base   = SalesBaseCurrency::baseCurrency($t);          // USD
        $native = SalesBaseCurrency::nativeCurrency($t);        // UGX
        $maxNat = (float) $this->option('max-native');
        $commit = (bool) $this->option('commit');

        $pays = CustomerPayment::where('tenant_id', $t->id)->where('kind', 'settlement')
            ->orderBy('order_no')->orderBy('id')->get();

        $rows = [];        // display
        $backup = [];      // originals for rollback
        $orders = [];      // order_no => true (to re-sync)
        $invoices = [];    // invoice_no => true

        foreach ($pays as $p) {
            $isNative = strtoupper((string) $p->currency) === $native;
            $amt      = (float) $p->amount;
            if (! ($isNative && $amt > 0 && $amt <= $maxNat)) continue;   // not mis-booked

            $oldUsd = (float) $p->usd_amount;
            $rows[] = ['CP-' . $p->id, (string) $p->order_no, $p->currency . ' ' . number_format($amt, 0),
                '$' . number_format($oldUsd, 2), '→', $base . ' ' . number_format($amt, 2), '$' . number_format($amt, 2)];
            $backup[] = ['type' => 'payment', 'id' => $p->id,
                'currency' => $p->currency, 'rate_to_usd' => (float) $p->rate_to_usd, 'usd_amount' => $oldUsd];

            if ($p->order_no) $orders[(string) $p->order_no] = true;
            if ($p->invoice_no) $invoices[(string) $p->invoice_no] = true;

            if ($commit) {
                $p->currency = $base; $p->rate_to_usd = 1; $p->usd_amount = round($amt, 2); $p->save();
                if ($p->order_cost_id) {
                    $cost = OrderCost::where('tenant_id', $t->id)->find($p->order_cost_id);
                    if ($cost) {
                        $backup[] = ['type' => 'cost', 'id' => $cost->id,
                            'currency' => $cost->currency, 'native_amount' => (float) $cost->native_amount,
                            'rate_to_usd' => (float) $cost->rate_to_usd, 'usd_amount' => (float) $cost->usd_amount,
                            'paid_native' => (float) $cost->paid_native];
                        $cost->currency = $base; $cost->native_amount = round($amt, 2); $cost->rate_to_usd = 1;
                        $cost->usd_amount = round($amt, 2); $cost->paid_native = round($amt, 2); $cost->save();
                    }
                }
            }
        }

        $this->newLine();
        $this->info(($commit ? 'FIX' : 'DRY RUN') . " — payments, {$t->name} (tenant {$t->id})   local {$native} · base {$base}");
        if (! $rows) { $this->info('  ✓ No mis-booked payments found.'); return self::SUCCESS; }
        $this->table(['Payment', 'Order', 'Booked', 'USD now', '', 'Corrected', 'USD after'], $rows);
        $this->line('  ' . count($rows) . ' payment(s) to correct across ' . count($orders) . ' order(s).');

        if (! $commit) {
            $this->warn('  DRY RUN — nothing written. Re-run with --commit once the list looks right.');
            $this->line('  (--commit saves a rollback snapshot; undo any time with --rollback.)');
            return self::SUCCESS;
        }

        // persist rollback snapshot, then re-sync every touched order + invoice from the corrected ledger
        $t->putSetting('payments_fix_backup', ['rows' => $backup]);
        $svc = app(InvoiceService::class);
        foreach (array_keys($orders) as $orderNo) {
            $this->recomputeOrderPaid($t, $orderNo, $base, $native);
            $svc->syncByPayment($t, null, $orderNo);
        }
        foreach (array_keys($invoices) as $invNo) $svc->syncByPayment($t, $invNo, null);

        $this->info('  ✓ Corrected ' . count($rows) . ' payment(s); re-synced ' . count($orders) . ' order(s) + their invoices.');
        $this->line('  Undo with:  php artisan cash:fix-payments --tenant=' . $t->id . ' --rollback');
        return self::SUCCESS;
    }

    /** Recompute an order's amount_paid from its settlement payments, in the order's own currency. */
    private function recomputeOrderPaid(Tenant $t, string $orderNo, string $base, string $native): void
    {
        $order = Order::where('tenant_id', $t->id)->where('order_no', $orderNo)->first();
        if (! $order) return;
        $oc  = SalesBaseCurrency::txnCurrency($order, $t);
        $fx  = Fx::forTenant($t);
        $paid = 0.0;
        foreach (CustomerPayment::where('tenant_id', $t->id)->where('order_no', $orderNo)
                     ->where('kind', 'settlement')->where('applied', true)->get() as $p) {
            $paid += strtoupper((string) $p->currency) === $oc ? (float) $p->amount
                   : ($oc === $base ? (float) $p->usd_amount : (float) $p->usd_amount * $fx->ratePerUsd($oc));
        }
        $order->amount_paid = round($paid, 2);
        $order->save();
    }

    private function rollback(Tenant $t): int
    {
        $snap = $t->setting('payments_fix_backup');
        if (! is_array($snap) || empty($snap['rows'])) { $this->warn('  No rollback snapshot for this tenant.'); return self::FAILURE; }
        $n = 0; $orders = [];
        DB::transaction(function () use ($snap, $t, &$n, &$orders) {
            foreach ($snap['rows'] as $r) {
                if (($r['type'] ?? '') === 'payment') {
                    $p = CustomerPayment::where('tenant_id', $t->id)->find($r['id']);
                    if ($p) { $p->currency = $r['currency']; $p->rate_to_usd = $r['rate_to_usd']; $p->usd_amount = $r['usd_amount']; $p->save(); $n++; if ($p->order_no) $orders[(string) $p->order_no] = true; }
                } else {
                    $c = OrderCost::where('tenant_id', $t->id)->find($r['id']);
                    if ($c) { $c->currency = $r['currency']; $c->native_amount = $r['native_amount']; $c->rate_to_usd = $r['rate_to_usd']; $c->usd_amount = $r['usd_amount']; $c->paid_native = $r['paid_native']; $c->save(); }
                }
            }
            $t->putSetting('payments_fix_backup', null);
        });
        $base = SalesBaseCurrency::baseCurrency($t); $native = SalesBaseCurrency::nativeCurrency($t);
        $svc = app(InvoiceService::class);
        foreach (array_keys($orders) as $orderNo) { $this->recomputeOrderPaid($t, $orderNo, $base, $native); $svc->syncByPayment($t, null, $orderNo); }
        $this->info("  ✓ Restored {$n} payment(s) and re-synced their orders/invoices. Snapshot cleared.");
        return self::SUCCESS;
    }
}
