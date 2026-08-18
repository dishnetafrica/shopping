<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

/**
 * Per-campaign daily-batch planning maths. PURE.
 *
 * Given an audience size and a "send X per day" pace, it answers the two questions the owner asks
 * when spreading a campaign to avoid a single-day blast: how many DAYS will it take, and how many go
 * out each day (a full X every day, with the remainder on the last day).
 */
final class BatchPlan
{
    /** Whole days needed to send `total` at `perDay`. 0 audience → 0 days; perDay ≤ 0 → 1 (all at once). */
    public static function days(int $total, int $perDay): int
    {
        $total = max(0, $total);
        if ($total === 0) {
            return 0;
        }
        if ($perDay <= 0) {
            return 1;                                   // no batching → one send
        }

        return (int) ceil($total / $perDay);
    }

    /**
     * Per-day breakdown, e.g. total=100, perDay=40 → [40, 40, 20]. Capped at $maxDays entries so a huge
     * audience with a tiny pace can't produce an unbounded array (the caller shows "… + N more days").
     *
     * @return array<int,int>
     */
    public static function breakdown(int $total, int $perDay, int $maxDays = 14): array
    {
        $total = max(0, $total);
        if ($total === 0 || $perDay <= 0) {
            return $total > 0 ? [$total] : [];
        }

        $out = [];
        $left = $total;
        while ($left > 0 && count($out) < $maxDays) {
            $take = min($perDay, $left);
            $out[] = $take;
            $left -= $take;
        }

        return $out;
    }
}
