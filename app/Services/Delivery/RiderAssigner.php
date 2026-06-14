<?php

namespace App\Services\Delivery;

/** Pure least-loaded rider suggestion (manual assignment stays the default). */
final class RiderAssigner
{
    /**
     * @param array $openCounts  [rider_id => open_delivery_count] for ACTIVE riders only
     * @param int|null $zoneDefaultRiderId  zone's default rider, if any
     * @return int|null suggested rider id
     */
    public static function suggest(array $openCounts, ?int $zoneDefaultRiderId = null): ?int
    {
        // Prefer the zone's default rider when they exist and aren't overloaded
        // relative to the lightest option.
        $least = null; $leastN = PHP_INT_MAX;
        foreach ($openCounts as $rid => $n) {
            if ($n < $leastN || ($n === $leastN && ($least === null || $rid < $least))) {
                $least = (int) $rid; $leastN = (int) $n;
            }
        }
        if ($zoneDefaultRiderId !== null && array_key_exists($zoneDefaultRiderId, $openCounts)) {
            $zoneLoad = (int) $openCounts[$zoneDefaultRiderId];
            if ($zoneLoad <= $leastN + 1) return $zoneDefaultRiderId;   // small tolerance
        }
        return $least;
    }
}
