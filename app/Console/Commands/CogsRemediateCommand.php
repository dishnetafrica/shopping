<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Money\Fx;
use App\Services\Sales\CogsRemediation;
use App\Services\Sales\OrderCosting;
use CloudBSS\Infra\LedgerPoster;
use CloudBSS\Kernel\Money\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B — correct the historical multi-currency COGS overstatement with compensating journal entries.
 *
 * SAFETY MODEL (deliberate, non-negotiable):
 *   • DRY RUN by default — writes nothing. It reads the ACTUAL posted COGS/revenue for each sale from
 *     the kernel event log (cloudbss_events), computes the correct base-currency cost, and prints/export s
 *     the exact compensating plan. You review it before anything is posted.
 *   • --confirm is required to post. Even then, before writing a single entry it PROBES that the tenant's
 *     ledger book has a CogsCorrectionPolicy registered (LedgerPoster::canPost). If not, it ABORTS that
 *     tenant and writes nothing — never a silent no-op that would poison idempotency.
 *   • Originals are immutable: each correction is a NEW 'CogsCorrected' event → Dr 1200 Inventory /
 *     Cr 5000 COGS for the overstatement only. The original sale journal is never touched.
 *   • Idempotent: the correction aggregate id is 'cogsfix_<orderId>'. A prior correction is detected
 *     (ALREADY_REMEDIATED) and skipped, and LedgerPoster no-ops a repeat of the same aggregate anyway.
 *   • Missing historical FX → REQUIRES_RECONCILIATION, excluded from auto-remediation (never invents a rate).
 *   • After each tenant batch it asserts the book still balances (Σdebits == Σcredits).
 *
 * This command must be run ONLY after the live staging journal check passes (per the remediation plan).
 */
class CogsRemediateCommand extends Command
{
    protected $signature = 'cogs:remediate
        {--tenant= : limit to one tenant id or slug (strongly recommended)}
        {--limit=5000 : max orders to scan per tenant}
        {--confirm : POST compensating entries. WITHOUT this flag it is a dry run that writes nothing}
        {--approver= : name/id recorded on every correction (REQUIRED with --confirm)}
        {--csv= : write the per-order plan to this CSV path (else a default under storage/app)}
        {--json : also print the summary as JSON}';

    protected $description = 'Post compensating entries for the historical COGS currency overstatement (dry run unless --confirm).';

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

        $mode = $confirm ? 'LIVE (--confirm) — compensating entries WILL be posted' : 'DRY RUN — nothing will be written';
        $this->line('');
        $this->info('COGS remediation — ' . $mode);
        $this->line('');

        /** @var LedgerPoster|null $poster */
        $poster = $confirm ? app(LedgerPoster::class) : null;

        $q = Tenant::query()->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        $allRows = [];
        $posted = 0; $skipped = 0; $abortedTenants = [];

        foreach ($q->get() as $t) {
            if (! (bool) $t->setting('ledger_enabled', false)) { continue; }

            $base    = strtoupper((string) $t->setting('currency', 'UGX'));
            $costCur = OrderCosting::COST_CURRENCY;
            $factor  = Currency::of($base)->subunit();
            $fx      = Fx::forTenant($t);
            $ledgerTenant = (string) $t->setting('ledger_tenant', 't_' . $t->id);
            $ledgerEntity = (string) $t->setting('ledger_entity', 'e1');

            // Complete (delivered/confirmed) orders — the ones that would have been posted (schema-safe).
            $hasConfirmed = Schema::hasColumn('orders', 'customer_confirmed_at');
            $cols = ['id', 'order_no', 'total', 'sell_currency', 'created_at', 'delivered_at'];
            if ($hasConfirmed) { $cols[] = 'customer_confirmed_at'; }
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where(function ($w) use ($hasConfirmed) {
                    $w->whereNotNull('delivered_at');
                    if ($hasConfirmed) { $w->orWhereNotNull('customer_confirmed_at'); }
                })
                ->orderByDesc('id')->limit($limit)->get($cols);
            if ($orders->isEmpty()) { continue; }

            // COGS native per order (cost_price × qty).
            $items = OrderItem::withoutGlobalScopes()->whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'product_id', 'qty']);
            $cost  = Product::withoutGlobalScopes()->whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('cost_price', 'id');
            $cogsByOrder = [];
            foreach ($items as $it) {
                $cogsByOrder[$it->order_id] = ($cogsByOrder[$it->order_id] ?? 0.0) + (float) ($cost[$it->product_id] ?? 0) * (float) $it->qty;
            }

            // ACTUAL posted amounts + existing corrections, read from the kernel event log.
            [$postedByOrder, $remediatedIds, $logOk] = $this->readKernelState($ledgerTenant, $ledgerEntity, $orders->pluck('id')->all());
            if (! $logOk) {
                $this->warn("  Tenant {$t->id}: could not read the kernel event log (cloudbss_events). Skipped — cannot determine actual postings.");
                continue;
            }

            // Gate LIVE posting on the book being registered BEFORE writing anything (no silent no-op).
            if ($confirm && ! $poster->canPost($ledgerTenant, $ledgerEntity, 'CogsCorrected')) {
                $abortedTenants[] = "{$t->id} ({$ledgerTenant}/{$ledgerEntity})";
                $this->error("  Tenant {$t->id}: ledger book {$ledgerTenant}/{$ledgerEntity} has NO CogsCorrectionPolicy registered — refusing to post (would silently no-op). Register it in CloudBssServiceProvider first.");
                continue;
            }

            $plans = [];
            foreach ($orders as $o) {
                $date = ($o->delivered_at ?: $o->created_at) ? Carbon::parse($o->delivered_at ?: $o->created_at)->format('Y-m-d') : null;
                $toBaseMinor = $this->minorConverter($fx, $base, $factor, $date);

                $plan = CogsRemediation::plan([
                    'order_id'   => (int) $o->id,
                    'order_no'   => (string) $o->order_no,
                    'base_currency' => $base,
                    'cost_currency' => $costCur,
                    'date'       => $date,
                    'posted_cost_minor'    => $postedByOrder[$o->id]['cost'] ?? null,
                    'posted_revenue_minor' => $postedByOrder[$o->id]['revenue'] ?? null,
                    'cogs_native' => (float) ($cogsByOrder[$o->id] ?? 0),
                    'already_remediated' => isset($remediatedIds['cogsfix_' . $o->id]),
                ], $toBaseMinor);
                $plan['tenant_id'] = (int) $t->id;
                $plan['fx_rate_cost'] = $this->rate($fx, $costCur, $base, $date);
                $plan['action'] = 'dry-run';
                $plans[] = $plan;
            }

            // LIVE posting for the remediable plans in this tenant.
            if ($confirm) {
                foreach ($plans as &$plan) {
                    if ($plan['status'] !== CogsRemediation::REMEDIABLE) { continue; }
                    $ok = $poster->post(
                        $ledgerTenant, $ledgerEntity,
                        $plan['correction_aggregate'], 'CogsCorrection', 'CogsCorrected',
                        "cogsfix_{$ledgerTenant}_{$plan['order_id']}",
                        [
                            'amount'   => (int) $plan['overstatement_minor'],
                            'currency' => $base,
                            'sale'     => (string) $plan['order_id'],
                            'original_posted_cost' => (int) $plan['posted_cost_minor'],
                            'correct_cost'         => (int) $plan['correct_cost_minor'],
                            'fx_rate'  => $plan['fx_rate_cost'],
                            'reason'   => 'multi-currency COGS overstatement: ' . $costCur . ' cost booked as base without conversion',
                            'version'  => CogsRemediation::VERSION,
                            'approver' => $approver,
                            'source_journal' => 'sale:' . $plan['order_id'],
                        ],
                    );
                    if ($ok) { $plan['action'] = 'posted'; $posted++; }
                    else     { $plan['action'] = 'already-posted (no-op)'; $skipped++; }
                }
                unset($plan);

                // Integrity: the book must still balance after this tenant's batch.
                $balanced = $poster->bookBalances($ledgerTenant, $ledgerEntity);
                if (! $balanced) {
                    $this->error("  Tenant {$t->id}: BOOK DOES NOT BALANCE after posting — STOP and investigate immediately.");
                    $allRows = array_merge($allRows, $plans);
                    $this->export($allRows);
                    return self::FAILURE;
                }
                $this->line("  Tenant {$t->id}: book balances after batch ✓");
            }

            $allRows = array_merge($allRows, $plans);
        }

        $this->summary($allRows, $confirm, $posted, $skipped, $abortedTenants);
        $this->export($allRows);

        if (! $confirm) {
            $this->line('');
            $this->comment('DRY RUN — nothing was written. Review the plan/CSV, complete the staging journal check, then re-run with:');
            $this->comment('  php artisan cogs:remediate --tenant=<id> --confirm --approver="<your name>"');
        }
        if ($abortedTenants) {
            $this->line('');
            $this->warn('Aborted (unregistered ledger book): ' . implode(', ', $abortedTenants) . ' — nothing posted for these.');
        }

        return self::SUCCESS;
    }

    /** Build fn(float $amount, string $from): ?int  → correct base amount in MINOR units (null if FX missing). */
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

    private function rate(Fx $fx, string $from, string $base, ?string $date): ?float
    {
        if (strtoupper($from) === strtoupper($base)) { return 1.0; }
        $rFrom = $fx->ratePerUsd($from, $date);
        $rBase = $fx->ratePerUsd($base, $date);
        if ($rFrom <= 0 || $rBase <= 0) { return null; }
        return round($rBase / $rFrom, 8);   // base units per 1 unit of cost currency
    }

    /**
     * Read, per order, the ACTUAL posted {cost,revenue} minor from cloudbss_events (name DeliveryCompleted),
     * and the set of correction aggregate ids already posted (name CogsCorrected). Read-only.
     *
     * @return array{0: array<int,array{cost:int,revenue:?int}>, 1: array<string,bool>, 2: bool}
     */
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
                if (! array_key_exists('cost', $p)) { continue; }
                $postedByOrder[(int) $r->aggregate_id] = [
                    'cost'    => (int) $p['cost'],
                    'revenue' => isset($p['revenue']) ? (int) $p['revenue'] : null,
                ];
            }

            $fixIds = array_map(fn ($id) => 'cogsfix_' . $id, $ids);
            $done = DB::connection('pgsql')->table('cloudbss_events')
                ->where('tenant_id', $ledgerTenant)->where('entity_id', $ledgerEntity)
                ->where('name', 'CogsCorrected')->whereIn('aggregate_id', $fixIds)
                ->pluck('aggregate_id');
            $remediatedIds = [];
            foreach ($done as $a) { $remediatedIds[(string) $a] = true; }

            return [$postedByOrder, $remediatedIds, true];
        } catch (\Throwable $e) {
            return [[], [], false];
        }
    }

    private function summary(array $rows, bool $confirm, int $posted, int $skipped, array $aborted): void
    {
        $by = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));
        $remediable = $by(CogsRemediation::REMEDIABLE);
        $overMinor  = array_sum(array_map(fn ($r) => $r['status'] === CogsRemediation::REMEDIABLE ? (int) $r['overstatement_minor'] : 0, $rows));
        // display total in each tenant's base — rows can mix bases; show a raw major-sum best-effort.
        $overMajor  = array_sum(array_map(fn ($r) => $r['status'] === CogsRemediation::REMEDIABLE ? (float) $r['overstatement'] : 0.0, $rows));

        $this->line('');
        $this->line('Orders scanned            : ' . count($rows));
        $this->line('Remediable (overstated)   : ' . $remediable);
        $this->line('Already remediated        : ' . $by(CogsRemediation::ALREADY));
        $this->line('Requires reconciliation   : ' . $by(CogsRemediation::REQUIRES) . '  (missing FX — never invented)');
        $this->line('Not affected              : ' . $by(CogsRemediation::NOT_AFFECTED));
        $this->line('Not posted to kernel      : ' . $by(CogsRemediation::NOT_POSTED));
        $this->line('COGS overstatement total  : ' . number_format($overMajor, 2) . '  = expected gross-profit restoration');
        if ($confirm) {
            $this->line('');
            $this->info("Posted this run           : {$posted}");
            $this->line("Skipped (already posted)  : {$skipped}");
        }

        // Top offenders.
        $rem = array_values(array_filter($rows, fn ($r) => $r['status'] === CogsRemediation::REMEDIABLE));
        usort($rem, fn ($a, $b) => ($b['overstatement_minor'] ?? 0) <=> ($a['overstatement_minor'] ?? 0));
        if ($rem) { $this->line(''); $this->line('Largest corrections:'); }
        foreach (array_slice($rem, 0, 15) as $r) {
            $this->line(sprintf('  %-10s  posted %s  → correct %s  (reduce COGS by %s)  [%s]  %s',
                $r['order_no'], number_format((float) $r['posted_cost'], 2), number_format((float) $r['correct_cost'], 2),
                number_format((float) $r['overstatement'], 2), $r['base_currency'], $r['action']));
        }
    }

    private function export(array $rows): void
    {
        $csv = (string) ($this->option('csv') ?: storage_path('app/cogs_remediate_' . now()->format('Ymd_His') . '.csv'));
        $cols = ['tenant_id', 'order_id', 'order_no', 'date', 'base_currency', 'cost_currency',
            'posted_cost', 'correct_cost', 'overstatement', 'fx_rate_cost', 'correction_aggregate',
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
                'posted_cost' => $r['posted_cost'], 'correct_cost' => $r['correct_cost'],
                'overstatement' => $r['overstatement'], 'action' => $r['action'],
            ], $rows), JSON_PRETTY_PRINT));
        }
    }
}
