<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Console\Command;

/**
 * invoices:repair-paid — the mixed-currency data fix (2026-07-18).
 *
 * The FX-correct application math (InvoiceService::syncPaidFromPayments) already
 * exists: true transaction currency + every payment bridged through USD. But it
 * only fires on NEW payment events — invoices paid before that machinery landed
 * still carry raw cross-currency amounts (e.g. a USD invoice showing
 * amount_paid = 2,284,375 because a UGX payment was written in unconverted).
 *
 * This command re-runs the correct math over every live invoice. Idempotent:
 * already-correct invoices don't change. Always run --dry first and READ it.
 */
class InvoiceRepairPaidCommand extends Command
{
    protected $signature   = 'invoices:repair-paid {tenant} {--dry : Show what would change, write nothing}';
    protected $description = 'Recompute every invoice\'s amount_paid/balance/status from the payment ledger with proper FX';

    public function handle(InvoiceService $svc): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) { $this->error('tenant not found'); return self::FAILURE; }
        app(\App\Support\TenantContext::class)->set($t->id);
        $dry = (bool) $this->option('dry');

        $invoices = Invoice::where('tenant_id', $t->id)->where('status', '!=', 'void')
            ->orderBy('id')->get();
        $changed = 0;

        $fx = \App\Services\Money\Fx::forTenant($t);
        foreach ($invoices as $inv) {
            $before = [round((float) $inv->total, 2), round((float) $inv->amount_paid, 2),
                       round((float) $inv->balance, 2), (string) $inv->status, (string) $inv->currency];

            // STEP 1 — normalise the MONEY SIDE of the invoice into its TRUE transaction
            // currency. The issuer stamps the tenant base (USD) even on UGX deals, so a
            // 1,315,800-UGX invoice can sit with total=360 in USD units. Convert
            // subtotal/discount/freight/total tag→txn via the same USD bridge as payments,
            // and stamp the honest currency (original tag kept in meta).
            $txn = \App\Services\Money\SalesBaseCurrency::txnCurrency($inv, $t);
            $tag = strtoupper((string) $inv->currency ?: $txn);
            if ($txn !== '' && $tag !== $txn) {
                $conv = function ($v) use ($fx, $tag, $txn) {
                    $usd = $tag === 'USD' ? (float) $v : ((float) $v / max(0.0001, $fx->ratePerUsd($tag)));
                    return round($txn === 'USD' ? $usd : $usd * $fx->ratePerUsd($txn), 2);
                };
                $meta = (array) ($inv->meta ?? []);
                $meta['currency_tag_original'] = $tag;
                $meta['currency_repaired_at'] = now()->toDateTimeString();
                $inv->meta = $meta;
                $inv->subtotal = $conv($inv->subtotal);
                $inv->discount = $conv($inv->discount);
                $inv->freight  = $conv($inv->freight);
                $inv->total    = $conv($inv->total);
                $inv->currency = $txn;
            }


            // STEP 2 — recompute paid/balance/status from the payment ledger (the proven
            // FX-correct sync). In --dry everything (incl. step 1) rolls back.
            try {
                \Illuminate\Support\Facades\DB::beginTransaction();
                $svc->syncPaidFromPayments($inv);
                $after = [round((float) $inv->total, 2), round((float) $inv->amount_paid, 2),
                          round((float) $inv->balance, 2), (string) $inv->status, (string) $inv->currency];
                if ($dry) { \Illuminate\Support\Facades\DB::rollBack(); } else { \Illuminate\Support\Facades\DB::commit(); }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                $this->warn("  {$inv->invoice_no}: failed — ".$e->getMessage());
                $inv->refresh();
                continue;
            }
            if ($dry) $inv->refresh();

            if ($before !== $after) {
                $changed++;
                $this->line(sprintf('  %-14s %-16s %s→%s  total %14s → %-14s paid %14s → %-14s balance %10s → %-10s %s→%s',
                    $inv->invoice_no, mb_substr((string) $inv->customer_name, 0, 16),
                    $before[4], $after[4],
                    number_format($before[0], 2), number_format($after[0], 2),
                    number_format($before[1], 2), number_format($after[1], 2),
                    number_format($before[2], 2), number_format($after[2], 2),
                    $before[3], $after[3]));
            }
        }

        $this->line('');
        $this->info(($dry ? '[DRY] would fix ' : 'Fixed ')."{$changed} of ".count($invoices).' invoice(s).');
        if ($dry && $changed > 0) $this->comment('Read the list above, then rerun without --dry.');
        if (! $dry && $changed > 0) $this->comment('Hard-refresh /v3 → Invoices: rows and the Paid (30d) card are now consolidated truth.');

        return self::SUCCESS;
    }
}
