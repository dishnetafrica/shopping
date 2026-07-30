<?php declare(strict_types=1);

namespace App\Services\Trust;

/**
 * Inventory Trust Engine v1 scorer (Spec v1.0 §3–4).
 *
 *   ℓ(x) = ℓ_base(type) + Σ prior contributions + Σ evidence contributions
 *   T = 100 · σ(ℓ)   ·   decision = T vs T_required(value)
 *
 * Pure function of its inputs (Spec §3.5 determinism): no DB access, no clock reads — the caller
 * assembles context. Every score returns its full decomposition as reason codes; a score that
 * cannot explain itself in one WhatsApp message is a bug, not a feature.
 */
final class TrustScorer
{
    /**
     * @param string $type        IN|OUT|MOVE|ADJUST
     * @param float  $value       movement value in tenant base currency (qty × cost/price)
     * @param float  $medianValue tenant's median movement value (context, precomputed)
     * @param array  $actorStat   ['alpha'=>float,'beta'=>float] or null for unknown actor
     * @param array  $patterns    subset of ['off_hours','burst','round_qty','negative_adjust']
     * @param array  $evidence    signal_code => 'present_pass'|'present_fail'|'expected_missing'|'na'
     *                            (signals EXPECTED for the type but absent from this array are
     *                            treated as expected_missing automatically)
     *
     * @return array{trust:float,trust_required:float,decision:string,logit_base:float,
     *               logit_prior:float,logit_evidence:float,expected_loss:float,reason_codes:array}
     */
    public static function score(
        string $type,
        float $value,
        float $medianValue,
        ?array $actorStat,
        array $patterns,
        array $evidence,
    ): array {
        $reasons = [];

        // ---- base ----
        $base = EntityTrust::logit(SignalCatalog::BASE_CLEAN[$type] ?? 0.90);

        // ---- priors ----
        $prior = 0.0;

        $actor = 0.0;
        if ($actorStat !== null) {
            $actor = EntityTrust::contribution(
                (float) $actorStat['alpha'], (float) $actorStat['beta'], SignalCatalog::CLAMP_ACTOR);
        }
        if (abs($actor) > 0.01) {
            $reasons[] = ['code' => 'actor_history', 'contribution' => round($actor, 3)];
        }
        $prior += $actor;

        $band = SignalCatalog::valueBandLogit($value, $medianValue);
        if (abs($band) > 0.01) {
            $reasons[] = ['code' => 'value_band', 'contribution' => $band];
        }
        $prior += $band;

        $patternMap = [
            'off_hours' => SignalCatalog::PATTERN_OFF_HOURS,
            'burst' => SignalCatalog::PATTERN_BURST,
            'round_qty' => SignalCatalog::PATTERN_ROUND_QTY,
            'negative_adjust' => SignalCatalog::PATTERN_NEGATIVE_ADJUST,
        ];
        $patternSum = 0.0;
        foreach ($patterns as $p) {
            if (isset($patternMap[$p])) {
                $patternSum += $patternMap[$p];
                $reasons[] = ['code' => 'pattern_' . $p, 'contribution' => $patternMap[$p]];
            }
        }
        $prior += max(SignalCatalog::CLAMP_PATTERN_TOTAL, $patternSum);

        // ---- evidence (class caps on positives, saturation on total) ----
        $classPos = ['S' => 0.0, 'P' => 0.0, 'A' => 0.0, 'X' => 0.0];
        $negative = 0.0;
        foreach (SignalCatalog::signals() as $code => $def) {
            if (! in_array($type, $def['applies'], true)) {
                continue;
            }
            $status = $evidence[$code] ?? 'expected_missing';
            if ($status === 'na') {
                continue;
            }
            if ($status === 'present_pass') {
                $classPos[$def['class']] += $def['present'];
                $reasons[] = ['code' => $code, 'contribution' => $def['present']];
            } else { // present_fail or expected_missing → the missing/failing penalty
                if ($def['missing'] !== 0.0) {
                    $negative += $def['missing'];
                    $reasons[] = ['code' => $code . '_missing', 'contribution' => $def['missing']];
                }
            }
        }
        $positive = 0.0;
        foreach ($classPos as $cls => $sum) {
            $positive += min($sum, SignalCatalog::CLASS_CAPS[$cls]);
        }
        $ev = max(-SignalCatalog::EVIDENCE_LIMIT,
                  min(SignalCatalog::EVIDENCE_LIMIT, $positive + $negative));

        // ---- combine ----
        $l = $base + $prior + $ev;
        $trust = round(100.0 * EntityTrust::sigmoid($l), 2);

        $v0 = max($medianValue / 4.0, 0.01);
        $tReq = min(SignalCatalog::T_MAX,
                    SignalCatalog::T_MIN + SignalCatalog::T_K * log10(1.0 + $value / $v0));
        $tReq = round($tReq, 2);

        $decision = 'auto_post';
        if ($trust < $tReq - SignalCatalog::STEP_UP_BAND) {
            $decision = 'hold';
        } elseif ($trust < $tReq) {
            $decision = 'step_up';
        }

        usort($reasons, fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));

        return [
            'trust' => $trust,
            'trust_required' => $tReq,
            'decision' => $decision,
            'logit_base' => round($base, 3),
            'logit_prior' => round($prior, 3),
            'logit_evidence' => round($ev, 3),
            'expected_loss' => round((1.0 - $trust / 100.0) * $value, 2),
            'reason_codes' => $reasons,
        ];
    }
}
