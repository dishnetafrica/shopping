<?php

namespace App\Services\Sales;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * v4.45 — quotation numbers that run in order.
 *
 * WHAT WAS THERE
 * `ORD-Q260806-ZH9Z` — a prefix, the date, and four random characters. Two problems. Random
 * collides: v4.38 had to rename live duplicates before it could put a unique index on the column.
 * And random cannot be read: you cannot tell which of two quotations came first, you cannot say
 * "quote forty-one" on the phone, and you cannot glance at a number and know how many you have
 * raised this year.
 *
 * WHAT THIS IS
 * The same mechanism invoices already use (`InvoiceService::nextSeq`) — a per-tenant, per-year
 * counter bumped inside a row lock, so two quotations created in the same second cannot take the
 * same number. Deliberately a copy of that shape rather than a clever shared abstraction: the two
 * series must be able to diverge (different prefixes, resets, padding) without one dragging the
 * other with it.
 *
 * WHAT IT DOES NOT DO
 * It never renumbers anything. Existing `ORD-Q…` quotations keep the numbers their customers were
 * given — a document number a customer has seen is not ours to change. The new series runs under
 * a different prefix so the two can coexist forever without colliding.
 */
final class QuoteNumber
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_SEQUENTIAL = 'sequential';

    /** Is this tenant on the running series? Off unless the owner switched it on. */
    public static function sequential(Tenant $t): bool
    {
        return strtolower((string) $t->setting('quote_number_mode', self::MODE_LEGACY)) === self::MODE_SEQUENTIAL;
    }

    /**
     * The next number for this tenant, e.g. QUO-2026-0001.
     *
     * `plain` mode gives one series that never resets (QUO-0001, QUO-0002 …) for anyone who would
     * rather their numbering did not restart every January.
     */
    public static function next(Tenant $t): string
    {
        $year   = (int) now()->format('Y');
        $prefix = strtoupper(trim((string) $t->setting('quote_prefix', 'QUO'))) ?: 'QUO';
        $pad    = max(3, min(8, (int) $t->setting('quote_seq_pad', 4)));
        $byYear = strtolower((string) $t->setting('quote_number_reset', 'year')) !== 'plain';

        $seq = self::nextSeq($t, $byYear ? $year : 0);
        $seqStr = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

        return self::format($prefix, $byYear ? $year : 0, $seqStr);
    }

    /** Pure — the shape of a number, so the harness can assert it without a database. */
    public static function format(string $prefix, int $year, string $seq): string
    {
        return $year > 0 ? "{$prefix}-{$year}-{$seq}" : "{$prefix}-{$seq}";
    }

    /**
     * Atomically bump and return the counter for (tenant, year).
     *
     * The row is inserted first with insertOrIgnore so the lock below always has something to
     * lock — without it the very first quotation of a year is a race between two requests that
     * both find no row and both create one.
     */
    private static function nextSeq(Tenant $t, int $year): int
    {
        DB::table('quote_counters')->insertOrIgnore([
            'tenant_id' => $t->id, 'year' => $year, 'last_seq' => self::seedFrom($t, $year),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::transaction(function () use ($t, $year) {
            $row = DB::table('quote_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->lockForUpdate()->first();
            $seq = ((int) ($row->last_seq ?? 0)) + 1;
            DB::table('quote_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->update(['last_seq' => $seq, 'updated_at' => now()]);

            return $seq;
        });
    }

    /**
     * Where to start if the counter row does not exist yet.
     *
     * Normally zero. But if the switch was turned on, some quotations were issued, and the counter
     * row was later lost (a restore from a partial backup, a manual truncate), starting again at 1
     * would hand a customer a number another customer already has — and the unique index would
     * then reject the save outright. So the seed is the highest sequence already issued under this
     * prefix. Costs one query, once per tenant per year, and only on the very first quotation.
     */
    private static function seedFrom(Tenant $t, int $year): int
    {
        try {
            $prefix = strtoupper(trim((string) $t->setting('quote_prefix', 'QUO'))) ?: 'QUO';
            $like = $year > 0 ? "{$prefix}-{$year}-%" : "{$prefix}-%";
            $max = 0;
            foreach (DB::table('quotations')->where('tenant_id', $t->id)
                        ->where('quote_no', 'like', $like)->pluck('quote_no') as $no) {
                $tail = (int) preg_replace('/\D/', '', (string) substr((string) $no, strrpos((string) $no, '-') + 1));
                if ($tail > $max) { $max = $tail; }
            }

            return $max;
        } catch (\Throwable $e) {
            return 0;   // a fresh install with no quotations table yet
        }
    }
}
