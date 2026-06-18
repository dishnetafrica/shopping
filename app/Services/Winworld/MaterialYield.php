<?php
namespace App\Services\Winworld;

/**
 * Material yield for a converter: resin-in vs good-kg-out, plus regrind recovery.
 * Pure logic. When the operator hasn't recorded resin input, input falls back to
 * produced + scrap so a sensible yield still shows (sharpens once input is logged).
 */
final class MaterialYield
{
    public static function inputKg(array $e): float
    {
        $in = (float) ($e['input_kg'] ?? 0);
        if ($in > 0) return $in;
        return (float) ($e['produced_kg'] ?? 0) + (float) ($e['scrap_kg'] ?? 0);
    }

    /** Aggregate yield over a set of production entries. */
    public static function rollup(array $entries): array
    {
        $in = 0.0; $good = 0.0; $scrap = 0.0; $regrind = 0.0;
        foreach ($entries as $e) {
            $prod = (float) ($e['produced_kg'] ?? 0);
            $sc   = (float) ($e['scrap_kg'] ?? 0);
            $in      += self::inputKg($e);
            $good    += max(0.0, $prod - $sc);
            $scrap   += $sc;
            $regrind += (float) ($e['regrind_kg'] ?? 0);
        }
        return [
            'input_kg'             => round($in, 1),
            'good_kg'              => round($good, 1),
            'scrap_kg'             => round($scrap, 1),
            'regrind_kg'           => round($regrind, 1),
            'yield_pct'            => $in > 0 ? round($good / $in * 100, 1) : 0.0,
            'waste_pct'            => $in > 0 ? round(($in - $good) / $in * 100, 1) : 0.0,
            'regrind_recovery_pct' => $scrap > 0 ? round(min(100.0, $regrind / $scrap * 100), 1) : 0.0,
        ];
    }
}
