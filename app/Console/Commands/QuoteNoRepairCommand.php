<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v5.07.1 — finish the job migration 2026_08_05_000001 reported as done.
 *
 * ── what went wrong ─────────────────────────────────────────────────────────────────────────────
 *
 * That migration was supposed to rename colliding quotation numbers and then put a UNIQUE index on
 * (tenant_id, quote_no), so a quote number means one quotation. It did neither, and said nothing:
 *
 *   1. the de-duplication used `->having('n', '>', 1)` against a SELECT alias, which PostgreSQL
 *      rejects in HAVING — "column \"n\" does not exist";
 *   2. that threw inside a `try { } catch (\Throwable $e) { }` whose comment reads "a box that
 *      cannot group here is a box that has no duplicates to fix", so the failure looked like an
 *      absence of duplicates;
 *   3. the unique index was then created inside a second identical catch — and on a table that
 *      still held its collisions, creating it fails too. Swallowed again.
 *
 * The migration therefore completed, was recorded as run, and achieved nothing. The only trace was
 * one line in the PostgreSQL server log. Confirmed on the live database on 14 Aug: pg_indexes lists
 * quotations_public_token_unique but no quotations_tenant_quote_no_unique.
 *
 * Editing the migration file does not help — Laravel will never run it again. Hence this.
 *
 * ── use ─────────────────────────────────────────────────────────────────────────────────────────
 *
 *   php artisan quotes:repair-numbers              # dry run: what collides, what would be renamed
 *   php artisan quotes:repair-numbers --write      # rename, then add the constraint
 *
 * A duplicate is renamed, never deleted — it is still somebody's quotation. The OLDEST row keeps
 * the number it was issued under, because that is the one the customer was given.
 */
final class QuoteNoRepairCommand extends Command
{
    protected $signature = 'quotes:repair-numbers
        {--write : apply it (default is a dry run)}';

    protected $description = 'Rename colliding quotation numbers and add the unique constraint the migration failed to add.';

    private const INDEX = 'quotations_tenant_quote_no_unique';

    public function handle(): int
    {
        if (! Schema::hasTable('quotations') || ! Schema::hasColumn('quotations', 'quote_no')) {
            $this->error('No quotations table / quote_no column here.');

            return self::FAILURE;
        }
        $write = (bool) $this->option('write');

        $hasIndex = collect(DB::select('select indexname from pg_indexes where tablename = ?', ['quotations']))
            ->pluck('indexname')->contains(self::INDEX);
        $this->line('');
        $this->info('QUOTATION NUMBER REPAIR' . ($write ? '  *** WRITE MODE ***' : '  (dry run)'));
        $this->line('unique index ' . self::INDEX . ' present: ' . ($hasIndex ? 'YES' : 'NO'));

        // The correct form: repeat the aggregate in HAVING. An alias is not visible there.
        $dupes = DB::table('quotations')
            ->select('tenant_id', 'quote_no', DB::raw('COUNT(*) as n'))
            ->whereNotNull('quote_no')->where('quote_no', '!=', '')
            ->groupBy('tenant_id', 'quote_no')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('tenant_id')->orderBy('quote_no')
            ->get();

        $this->line('colliding quote numbers: ' . $dupes->count());

        $renames = [];
        foreach ($dupes as $d) {
            $ids = DB::table('quotations')
                ->where('tenant_id', $d->tenant_id)->where('quote_no', $d->quote_no)
                ->orderBy('id')->pluck('id')->all();
            $keep = array_shift($ids);              // oldest keeps the number the customer was given
            foreach ($ids as $i => $id) {
                $renames[] = ['id' => $id, 'tenant_id' => $d->tenant_id,
                              'from' => $d->quote_no, 'to' => $d->quote_no . '-D' . ($i + 2), 'keeps' => $keep];
            }
        }

        if ($renames) {
            $this->table(['quotation id', 'tenant', 'number now', 'renamed to', 'kept by'],
                array_map(fn ($r) => [$r['id'], $r['tenant_id'], $r['from'], $r['to'], $r['keeps']],
                    array_slice($renames, 0, 40)));
            if (count($renames) > 40) { $this->line('   …and ' . (count($renames) - 40) . ' more'); }
        }

        // The migration's de-duplication excluded quote_no = '' — and so does the query above,
        // deliberately, because an empty string is not a number anybody was given. But PostgreSQL
        // does NOT treat two empty strings as distinct the way it treats two NULLs, so a second
        // blank row is enough to make CREATE UNIQUE INDEX fail. That is a third way the original
        // migration could have failed silently, and it would have failed the same way here.
        $blanks = (int) DB::table('quotations')->where('quote_no', '')->count();
        if ($blanks > 0) {
            $this->warn($blanks . ' quotation(s) have an EMPTY quote number. Two empty strings collide in a'
                . ' unique index (unlike two NULLs), so these are set to NULL — same meaning, no number.');
        }

        if ($hasIndex && ! $renames && $blanks === 0) {
            $this->info('Nothing to do — the constraint is in place and no numbers collide.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->line('');
            $this->comment(count($renames) . ' quotation(s) would be renamed'
                . ($blanks ? (', ' . $blanks . ' blank number(s) set to NULL') : '') . ', then the unique index'
                . ($hasIndex ? ' (already present)' : ' would be created') . '.');
            $this->comment('Nothing is deleted. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($renames, $blanks) {
            foreach ($renames as $r) {
                DB::table('quotations')->where('id', $r['id'])->update(['quote_no' => $r['to']]);
            }
            if ($blanks > 0) {
                DB::table('quotations')->where('quote_no', '')->update(['quote_no' => null]);
            }
        });
        $this->line(count($renames) . ' renamed' . ($blanks ? (', ' . $blanks . ' blanks set to NULL') : '') . '.');

        if (! $hasIndex) {
            try {
                DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON quotations (tenant_id, quote_no)');
                $this->info('Unique index created — a quote number now means one quotation.');
            } catch (\Throwable $e) {
                // Reported, not swallowed. Being told is the whole point of this command existing.
                $this->error('Could not create the index: ' . $e->getMessage());
                $this->warn('The renames above are committed; run the dry run again to see what still collides.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
