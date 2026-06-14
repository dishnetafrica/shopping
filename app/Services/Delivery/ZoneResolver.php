<?php

namespace App\Services\Delivery;

use App\Models\DeliveryZone;

/**
 * Resolves a delivery location to a zone + fee + ETA. Matching order:
 *   1. area keyword in the customer's location text
 *   2. customer pin inside a zone's center+radius
 *   3. tenant distance-rule fallback (the pre-existing base/per-km/min/free-over)
 * The core (matchZone / computeFee / haversineKm / etaAt) is PURE and unit-tested.
 */
final class ZoneResolver
{
    /**
     * @param array $zones  list of zone arrays (id,name,match_keywords[],center_lat,center_lng,
     *                       radius_m,flat_fee,per_km_fee,min_fee,free_over,eta_minutes)
     * @return array|null   the matched zone array, or null
     */
    public static function matchZone(string $locationText, ?float $lat, ?float $lng, array $zones): ?array
    {
        $loc = mb_strtolower(trim($locationText));

        // 1) keyword match on the location text
        if ($loc !== '') {
            foreach ($zones as $z) {
                foreach ((array) ($z['match_keywords'] ?? []) as $kw) {
                    $kw = mb_strtolower(trim((string) $kw));
                    if ($kw !== '' && str_contains($loc, $kw)) return $z;
                }
            }
        }
        // 2) pin inside center+radius (closest matching zone wins)
        if ($lat !== null && $lng !== null) {
            $best = null; $bestKm = PHP_FLOAT_MAX;
            foreach ($zones as $z) {
                if (!isset($z['center_lat'], $z['center_lng'], $z['radius_m'])) continue;
                if ($z['center_lat'] === null || $z['center_lng'] === null || !$z['radius_m']) continue;
                $km = self::haversineKm($lat, $lng, (float) $z['center_lat'], (float) $z['center_lng']);
                if ($km * 1000 <= (float) $z['radius_m'] && $km < $bestKm) { $best = $z; $bestKm = $km; }
            }
            if ($best) return $best;
        }
        return null;
    }

    /**
     * Compute the delivery fee (integer currency units).
     * @param array|null $zone   matched zone array, or null to use the fallback rule
     * @param array $fallback    tenant rule ['base'=>,'per_km'=>,'min'=>,'free_over'=>] (existing setting)
     */
    public static function computeFee(?array $zone, int $subtotal, ?float $distanceKm, array $fallback): int
    {
        if ($zone) {
            $freeOver = $zone['free_over'] ?? null;
            if ($freeOver !== null && $freeOver > 0 && $subtotal >= (int) $freeOver) return 0;
            $fee = (int) ($zone['flat_fee'] ?? 0);
            if (!empty($zone['per_km_fee']) && $distanceKm !== null) {
                $fee += (int) round($distanceKm * (int) $zone['per_km_fee']);
            }
            $min = (int) ($zone['min_fee'] ?? 0);
            return max($fee, $min);
        }
        // fallback: tenant distance rule
        $freeOver = (int) ($fallback['free_over'] ?? 0);
        if ($freeOver > 0 && $subtotal >= $freeOver) return 0;
        $base  = (int) ($fallback['base'] ?? 0);
        $perKm = (int) ($fallback['per_km'] ?? 0);
        $min   = (int) ($fallback['min'] ?? 0);
        $fee   = $base + ($distanceKm !== null ? (int) round($distanceKm * $perKm) : 0);
        return max($fee, $min);
    }

    /** ETA timestamp (epoch) from now + zone minutes (default 45). */
    public static function etaSeconds(?array $zone, int $now, int $defaultMinutes = 45): int
    {
        $mins = $zone ? (int) ($zone['eta_minutes'] ?? $defaultMinutes) : $defaultMinutes;
        return $now + ($mins * 60);
    }

    /** Great-circle distance in km. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Instance helper: load active zones for the current tenant and quote. */
    public function quote(string $locationText, ?float $lat, ?float $lng, int $subtotal, array $fallback, ?float $storeLat = null, ?float $storeLng = null): array
    {
        $zones = DeliveryZone::query()->where('active', true)->get()
            ->map(fn ($z) => $z->only(['id','name','match_keywords','center_lat','center_lng','radius_m',
                'flat_fee','per_km_fee','min_fee','free_over','eta_minutes','default_rider_id']))->all();

        $zone = self::matchZone($locationText, $lat, $lng, $zones);
        $distance = ($lat !== null && $lng !== null && $storeLat !== null && $storeLng !== null)
            ? self::haversineKm($storeLat, $storeLng, $lat, $lng) : null;

        $fee = self::computeFee($zone, $subtotal, $distance, $fallback);
        $eta = self::etaSeconds($zone, time());

        return ['zone' => $zone, 'fee' => $fee, 'distance_km' => $distance, 'eta_at' => $eta];
    }
}
