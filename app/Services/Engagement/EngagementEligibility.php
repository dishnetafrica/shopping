<?php declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * Phase 1 — the hard eligibility gate for re-engagement. PURE.
 *
 * This is where the existing consent/safety rules stay in control. A customer is contactable ONLY if
 * every rule passes; the score never overrides it. The rules mirror the ones the live send path already
 * enforces (MarketingConsent / CommSuppression / FrequencyGuard) so the engine inherits the STRICT
 * marketing regime, not a looser one:
 *
 *   1. opted_out or on the suppression list → never (authoritative do-not-contact).
 *   2. not explicitly opted in → never (a prior inbound is NOT consent).
 *   3. an active/handover conversation → never (don't cut across a live human/bot thread).
 *   4. contacted within the cooldown window → not yet (the 14–21 day rotation).
 *
 * The planner passes booleans it has already resolved from the real sources, so this stays a pure,
 * exhaustively testable decision. First failing rule wins, so `blocked` names the single reason.
 *
 * @return via decide(): array{eligible:bool, blocked:?string, next_eligible_in_days:int}
 */
final class EngagementEligibility
{
    public const NOT_OPTED_IN       = 'not_opted_in';
    public const OPTED_OUT          = 'opted_out';
    public const SUPPRESSED         = 'suppressed';
    public const ACTIVE_CONVERSATION = 'active_conversation';
    public const COOLDOWN           = 'cooldown';

    /**
     * @param  array<string,mixed>  $s  opted_in(bool), opted_out(bool), suppressed(bool),
     *                                  active_conversation(bool), days_since_contacted(?int)
     * @param  int  $cooldownDays  the configured re-contact cooldown (14–21)
     * @return array{eligible:bool, blocked:?string, next_eligible_in_days:int}
     */
    public static function decide(array $s, int $cooldownDays): array
    {
        $cooldownDays = max(0, $cooldownDays);
        $sinceC       = $s['days_since_contacted'] ?? null;

        // A cooldown that hasn't elapsed still tells us WHEN they become eligible again.
        $nextInDays = ($sinceC !== null && (int) $sinceC < $cooldownDays)
            ? max(0, $cooldownDays - (int) $sinceC)
            : 0;

        if (! empty($s['opted_out'])) {
            return self::no(self::OPTED_OUT, $nextInDays);
        }
        if (! empty($s['suppressed'])) {
            return self::no(self::SUPPRESSED, $nextInDays);
        }
        if (empty($s['opted_in'])) {
            return self::no(self::NOT_OPTED_IN, $nextInDays);
        }
        if (! empty($s['active_conversation'])) {
            return self::no(self::ACTIVE_CONVERSATION, $nextInDays);
        }
        if ($sinceC !== null && (int) $sinceC < $cooldownDays) {
            return self::no(self::COOLDOWN, $nextInDays);
        }

        return ['eligible' => true, 'blocked' => null, 'next_eligible_in_days' => 0];
    }

    private static function no(string $reason, int $nextInDays): array
    {
        return ['eligible' => false, 'blocked' => $reason, 'next_eligible_in_days' => $nextInDays];
    }
}
