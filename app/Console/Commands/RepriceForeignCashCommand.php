<?php

namespace App\Console\Commands;

use App\Models\OrderCost;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair the USD valuation of foreign-currency cashbook entries whose stored rate_to_usd
 * diverged from the dollars that actually moved.
 *
 * Root cause (fixed forward in TradeCashbookController::convert): a currency conversion stamped the
 * SYSTEM DEFAULT rate on the target (e.g. SSP) leg instead of the real deal rate, so the row held a
 * correct usd_amount but an inconsistent rate_to_usd. The cashbook sums by rate_to_usd, so a person's
 * foreign cash was mis-valued (SSP inflated).
 *
 * How each entry is corrected (no guesswork):
 *   1. CONVERSION legs — usd_amount is the real money that moved, so the true rate is
 *      native_amount / usd_amount. We snap rate_to_usd to that and leave usd_amount untouched.
 *   2. Other entries (spends/receipts) — valued at the effective DATED rate for the entry's date,
 *      taken from the tenant fx schedule (below). usd_amount is recomputed = native / rate.
 *      Only entries whose rate deviates > tolerance from the dated rate are touched (catches typos
 *      like a 20,000 rate), so correctly-booked entries are left exactly as they are.
 *
 * DRY RUN by default — prints every proposed change and the new per-holder totals, writes nothing.
 * With --commit it writes inside a transaction AND stores every original value in the tenant setting
 * `cash_reprice_backup` so the whole run can be reversed with --rollback.
 *
 *   php artisan cash:reprice-foreign --tenant=ID                 # dry run (safe, no writes)
 *   php artisan cash:reprice-foreign --tenant=ID --commit        # apply + save rollback snapshot
 *   php artisan cash:reprice-foreign --tenant=ID --rollback      # undo the last committed run
 */
final class RepriceForeignCashCommand extends Command
{
    protected $signature = 'cash:reprice-foreign
        {--tenant= : tenant id (required)}
        {--currency=SSP : currency to reprice (SSP or UGX)}
        {--tolerance=2 : %% deviation from the dated rate before a non-conversion entry is corrected}
        {--commit : actually write (default is a dry run)}
        {--rollback : restore the values saved by the last committed run}';

    protected $description = 'Repair foreign-currency cashbook USD valuations to the real dated rates (dry run unless --commit).';

    /**
     * Effective-dated rate schedule: native units per 1 USD, "from this date onward".
     * Derived from the ACTUAL conversions in the ledger (the USD that moved), confirmed with the owner:
     *   SSP: 6,718 (9 Jul) -> 7,000 (10 Jul) -> 7,200 (14 Jul) -> 8,000 (16 Jul)
     * Ascending by date; the last entry on/before a row's date applies.
     */
    private const SCHEDULE = [
        'SSP' => [
            ['from' => '2026-07-09', 'rate' => 6718.0],
            ['from' => '2026-07-10', 'rate' => 7000.0],
            ['from' => '2026-07-14', 'rate' => 7200.0],
            ['from' => '2026-07-16', 'rate' => 8000.0],
        ],
    ];

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $t = Tenant::find($id);
        if (! $t) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        // OrderCost + the ledger models are tenant-scoped: activate this tenant so their global scope
        // resolves to it (both the reprice path and rollback read OrderCost, and the GL revalue writes lines).
        app(\App\Support\TenantContext::class)->set($t->id);

        $cur = strtoupper((string) $this->option('currency'));
        if ($this->option('rollback')) {
            return $this->rollback($t, $cur);
        }
        if (! isset(self::SCHEDULE[$cur])) {
            $this->error("No dated schedule defined for {$cur}. Defined: " . implode(', ', array_keys(self::SCHEDULE)));
            return self::FAILURE;
        }

        $tol = max(0.0, (float) $this->option('tolerance')) / 100.0;
        $commit = (bool) $this->option('commit');

        $rows = OrderCost::where('tenant_id', $t->id)->where('currency', $cur)
            ->orderBy('created_at')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->info("No {$cur} cashbook entries for {$t->name}.");
            return self::SUCCESS;
        }

        $changes = [];       // rows we will write
        $backup  = [];       // originals, for rollback
        $holderNow = [];     // holder => usd before
        $holderNew = [];     // holder => usd after

        foreach ($rows as $c) {
            $native = (float) $c->native_amount;
            $oldRate = (float) $c->rate_to_usd;
            $oldUsd  = (float) $c->usd_amount;
            $date    = optional($c->created_at)->format('Y-m-d') ?: date('Y-m-d');
            $isConv  = trim((string) $c->category) === 'Conversion';

            // per-holder running USD, valued the way the cashbook values it (by rate_to_usd)
            $sign = in_array($c->stage, ['funding', 'cash_in', 'customer_payment'], true) ? 1 : -1;
            $paid = (float) ($c->paid_native ?? $native);
            $holder = (string) ($c->holder ?: '—');
            $holderNow[$holder] = ($holderNow[$holder] ?? 0) + $sign * ($oldRate > 0 ? $paid / $oldRate : 0);

            // decide the corrected rate
            $newRate = $oldRate;
            $reason  = '';
            if ($isConv && $oldUsd > 0) {
                // real money moved is in usd_amount -> true rate = native / usd_amount
                $cand = round($native / $oldUsd, 6);
                if (abs($cand - $oldRate) > 0.5) { $newRate = $cand; $reason = 'conversion leg → match real $ moved'; }
            } else {
                $dated = $this->datedRate($cur, $date);
                if ($dated > 0 && abs($oldRate - $dated) / $dated > $tol) {
                    $newRate = $dated; $reason = "off dated rate ({$dated}) by >" . ($tol * 100) . '%';
                }
            }

            $newUsd = $newRate > 0 ? round($native / $newRate, 2) : $oldUsd;
            $holderNew[$holder] = ($holderNew[$holder] ?? 0) + $sign * ($newRate > 0 ? $paid / $newRate : 0);

            if (abs($newRate - $oldRate) > 0.5 || abs($newUsd - $oldUsd) >= 0.01) {
                $ref = 'CB-' . str_pad((string) $c->id, 4, '0', STR_PAD_LEFT);
                $changes[] = [$ref, $date, $c->category ?: $c->stage, number_format($native, 0),
                    number_format($oldRate, 0) . ' → ' . number_format($newRate, 0),
                    '$' . number_format($oldUsd, 2) . ' → $' . number_format($newUsd, 2), $reason];
                $backup[]  = ['id' => $c->id, 'rate_to_usd' => $oldRate, 'usd_amount' => $oldUsd];
                if ($commit) {
                    $c->rate_to_usd = $newRate;
                    $c->usd_amount  = $newUsd;
                }
            }
        }

        $this->newLine();
        $this->info(($commit ? 'REPRICE' : 'DRY RUN') . " — {$cur} cashbook, {$t->name} (tenant {$t->id})");
        if (! $changes) {
            $this->info('  ✓ Every entry already reconciles. Nothing to change.');
            return self::SUCCESS;
        }
        $this->table(['Ref', 'Date', 'Type', ucfirst(strtolower($cur)), 'Rate', 'USD', 'Why'], $changes);

        $this->line('  Per-holder ' . $cur . ' value (as booked → corrected):');
        foreach ($holderNow as $h => $before) {
            $after = $holderNew[$h] ?? 0;
            if (abs($after - $before) < 0.01) continue;
            $this->line('    ' . str_pad($h, 20) . '$' . number_format($before, 2) . '  →  $' . number_format($after, 2));
        }
        $this->newLine();

        if (! $commit) {
            $this->warn('  DRY RUN — nothing written. Re-run with --commit once the numbers look right.');
            $this->line('  (--commit also saves a rollback snapshot; undo any time with --rollback.)');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $t, $cur, $backup) {
            foreach ($rows as $c) { if ($c->isDirty()) $c->save(); }
            $t->putSetting('cash_reprice_backup', ['currency' => $cur, 'rows' => $backup]);
        });
        $this->info('  ✓ Wrote ' . count($changes) . ' correction(s). Rollback snapshot saved.');
        $this->line('  Undo with:  php artisan cash:reprice-foreign --tenant=' . $t->id . ' --currency=' . $cur . ' --rollback');
        return self::SUCCESS;
    }

    private function datedRate(string $cur, string $date): float
    {
        $rate = 0.0;
        foreach (self::SCHEDULE[$cur] ?? [] as $row) {
            if ($row['from'] <= $date) $rate = (float) $row['rate'];
            else break;
        }
        return $rate ?: (float) (self::SCHEDULE[$cur][0]['rate'] ?? 0);
    }

    private function rollback(Tenant $t, string $cur): int
    {
        $snap = $t->setting('cash_reprice_backup');
        if (! is_array($snap) || empty($snap['rows'])) {
            $this->warn('  No rollback snapshot found for this tenant.');
            return self::FAILURE;
        }
        $n = 0;
        DB::transaction(function () use ($snap, &$n, $t) {
            foreach ($snap['rows'] as $r) {
                $c = OrderCost::where('tenant_id', $t->id)->find($r['id']);
                if (! $c) continue;
                $c->rate_to_usd = $r['rate_to_usd'];
                $c->usd_amount  = $r['usd_amount'];
                $c->save();
                $n++;
            }
            $t->putSetting('cash_reprice_backup', null);
        });
        $this->info("  ✓ Restored {$n} entr(ies) to their pre-reprice values. Snapshot cleared.");
        return self::SUCCESS;
    }
}
