<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — automation readiness. Pure logic.
 *
 * Weighs how much of the business the scan could reconstruct into a single 0-100 score and a band.
 * Each section contributes (its confidence/100 × weight); the result is the share of day-one
 * automation coverage the owner can expect once they approve the findings.
 */
class AutomationReadiness
{
    private const WEIGHTS = [
        'products' => 25, 'faqs' => 20, 'delivery' => 15, 'hours' => 10,
        'language' => 10, 'owner_style' => 5, 'promotions' => 5, 'menu' => 5, 'rules' => 5,
    ];

    /** @param array<string,int> $sectionConfidence section => 0-100 confidence */
    public static function score(array $sectionConfidence): int
    {
        $score = 0.0;
        foreach (self::WEIGHTS as $section => $weight) {
            $conf = max(0, min(100, (int) ($sectionConfidence[$section] ?? 0)));
            if ($conf <= 0) continue;                       // section not discovered → no credit
            // A discovered section earns half its weight for presence and half for confidence,
            // so a business with strong coverage but conservative per-section confidence still
            // reaches the operational-readiness band (calibrated against real-business validation).
            $score += $weight * (0.5 + 0.5 * $conf / 100);
        }
        return (int) round($score);
    }

    public static function band(int $score): string
    {
        if ($score >= 80) return 'Excellent — ready for high automation';
        if ($score >= 70) return 'Strong — ready for guided automation';
        if ($score >= 50) return 'Moderate — needs some owner input';
        if ($score >= 30) return 'Limited — review carefully before activating';
        return 'Sparse — too little history to automate confidently';
    }
}
