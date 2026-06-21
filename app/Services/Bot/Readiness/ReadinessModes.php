<?php

namespace App\Services\Bot\Readiness;

/**
 * Business Readiness — score band → classification → recommended operating mode. Pure logic.
 *
 * The recommended mode is only ever a *recommendation*. The AI never switches modes on its own;
 * the owner approves the mode in the panel. Autonomous is additionally gated on owner approval
 * (see BusinessReadinessEvaluator) so a brand-new, unapproved business is never told to go fully
 * autonomous.
 */
class ReadinessModes
{
    public const NOT_READY  = 'Not Ready';
    public const PILOT      = 'Pilot Mode';
    public const ASSISTED   = 'Assisted Mode';
    public const AUTONOMOUS = 'Autonomous Mode';

    public const MODE_MANUAL     = 'Manual Mode';
    public const MODE_SUGGESTION = 'AI Suggestion Mode';
    public const MODE_ASSISTED   = 'AI Assisted Mode';
    public const MODE_AUTONOMOUS = 'AI Autonomous Mode';

    public static function classify(int $overall): string
    {
        if ($overall >= 90) return self::AUTONOMOUS;
        if ($overall >= 75) return self::ASSISTED;
        if ($overall >= 50) return self::PILOT;
        return self::NOT_READY;
    }

    public static function modeFor(string $classification): string
    {
        return [
            self::NOT_READY  => self::MODE_MANUAL,
            self::PILOT      => self::MODE_SUGGESTION,
            self::ASSISTED   => self::MODE_ASSISTED,
            self::AUTONOMOUS => self::MODE_AUTONOMOUS,
        ][$classification] ?? self::MODE_MANUAL;
    }
}
