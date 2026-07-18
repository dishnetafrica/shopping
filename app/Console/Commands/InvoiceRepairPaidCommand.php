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

        foreach ($invoices as $inv) {
            $before = [round((float) $inv->amount_paid, 2), round((float) $inv->balance, 2), (string) $inv->status];

            if ($dry) {
                // simulate on a clone so nothing persists
                $sim = $inv->replicate(['pdf_path']);
                $sim->id = $inv->id;
                $sim->exists = true;
                $sim->invoice_no = $inv->invoice_no;
                try {
                    \Illuminate\Support\Facades\DB::beginTransaction();
                    $svc->syncPaidFromPayments($inv);
                    $after = [round((float) $inv->amount_paid, 2), round((float) $inv->balance, 2), (string) $inv->status];
                    \Illuminate\Support\Facades\DB::rollBack();
                    $inv->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    $this->warn("  {$inv->invoice_no}: simulate failed — ".$e->getMessage());
                    continue;
                }
            } else {
                try {
                    $svc->syncPaidFromPayments($inv);
                } catch (\Throwable $e) {
                    $this->warn("  {$inv->invoice_no}: failed — ".$e->getMessage());
                    continue;
                }
                $inv->refresh();
                $after = [round((float) $inv->amount_paid, 2), round((float) $inv->balance, 2), (string) $inv->status];
            }

            if ($before !== $after) {
                $changed++;
                $this->line(sprintf('  %-14s %-10s paid %14s → %-14s balance %12s → %-12s status %s → %s',
                    $inv->invoice_no, (string) $inv->customer_name,
                    number_format($before[0], 2), number_format($after[0], 2),
                    number_format($before[1], 2), number_format($after[1], 2),
                    $before[2], $after[2]));
            }
        }

        $this->line('');
        $this->info(($dry ? '[DRY] would fix ' : 'Fixed ')."{$changed} of ".count($invoices).' invoice(s).');
        if ($dry && $changed > 0) $this->comment('Read the list above, then rerun without --dry.');
        if (! $dry && $changed > 0) $this->comment('Hard-refresh /v3 → Invoices: rows and the Paid (30d) card are now consolidated truth.');

        return self::SUCCESS;
    }
}
