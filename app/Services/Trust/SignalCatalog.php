<?php declare(strict_types=1);

namespace App\Services\Trust;

/**
 * Inventory Trust Engine v1 — signal catalog (Spec v1.0 §3–4).
 *
 * Hand-set logits, versioned in code so every score is reproducible. The points ARE log-odds
 * (FICO-style scorecard), so when labels accumulate these constants are simply replaced by fitted
 * logistic coefficients under the same feature names — no threshold in production breaks.
 *
 * Independence classes (Spec Rule A): S system-derived · P passive sensor · A actor-supplied ·
 * X counterparty. Positive contributions are capped PER CLASS so correlated signals from one
 * source cannot launder a lie; total evidence is saturated at ±LIMIT (Rule B).
 */
final class SignalCatalog
{
    public const VERSION = 1;

    /** Per-class caps on total POSITIVE evidence logit. */
    public const CLASS_CAPS = ['S' => 2.0, 'P' => 2.0, 'A' => 0.8, 'X' => 1.5];

    /** Saturation of total evidence contribution. */
    public const EVIDENCE_LIMIT = 4.0;

    /** Base clean rates per movement type → ℓ_base = logit(rate). */
    public const BASE_CLEAN = ['IN' => 0.95, 'OUT' => 0.96, 'MOVE' => 0.93, 'ADJUST' => 0.85];

    /** Prior-component clamps (Spec §3.2). */
    public const CLAMP_ACTOR = 1.0;
    public const CLAMP_VALUE = 0.8;
    public const CLAMP_PATTERN_TOTAL = -2.0;

    /** T_required(V) = T_MIN + T_K · log10(1 + V/V0), V0 derived from tenant median value. */
    public const T_MIN = 55.0;
    public const T_K = 13.0;
    public const T_MAX = 90.0;
    public const STEP_UP_BAND = 15.0;   // step_up when T_required − BAND ≤ T < T_required

    /**
     * Evidence signals available from ledger fields alone (v1 / backtest scope).
     * logit_missing applies when the signal is EXPECTED for the movement type but absent —
     * expected-but-missing is negative evidence, the engine's most important idea.
     *
     * @return array<string, array{class:string, present:float, missing:float, applies:string[]}>
     */
    public static function signals(): array
    {
        return [
            'ref_linked'  => ['class' => 'S', 'present' => 1.10, 'missing' => -0.60,
                              'applies' => ['IN', 'OUT', 'MOVE', 'ADJUST']],
            'actor_known' => ['class' => 'S', 'present' => 0.30, 'missing' => -0.50,
                              'applies' => ['IN', 'OUT', 'MOVE', 'ADJUST']],
            'note_present' => ['class' => 'A', 'present' => 0.25, 'missing' => -0.30,
                              'applies' => ['ADJUST']],   // an unexplained adjustment is a red flag

            // Runtime evidence (Phase B0+, attached via trust_evidence_items — not synthesized in backtest)
            'scale_weight'      => ['class' => 'P', 'present' => 1.20, 'missing' => -1.00, 'applies' => ['IN']],
            'grn_photo'         => ['class' => 'A', 'present' => 0.50, 'missing' => -0.30, 'applies' => ['IN']],
            'dock_clip'         => ['class' => 'P', 'present' => 0.90, 'missing' => -0.30, 'applies' => ['IN']],
            'counterparty_ack'  => ['class' => 'X', 'present' => 1.00, 'missing' => -0.40, 'applies' => ['MOVE']],
            'second_pin'        => ['class' => 'X', 'present' => 0.60, 'missing' =>  0.00, 'applies' => ['ADJUST', 'MOVE']],
            'cycle_count_match' => ['class' => 'S', 'present' => 1.50, 'missing' =>  0.00, 'applies' => ['IN', 'OUT', 'MOVE', 'ADJUST']],
        ];
    }

    /** Pattern flags (prior side, Spec §3.2 row 5). */
    public const PATTERN_OFF_HOURS = -0.60;   // outside 06:00–22:00 local
    public const PATTERN_BURST = -0.50;       // ≥4 same-type movements, same actor+sku, 7 days
    public const PATTERN_ROUND_QTY = -0.30;   // suspiciously round large quantities on OUT/ADJUST
    public const PATTERN_NEGATIVE_ADJUST = -0.40; // shrink-shaped adjustment

    /** Value-band prior: ratio of movement value to tenant median. */
    public static function valueBandLogit(float $value, float $median): float
    {
        if ($median <= 0) {
            return 0.0;
        }
        $r = $value / $median;
        if ($r < 0.5) return 0.30;
        if ($r < 2.0) return 0.00;
        if ($r < 10.0) return -0.30;
        return -0.80;
    }
}
