<?php

namespace App\Services\Bot\Validation;

/**
 * Platform Validation Campaign — analytics + reporting. Pure logic, no framework deps, no new AI.
 *
 * Aggregates field-validation results (produced by the existing FieldValidationProgram /
 * ValidationComparator) into a business-type leaderboard, the five campaign questions, and the
 * monthly report. Everything here is descriptive statistics over data already collected.
 */
class CampaignMetrics
{
    /** Pull per-category accuracy from a stored comparator metrics array. */
    public static function categoryAccuracy(array $metrics): array
    {
        return [
            'products'  => (int) ($metrics['products']['recall'] ?? 0),
            'faqs'      => (int) ($metrics['faqs']['recall'] ?? 0),
            'delivery'  => (int) ($metrics['delivery']['recall'] ?? 0),
            'languages' => (int) ($metrics['languages']['recall'] ?? 0),
            'offers'    => (int) ($metrics['offers']['accuracy'] ?? 0),
        ];
    }

    /**
     * Business-type leaderboard, ranked by an onboarding-ease composite.
     * Rows are FieldValidation-shaped (owner_approved_accuracy/actual_accuracy,
     * owner_corrections_pct, time_to_go_live_min, readiness_score, ...).
     */
    public static function leaderboard(array $rows): array
    {
        $byType = [];
        foreach ($rows as $r) $byType[(string) ($r['business_type'] ?? 'unknown')][] = $r;

        $board = [];
        foreach ($byType as $type => $g) {
            $accuracy    = self::avgOf($g, 'accuracy');
            $corrections = self::avgOf($g, 'corrections');
            $time        = self::avgOf($g, 'time');
            $readiness   = self::avgOf($g, 'readiness');

            $board[] = [
                'business_type' => $type,
                'businesses'    => count($g),
                'avg_accuracy'  => $accuracy,
                'avg_corrections' => $corrections,
                'avg_time'      => $time,
                'avg_readiness' => $readiness,
                'ease_score'    => self::easeScore($accuracy, $corrections, $readiness, $time),
            ];
        }

        usort($board, fn ($a, $b) => $b['ease_score'] <=> $a['ease_score']);
        return $board;
    }

    /** Higher = easier to onboard. Accuracy & low corrections dominate. */
    private static function easeScore(int $accuracy, int $corrections, int $readiness, int $time): int
    {
        $timeScore = (int) max(0, 100 - max(0, $time - 15) * 4);   // 15 min = 100, 40 min = 0
        $score = 0.45 * $accuracy + 0.30 * (100 - $corrections) + 0.15 * $readiness + 0.10 * $timeScore;
        return (int) round($score);
    }

    /**
     * Answer the five campaign questions.
     * Rows need: business_type, accuracy, corrections, time, readiness, messages,
     * and the predictor features (messages, products_found, faq_found, delivery_rules_found).
     */
    public static function questions(array $rows): array
    {
        $board = self::leaderboard($rows);

        $mostCorr = null;
        foreach ($board as $b) {
            if ($mostCorr === null || $b['avg_corrections'] > $mostCorr['avg_corrections']) $mostCorr = $b;
        }

        // messages needed = avg messages among businesses that reached the go-live band
        $success = array_values(array_filter($rows, fn ($r) => self::val($r, 'readiness') >= 70));
        $msgsNeeded = $success ? self::avgOf($success, 'messages') : self::avgOf($rows, 'messages');

        // what predicts success: correlation of each feature with accuracy
        $target = array_map(fn ($r) => (float) self::val($r, 'accuracy'), $rows);
        $features = ['messages_scanned', 'products_found', 'faq_found', 'delivery_rules_found'];
        $corrs = [];
        foreach ($features as $f) {
            $xs = array_map(fn ($r) => (float) ($r[$f] ?? 0), $rows);
            $corrs[$f] = self::pearson($xs, $target);
        }
        arsort($corrs);
        $bestFeature = array_key_first($corrs);

        return [
            'easiest_type'         => $board[0]['business_type'] ?? null,
            'easiest_score'        => $board[0]['ease_score'] ?? 0,
            'most_corrections_type'=> $mostCorr['business_type'] ?? null,
            'most_corrections_pct' => $mostCorr['avg_corrections'] ?? 0,
            'messages_needed'      => $msgsNeeded,
            'avg_readiness'        => self::avgOf($rows, 'readiness'),
            'best_predictor'       => ['feature' => $bestFeature, 'correlation' => round($corrs[$bestFeature] ?? 0, 2)],
            'predictor_ranking'    => array_map(fn ($f, $r) => ['feature' => $f, 'correlation' => round($r, 2)], array_keys($corrs), array_values($corrs)),
        ];
    }

    /** Assemble the monthly report. Reuses FieldMetrics for the success-criteria summary + verdict. */
    public static function monthlyReport(array $rows, string $period): array
    {
        $summary = FieldMetrics::summary($rows);
        return [
            'period'      => $period,
            'businesses'  => count($rows),
            'leaderboard' => self::leaderboard($rows),
            'questions'   => self::questions($rows),
            'criteria'    => $summary['criteria'] ?? [],
            'meets_criteria' => $summary['meets_criteria'] ?? false,
            'verdict'     => FieldMetrics::verdict($summary),
            'category_avg'=> self::categoryAverages($rows),
        ];
    }

    /** Average per-category accuracy across the cohort. */
    public static function categoryAverages(array $rows): array
    {
        $cats = ['products_accuracy', 'faq_accuracy', 'delivery_accuracy', 'offer_accuracy', 'language_accuracy'];
        $out = [];
        foreach ($cats as $c) {
            $vals = array_filter(array_map(fn ($r) => $r[$c] ?? null, $rows), fn ($v) => $v !== null);
            $out[$c] = $vals ? (int) round(array_sum($vals) / count($vals)) : 0;
        }
        return $out;
    }

    // --------------------------------------------------------------------- helpers

    /** Resolve a logical metric from FieldValidation-shaped (or short-key) rows. */
    private static function val(array $r, string $metric): float
    {
        switch ($metric) {
            case 'accuracy':
                return (float) ($r['owner_approved_accuracy'] ?? $r['actual_accuracy'] ?? $r['accuracy'] ?? 0);
            case 'corrections':
                return (float) ($r['owner_corrections_pct'] ?? $r['corrections'] ?? 0);
            case 'time':
                return (float) ($r['time_to_go_live_min'] ?? $r['time'] ?? 0);
            case 'readiness':
                return (float) ($r['readiness_score'] ?? $r['readiness'] ?? 0);
            case 'messages':
                return (float) ($r['messages_scanned'] ?? $r['messages'] ?? 0);
        }
        return 0.0;
    }

    private static function avgOf(array $rows, string $metric): int
    {
        if (! $rows) return 0;
        return (int) round(array_sum(array_map(fn ($r) => self::val($r, $metric), $rows)) / count($rows));
    }

    /** Pearson correlation; 0 when undefined (no variance). */
    public static function pearson(array $xs, array $ys): float
    {
        $n = min(count($xs), count($ys));
        if ($n < 2) return 0.0;
        $xs = array_slice(array_values($xs), 0, $n);
        $ys = array_slice(array_values($ys), 0, $n);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = $sx = $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my;
            $sxy += $dx * $dy; $sx += $dx * $dx; $sy += $dy * $dy;
        }
        if ($sx <= 0 || $sy <= 0) return 0.0;
        return $sxy / sqrt($sx * $sy);
    }
}
