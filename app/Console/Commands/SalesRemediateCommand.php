<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Sales\SaleFxAudit;
use CloudBSS\Infra\LedgerPoster;
use CloudBSS\Kernel\Money\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correct the historical multi-currency REVENUE overstatement with compensating journal entries.
 * Sibling of cogs:remediate — same safety model:
 *   • DRY RUN by default (writes nothing). Reads the ACTUAL posted net revenue from cloudbss_events,
 *     computes the correct base value, and prints/exports the exact per-order plan.
 *   • --confirm posts, but only after: --approver is given, the ledger book has a SaleFxCorrectionPolicy
 *     registered (LedgerPoster::canPost — else abort that tenant, write nothing), each entry uses the
 *     idempotent aggregate 'salefx_<orderId>', missing FX is excluded (REQUIRES_RECONCILIATION), a prior
 *     correction is skipped (ALREADY_REMEDIATED), and the book balance is re-asserted after each batch.
 *   • The original sale journal is immutable; the correction is a NEW 'SaleFxCorrected' event →
 *     Dr 4000 Sales Revenue / Dr 2200 VAT Output / Cr 1100 AR for the overstatement only.
 *
 * Run ONLY after the live staging journal check passes.
 */
class SalesRemediateCommand extends Command
{
    /** Output VAT applied by UgandaVatSalesPolicy — keep in sync with the booted policy. */
    private const VAT_BP = 1800;

    protected $signature = 'sales:remediate
        {--tenant= : limit to one tenant id or slug (strongly recommended)}
        {--limit=5000 : max orders to scan per tenant}
        {--confirm : POST compensating entries. WITHOUT this flag it is a dry run that writes nothing}
        {--approver= : name/id recorded on every correction (REQUIRED with --confirm)}
        {--csv= : write the per-order plan to this CSV path (else a default under storage/app)}
        {--json : also print the summary as JSON}';

    protected $description = 'Post compensating entries for the historical REVENUE currency overstatement (dry run unless --confirm).';

    public function handle(): int
    {
        $confirm  = (bool) $this->option('confirm');
        $approver = trim((string) $this->option('approver'));
        $limit    = max(1, (int) $this->option('limit'));
        $only     = (string) $this->option('tenant');

        if ($confirm && $approver === '') {
            $this->error('--approver is required with --confirm (it is recorded on every compensating entry).');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('REVENUE remediation — ' . ($confirm ? 'LIVE (--confirm) — compensating entries WILL be posted' : 'DRY RUN — nothing will be written'));
        $this->line('');

        /** @var LedgerPoster|null $poster */
        $poster = $confirm ? app(LedgerPoster::class) : null;

        $q = Tenant::query()->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        $allRows = []; $posted = 0; $skipped = 0; $abortedTenants = [];

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

            [$postedByOrder, $remediatedIds, $logOk] = $this->readKernelState($ledgerTenant, $ledgerEntity, $orders->pluck('id')->all());
            if (! $logOk) {
                $this->warn("  Tenant {$t->id}: could not read the kernel event log (cloudbss_events). Skipped.");
                continue;
            }

            if ($confirm && ! $poster->canPost($ledgerTenant, $ledgerEntity, 'SaleFxCorrected')) {
                $abortedTenants[] = "{$t->id} ({$ledgerTenant}/{$ledgerEntity})";
                $this->error("  Tenant {$t->id}: ledger book {$ledgerTenant}/{$ledgerEntity} has NO SaleFxCorrectionPolicy registered — refusing to post. Register it in LedgerServiceProvider first.");
                continue;
            }

            $plans = [];
            foreach ($orders as $o) {
                $date = ($o->delivered_at ?: $o->created_at) ? Carbon::parse($o->delivered_at ?: $o->created_at)->format('Y-m-d') : null;
                $toBaseMinor = $this->minorConverter($fx, $base, $factor, $date);
                $plan = SaleFxAudit::assess([
                    'order_id' => (int) $o->id, 'order_no' => (string) $o->order_no, 'date' => $date,
                    'base_currency' => $base, 'sell_currency' => (string) $o->sell_currency,
                    'base_factor' => $factor, 'vat_bp' => self::VAT_BP,
                    'posted_net_minor' => $postedByOrder[$o->id] ?? null,
                ], $toBaseMinor);
                $plan['tenant_id'] = (int) $t->id;
                $plan['already'] = isset($remediatedIds['salefx_' . $o->id]);
                $plan['action'] = 'dry-run';
                $plans[] = $plan;
            }

            if ($confirm) {
                foreach ($plans as &$plan) {
                    if ($plan['status'] !== SaleFxAudit::REMEDIABLE) { continue; }
                    if ($plan['already']) { $plan['action'] = 'already-remediated (skip)'; $skipped++; continue; }
                    $ok = $poster->post(
                        $ledgerTenant, $ledgerEntity,
                        $plan['correction_aggregate'], 'SaleFxCorrection', 'SaleFxCorrected',
                        "salefx_{$ledgerTenant}_{$plan['order_id']}",
                        [
                            'net_amount' => (int) $plan['net_over_minor'],
                            'currency'   => $base,
                            'vat_bp'     => self::VAT_BP,
                            'sale'       => (string) $plan['order_id'],
                            'posted_net' => (int) $plan['posted_net_minor'],
                            'correct_net' => (int) $plan['correct_net_minor'],
                            'reason'     => 'multi-currency revenue overstatement: sell-currency net booked as base without conversion',
                            'version'    => SaleFxAudit::VERSION,
                            'approver'   => $approver,
                            'source_journal' => 'sale:' . $plan['order_id'],
                        ],
                    );
                    if ($ok) { $plan['action'] = 'posted'; $posted++; }
                    else     { $plan['action'] = 'already-posted (no-op)'; $skipped++; }
                }
                unset($plan);

                if (! $poster->bookBalances($ledgerTenant, $ledgerEntity)) {
                    $this->error("  Tenant {$t->id}: BOOK DOES NOT BALANCE after posting — STOP and investigate immediately.");
                    $allRows = array_merge($allRows, $plans);
                    $this->export($allRows);
                    return self::FAILURE;
                }
                $this->line("  Tenant {$t->id}: book balances after batch ✓");
            }

            $allRows = array_merge($allRows, $plans);
        }

        $this->summary($allRows, $confirm, $posted, $skipped);
        $this->export($allRows);

        if (! $confirm) {
            $this->line('');
            $this->comment('DRY RUN — nothing was written. Review, complete the staging journal check, then re-run with:');
            $this->comment('  php artisan sales:remediate --tenant=<id> --confirm --approver="<your name>"');
        }
        if ($abortedTenants) {
            $this->line('');
            $this->warn('Aborted (unregistered ledger book): ' . implode(', ', $abortedTenants) . ' — nothing posted for these.');
        }

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

    /** @return array{0: array<int,int>, 1: array<string,bool>, 2: bool} */
    private function readKernelState(string $ledgerTenant, string $ledgerEntity, array $orderIds): array
    {
        try {
            $ids = array_map('strval', $orderIds);
            $sales = DB::connection('pgsql')->table('cloudbss_events')
                ->where('tenant_id', $ledgerTenant)->where('entity_id', $ledgerEntity)
                ->where('name', 'DeliveryCompleted')->whereIn('aggregate_id', $ids)
                ->get(['aggregate_id', 'payload']);
            $postedByOrder = [];
            foreach ($sales as $r) {
                $p = json_decode((string) $r->payload, true) ?: [];
                if (! array_key_exists('revenue', $p)) { continue; }
                $postedByOrder[(int) $r->aggregate_id] = (int) $p['revenue'];
            }

            $fixIds = array_map(fn ($id) => 'salefx_' . $id, $ids);
            $done = DB::connection('pgsql')->table('cloudbss_events')
                ->where('tenant_id', $ledgerTenant)->where('entity_id', $ledgerEntity)
                ->where('name', 'SaleFxCorrected')->whereIn('aggregate_id', $fixIds)
                ->pluck('aggregate_id');
            $remediatedIds = [];
            foreach ($done as $a) { $remediatedIds[(string) $a] = true; }

            return [$postedByOrder, $remediatedIds, true];
        } catch (\Throwable $e) {
            return [[], [], false];
        }
    }

    private function summary(array $rows, bool $confirm, int $posted, int $skipped): void
    {
        $by = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));
        $rem = array_values(array_filter($rows, fn ($r) => $r['status'] === SaleFxAudit::REMEDIABLE));
        $netOver   = array_sum(array_map(fn ($r) => (float) $r['net_overstatement'], $rem));
        $grossOver = array_sum(array_map(fn ($r) => (float) $r['gross_overstatement'], $rem));

        $this->line('');
        $this->line('Orders scanned            : ' . count($rows));
        $this->line('Remediable (overstated)   : ' . count($rem));
        $this->line('Already remediated        : ' . count(array_filter($rows, fn ($r) => ! empty($r['already']))));
        $this->line('Requires reconciliation   : ' . $by(SaleFxAudit::REQUIRES) . '  (missing FX — never invented)');
        $this->line('Not affected              : ' . $by(SaleFxAudit::NOT_AFFECTED));
        $this->line('Not posted to kernel      : ' . $by(SaleFxAudit::NOT_POSTED));
        $this->line('Net revenue overstatement : ' . number_format($netOver, 2) . '  (account 4000)');
        $this->line('Gross / AR overstatement  : ' . number_format($grossOver, 2) . '  (account 1100; VAT 2200 = the difference)');
        if ($confirm) {
            $this->line('');
            $this->info("Posted this run           : {$posted}");
            $this->line("Skipped (already / no-op) : {$skipped}");
        }

        usort($rem, fn ($a, $b) => ($b['net_over_minor'] ?? 0) <=> ($a['net_over_minor'] ?? 0));
        foreach (array_slice($rem, 0, 30) as $r) {
            $this->line(sprintf('  %-10s  %s  posted net %-14s → correct %-10s  (over net %s / vat %s / gross %s)  [%s]',
                $r['order_no'], $r['sell_currency'], number_format((float) $r['posted_net'], 2), number_format((float) $r['correct_net'], 2),
                number_format((float) $r['net_overstatement'], 2), number_format((float) $r['vat_overstatement'], 2),
                number_format((float) $r['gross_overstatement'], 2), $r['action']));
        }
    }

    private function export(array $rows): void
    {
        $csv = (string) ($this->option('csv') ?: storage_path('app/sales_remediate_' . now()->format('Ymd_His') . '.csv'));
        $cols = ['tenant_id', 'order_id', 'order_no', 'date', 'sell_currency', 'posted_net', 'correct_net',
            'net_overstatement', 'vat_overstatement', 'gross_overstatement', 'correction_aggregate',
            'source_sale_aggregate', 'version', 'status', 'action'];
        $fh = @fopen($csv, 'w');
        if ($fh) {
            fputcsv($fh, $cols);
            foreach ($rows as $r) { fputcsv($fh, array_map(fn ($c) => $r[$c] ?? '', $cols)); }
            fclose($fh);
            $this->line('');
            $this->info('CSV plan: ' . $csv);
        } else {
            $this->warn('Could not write CSV to ' . $csv);
        }

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($r) => [
                'order_no' => $r['order_no'], 'status' => $r['status'],
                'net_overstatement' => $r['net_overstatement'], 'action' => $r['action'],
            ], $rows), JSON_PRETTY_PRINT));
        }
    }
}
