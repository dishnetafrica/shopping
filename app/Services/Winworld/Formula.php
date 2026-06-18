<?php
namespace App\Services\Winworld;

/**
 * Winworld production formula engine. Pure logic, no framework.
 *
 * Confirmed formulas (from Winworld_Production_Planning_System.xlsx):
 *   gram/pcs = Width(inch) x Length(inch) x Gauge / 3300
 *   order kg = qty pcs x gram/pcs / 1000
 *   required hours = order kg / final output kg/hr
 *
 * A1: final output = manual (operator-entered) when set, else the advisory
 *     auto figure. Manual is the source of truth until the auto output-rate
 *     model is validated against real daily reports (design Q2).
 */
final class Formula
{
    public const GRAM_DIVISOR = 3300;

    /** Grams per piece from sheet/tube/bag dimensions. */
    public static function gramPerPcs(float $widthInch, float $lengthInch, float $gauge): float
    {
        if ($widthInch <= 0 || $lengthInch <= 0 || $gauge <= 0) return 0.0;
        return ($widthInch * $lengthInch * $gauge) / self::GRAM_DIVISOR;
    }

    /** Total kilograms for an order quantity. */
    public static function orderKg(int $qtyPcs, float $gramPerPcs): float
    {
        if ($qtyPcs <= 0 || $gramPerPcs <= 0) return 0.0;
        return ($qtyPcs * $gramPerPcs) / 1000;
    }

    /**
     * Resolve the output rate used for planning.
     * Manual wins when provided (> 0); otherwise the advisory auto figure.
     */
    public static function finalOutputKgHr(?float $auto, ?float $manual): float
    {
        if ($manual !== null && $manual > 0) return $manual;
        if ($auto !== null && $auto > 0)     return $auto;
        return 0.0;
    }

    /** Planned run time in hours for a job. Returns 0 if rate unknown. */
    public static function requiredHours(float $orderKg, float $finalOutputKgHr): float
    {
        if ($orderKg <= 0 || $finalOutputKgHr <= 0) return 0.0;
        return $orderKg / $finalOutputKgHr;
    }

    /** Elapsed hours between two timestamps (actuals capture). */
    public static function elapsedHours(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $secs = $end->getTimestamp() - $start->getTimestamp();
        return $secs > 0 ? $secs / 3600 : 0.0;
    }

    /** Output achieved per hour from recorded actuals. */
    public static function actualOutputKgHr(float $producedKg, float $actualHours): float
    {
        if ($producedKg <= 0 || $actualHours <= 0) return 0.0;
        return $producedKg / $actualHours;
    }

    public static function round2(float $v): float { return round($v, 2); }
    public static function round3(float $v): float { return round($v, 3); }
}
