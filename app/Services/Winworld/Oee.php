<?php
namespace App\Services\Winworld;

/**
 * OEE engine - the north-star metric. OEE = Availability x Performance x Quality.
 *
 *   Availability = run hours / planned hours        (loss: downtime, idle, setup)
 *   Performance  = actual output / target output    (loss: running slow)
 *   Quality      = good kg / produced kg            (loss: scrap, rejects, rework)
 *
 * Availability and Quality are clamped to [0,1]. Raw performance can exceed
 * 1.0 (running faster than standard); for the OEE product it is capped at 1.0,
 * while efficiency_pct is reported uncapped (matches the workbook's
 * Efficiency % = actual / target).
 */
final class Oee
{
    private static function clamp01(float $v): float { return $v < 0 ? 0.0 : ($v > 1 ? 1.0 : $v); }

    public static function availability(float $runHours, float $plannedHours): float
    {
        if ($plannedHours <= 0) return 0.0;
        return self::clamp01($runHours / $plannedHours);
    }

    /** Uncapped ratio actual/target (the workbook's efficiency, as a fraction). */
    public static function performanceRaw(float $actualKgHr, float $targetKgHr): float
    {
        if ($targetKgHr <= 0) return 0.0;
        return $actualKgHr / $targetKgHr;
    }

    public static function quality(float $producedKg, float $scrapKg): float
    {
        if ($producedKg <= 0) return 0.0;
        $good = $producedKg - max(0.0, $scrapKg);
        return self::clamp01($good / $producedKg);
    }

    /**
     * @return array{availability:float,performance:float,performance_raw:float,quality:float,oee:float,efficiency_pct:float}
     */
    public static function compute(float $runHours, float $plannedHours, float $actualKgHr, float $targetKgHr, float $producedKg, float $scrapKg): array
    {
        $a   = self::availability($runHours, $plannedHours);
        $pRaw = self::performanceRaw($actualKgHr, $targetKgHr);
        $p   = self::clamp01($pRaw);
        $q   = self::quality($producedKg, $scrapKg);
        return [
            'availability'    => round($a, 4),
            'performance'     => round($p, 4),
            'performance_raw' => round($pRaw, 4),
            'quality'         => round($q, 4),
            'oee'             => round($a * $p * $q, 4),
            'efficiency_pct'  => round($pRaw * 100, 2),
        ];
    }
}
