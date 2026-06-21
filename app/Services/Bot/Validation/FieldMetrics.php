<?php

namespace App\Services\Bot\Validation;

/**
 * Field Validation — program metrics. Pure logic, no framework deps, no new intelligence.
 *
 * Turns per-business validation results (produced by the existing ValidationComparator) into the
 * program-level numbers the field study reports: owner corrections, cohort averages, the three
 * success criteria, and the one-line verdict.
 */
class FieldMetrics
{
    private const CATS = ['products', 'faqs', 'delivery', 'languages'];

    public const TARGET_ACCURACY    = 80;   // average actual accuracy ≥ 80%
    public const TARGET_TIME_MIN    = 30;   // average time-to-go-live ≤ 30 minutes
    public const TARGET_CORRECTIONS = 20;   // average owner corrections ≤ 20%

    /** Owner edits implied by a comparison: every false-positive + every missed fact. */
    public static function editsFromMetrics(array $metrics): int
    {
        $edits = 0;
        foreach (self::CATS as $c) {
            $m = $metrics[$c] ?? ['detected' => 0, 'actual' => 0, 'matched' => 0];
            $edits += max(0, (int) $m['detected'] - (int) $m['matched']);   // remove wrong
            $edits += max(0, (int) $m['actual'] - (int) $m['matched']);     // add missing
        }
        return $edits;
    }

    /** Total ground-truth facts across the compared categories. */
    public static function groundTruthSize(array $metrics): int
    {
        $n = 0;
        foreach (self::CATS as $c) $n += (int) ($metrics[$c]['actual'] ?? 0);
        return $n;
    }

    /** Owner corrections as a percentage of ground-truth facts (≤ 20% is the target). */
    public static function correctionsPct(int $edits, int $groundTruthSize): int
    {
        if ($groundTruthSize <= 0) return $edits > 0 ? 100 : 0;
        return (int) round(min(100, $edits / $groundTruthSize * 100));
    }

    /**
     * Aggregate a cohort. Each row: ['business_type','actual_accuracy','owner_approved_accuracy',
     * 'time_to_go_live_min','owner_corrections_pct'].
     */
    public static function summary(array $rows): array
    {
        if (! $rows) return ['businesses' => 0, 'meets_criteria' => false];

        $acc  = self::avg($rows, fn ($r) => $r['owner_approved_accuracy'] ?? $r['actual_accuracy'] ?? 0);
        $time = self::avg($rows, fn ($r) => $r['time_to_go_live_min'] ?? 0);
        $corr = self::avg($rows, fn ($r) => $r['owner_corrections_pct'] ?? 0);

        $byType = [];
        foreach (self::groupByType($rows) as $type => $group) {
            $byType[$type] = [
                'businesses'    => count($group),
                'avg_accuracy'  => self::avg($group, fn ($r) => $r['owner_approved_accuracy'] ?? $r['actual_accuracy'] ?? 0),
                'avg_time'      => self::avg($group, fn ($r) => $r['time_to_go_live_min'] ?? 0),
                'avg_corrections' => self::avg($group, fn ($r) => $r['owner_corrections_pct'] ?? 0),
            ];
        }

        return [
            'businesses'         => count($rows),
            'avg_accuracy'       => $acc,
            'avg_time_to_go_live'=> $time,
            'avg_corrections'    => $corr,
            'meets_criteria'     => self::meetsCriteria($acc, $time, $corr),
            'criteria'           => [
                'accuracy'    => ['value' => $acc, 'target' => self::TARGET_ACCURACY, 'pass' => $acc >= self::TARGET_ACCURACY],
                'time'        => ['value' => $time, 'target' => self::TARGET_TIME_MIN, 'pass' => $time <= self::TARGET_TIME_MIN],
                'corrections' => ['value' => $corr, 'target' => self::TARGET_CORRECTIONS, 'pass' => $corr <= self::TARGET_CORRECTIONS],
            ],
            'by_type'            => $byType,
        ];
    }

    public static function meetsCriteria(int $avgAccuracy, int $avgTime, int $avgCorrections): bool
    {
        return $avgAccuracy >= self::TARGET_ACCURACY
            && $avgTime <= self::TARGET_TIME_MIN
            && $avgCorrections <= self::TARGET_CORRECTIONS;
    }

    /** The single question the field study answers. */
    public static function verdict(array $summary): array
    {
        $can = (bool) ($summary['meets_criteria'] ?? false);
        $n   = (int) ($summary['businesses'] ?? 0);
        $sentence = $can
            ? "Yes — across {$n} businesses, a new shop reaches operational readiness from WhatsApp history within the targets (≥80% accuracy, ≤30 min, ≤20% corrections)."
            : "Not yet — across {$n} businesses the cohort misses at least one target; see which criterion failed.";
        return ['can_go_operational' => $can, 'statement' => $sentence];
    }

    // --------------------------------------------------------------------- helpers

    private static function avg(array $rows, callable $f): int
    {
        if (! $rows) return 0;
        return (int) round(array_sum(array_map($f, $rows)) / count($rows));
    }

    private static function groupByType(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) $out[(string) ($r['business_type'] ?? 'unknown')][] = $r;
        return $out;
    }
}
