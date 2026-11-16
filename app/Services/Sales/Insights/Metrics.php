<?php

namespace App\Services\Sales\Insights;

/** PURE arithmetic for the sales dashboard and quality monitoring (DishNet plan, Phases 7–8). */
final class Metrics
{
    public const DAYS = 30;

    /** A customer counts as waiting when their message is the last one and is older than this. */
    public const WAITING_HOURS = 2;

    /** Median of numbers, or null for none. */
    public static function median(array $xs): ?float
    {
        $xs = array_values(array_filter($xs, fn ($x) => $x !== null));
        if (! $xs) {
            return null;
        }
        sort($xs);
        $n = count($xs);

        return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /** Won as a share of decided leads (won + lost), as a whole percent, or null. */
    public static function conversion(int $won, int $lost): ?int
    {
        return $won + $lost > 0 ? (int) round(100 * $won / ($won + $lost)) : null;
    }

    /**
     * Minutes from each lead's creation to the first reply a person sent that customer afterwards.
     * @param array $leads   list of ['phone'=>, 'created'=>unix]
     * @param array $replies phone => sorted list of unix times of staff replies
     * @return array<int,?float> per lead (null = no reply yet)
     */
    public static function firstReplyMinutes(array $leads, array $replies): array
    {
        $out = [];
        foreach ($leads as $l) {
            $m = null;
            foreach ($replies[$l['phone']] ?? [] as $t) {
                if ($t >= $l['created']) {
                    $m = round(($t - $l['created']) / 60, 1);
                    break;
                }
            }
            $out[] = $m;
        }

        return $out;
    }

    /** Is the customer waiting for us? Their last message is the newest one, and it is old enough. */
    public static function waiting(?int $lastInbound, ?int $lastMessage, int $now, int $hours = self::WAITING_HOURS): bool
    {
        return $lastInbound !== null && $lastMessage !== null && $lastInbound >= $lastMessage - 1 && ($now - $lastInbound) >= $hours * 3600;
    }

    /** "45 min", "3.5 h", "2 days". */
    public static function duration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return round($minutes) . ' min';
        }
        if ($minutes < 48 * 60) {
            return rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.') . ' h';
        }

        return round($minutes / 1440) . ' days';
    }
}
