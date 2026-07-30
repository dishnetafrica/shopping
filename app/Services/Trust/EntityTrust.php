<?php declare(strict_types=1);

namespace App\Services\Trust;

/**
 * Entity trust (Spec v1.0 §5) — decayed Beta-Bernoulli reputation.
 *
 * Two counters per entity: α (clean-weighted) and β (variance-weighted), decayed by λ per event so
 * memory spans roughly the last ~30 events. Cold start = population mean at LOW weight, so a new
 * actor's *uncertainty itself* demands more evidence for their first transactions — no special case
 * needed. Contribution to a transaction's prior is the clamped logit gap vs the population mean.
 *
 * Consequence rule (Spec §5.2): this value routes evidence requirements and review workload ONLY.
 * It must never be displayed as a ranking or drive any automatic personnel action.
 */
final class EntityTrust
{
    public const LAMBDA = 0.97;
    public const POPULATION_MEAN = 0.95;
    public const COLD_WEIGHT = 5.0;         // α+β at cold start (low → wide uncertainty)
    public const SEVERITY_FLOOR = 0.25;
    public const CONTRIB_WEIGHT = 0.8;

    /** @return array{alpha:float,beta:float} */
    public static function coldStart(): array
    {
        return [
            'alpha' => self::POPULATION_MEAN * self::COLD_WEIGHT,
            'beta'  => (1.0 - self::POPULATION_MEAN) * self::COLD_WEIGHT,
        ];
    }

    /** Register one observed outcome. $severity in [0,1] scales a variance's weight. */
    public static function update(float $alpha, float $beta, bool $clean, float $severity = 1.0): array
    {
        $severity = max(self::SEVERITY_FLOOR, min(1.0, $severity));
        $alpha = self::LAMBDA * $alpha + ($clean ? 1.0 : 0.0);
        $beta  = self::LAMBDA * $beta + ($clean ? 0.0 : $severity);
        return ['alpha' => $alpha, 'beta' => $beta];
    }

    /** Clamped logit contribution of this entity's reputation vs the population. */
    public static function contribution(float $alpha, float $beta, float $clamp): float
    {
        $n = $alpha + $beta;
        if ($n <= 0.0) {
            return 0.0;
        }
        $mean = min(max($alpha / $n, 1e-4), 1 - 1e-4);
        $raw = self::CONTRIB_WEIGHT * (self::logit($mean) - self::logit(self::POPULATION_MEAN));
        return max(-$clamp, min($clamp, $raw));
    }

    public static function logit(float $p): float
    {
        $p = min(max($p, 1e-6), 1 - 1e-6);
        return log($p / (1 - $p));
    }

    public static function sigmoid(float $l): float
    {
        return 1.0 / (1.0 + exp(-$l));
    }
}
