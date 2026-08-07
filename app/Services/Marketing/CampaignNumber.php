<?php

namespace App\Services\Marketing;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * v4.49 — CMP-2026-0001. The fourth numbered document type, on the same counter as the other three.
 *
 * Deliberately a sibling of `QuoteNumber` and `InvoiceService::nextSeq` rather than a shared
 * abstraction: the series must be able to diverge — different prefix, different reset, different
 * padding — without one dragging the others with it. Copying forty lines is cheaper than the
 * coupling.
 *
 * Unlike quotations, campaigns had NO numbering before this, so there is no legacy mode and no
 * switch: anything created from now on gets a number. Anything already sent keeps `campaign_no`
 * null and is left exactly as it was.
 */
final class CampaignNumber
{
    /** The next number for this tenant, e.g. CMP-2026-0001. */
    public static function next(Tenant $t): string
    {
        $year   = (int) now()->format('Y');
        $prefix = strtoupper(trim((string) $t->setting('campaign_prefix', 'CMP'))) ?: 'CMP';
        $pad    = max(3, min(8, (int) ($t->setting('campaign_seq_pad', 4) ?: 4)));
        $byYear = strtolower((string) $t->setting('campaign_number_reset', 'year')) !== 'plain';

        $seq = self::nextSeq($t, $byYear ? $year : 0);

        return self::format($prefix, $byYear ? $year : 0, str_pad((string) $seq, $pad, '0', STR_PAD_LEFT));
    }

    /** Pure — the shape of a number, so the harness can assert it without a database. */
    public static function format(string $prefix, int $year, string $seq): string
    {
        return $year > 0 ? "{$prefix}-{$year}-{$seq}" : "{$prefix}-{$seq}";
    }

    /**
     * Atomically bump and return the counter for (tenant, year).
     *
     * insertOrIgnore BEFORE lockForUpdate, so the very first campaign of a year is not a race
     * between two requests that both find no row and both create one.
     */
    private static function nextSeq(Tenant $t, int $year): int
    {
        DB::table('campaign_counters')->insertOrIgnore([
            'tenant_id' => $t->id, 'year' => $year, 'last_seq' => self::seedFrom($t, $year),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::transaction(function () use ($t, $year) {
            $row = DB::table('campaign_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->lockForUpdate()->first();
            $seq = ((int) ($row->last_seq ?? 0)) + 1;
            DB::table('campaign_counters')
                ->where('tenant_id', $t->id)->where('year', $year)
                ->update(['last_seq' => $seq, 'updated_at' => now()]);

            return $seq;
        });
    }

    /**
     * Where to start if the counter row is missing — the highest number already issued under this
     * prefix, not 1. A restore from a partial backup would otherwise hand out a number a campaign
     * already has, and the unique index would reject the save outright.
     */
    private static function seedFrom(Tenant $t, int $year): int
    {
        try {
            $prefix = strtoupper(trim((string) $t->setting('campaign_prefix', 'CMP'))) ?: 'CMP';
            $like = $year > 0 ? "{$prefix}-{$year}-%" : "{$prefix}-%";
            $max = 0;
            foreach (DB::table('campaigns')->where('tenant_id', $t->id)
                        ->whereNotNull('campaign_no')->where('campaign_no', 'like', $like)
                        ->pluck('campaign_no') as $no) {
                $tail = (int) preg_replace('/\D/', '', (string) substr((string) $no, strrpos((string) $no, '-') + 1));
                if ($tail > $max) { $max = $tail; }
            }

            return $max;
        } catch (\Throwable $e) {
            return 0;   // column or table not migrated yet
        }
    }
}
