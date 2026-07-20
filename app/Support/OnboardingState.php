<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Onboarding state machine (Stage 0 rev 2).
 *
 * Stored as tenant.settings['onboarding_state'] — no schema change, atomic writes
 * via the row-locked putSetting.
 *
 * MONOTONIC by rule: transitions only move forward or into Failed. A stale retry
 * that arrives after the tenant is already WhatsAppReady is a no-op — we never
 * overwrite a newer successful state with an older one.
 */
final class OnboardingState
{
    public const PENDING         = 'Pending';
    public const TENANT_CREATED  = 'TenantCreated';
    public const PROVISIONING    = 'Provisioning';
    public const WHATSAPP_READY  = 'WhatsAppReady';
    public const WIZARD_COMPLETE = 'WizardComplete';
    public const OPERATIONAL     = 'Operational';
    public const FAILED          = 'Failed';

    /** Forward-only progression. Index = "rank" for monotonic comparison. */
    private const RANK = [
        self::PENDING         => 0,
        self::TENANT_CREATED  => 1,
        self::PROVISIONING    => 2,
        self::WHATSAPP_READY  => 3,
        self::WIZARD_COMPLETE => 4,
        self::OPERATIONAL     => 5,
    ];

    public static function current(Tenant $t): string
    {
        return (string) ($t->setting('onboarding_state', self::PENDING) ?: self::PENDING);
    }

    /**
     * Attempt a transition. Returns true if applied, false if rejected.
     *
     * Rules:
     *   - Forward moves in RANK are always allowed.
     *   - Transitioning TO Failed is allowed FROM any non-terminal state, but is
     *     rejected if the current state has already reached WhatsAppReady or later
     *     (a stale retry cannot un-succeed a completed provisioning).
     *   - Failed → any non-Failed forward state is allowed (retry recovery).
     *   - Same-state transitions are no-ops (return true).
     *   - Backward moves are always rejected.
     */
    public static function transition(Tenant $t, string $to): bool
    {
        $from = self::current($t);
        if ($from === $to) return true;

        if ($to === self::FAILED) {
            // Stale retry protection: never mark Failed if we've already succeeded.
            if ($from === self::WHATSAPP_READY
                || $from === self::WIZARD_COMPLETE
                || $from === self::OPERATIONAL) {
                return false;
            }
            $t->putSetting('onboarding_state', self::FAILED);
            return true;
        }

        if ($from === self::FAILED) {
            // Recovery path: Failed → any forward state.
            if (! isset(self::RANK[$to])) return false;
            $t->putSetting('onboarding_state', $to);
            return true;
        }

        // Normal forward-only progression.
        if (! isset(self::RANK[$from]) || ! isset(self::RANK[$to])) return false;
        if (self::RANK[$to] <= self::RANK[$from]) return false;

        $t->putSetting('onboarding_state', $to);
        return true;
    }

    /** True once the tenant reaches WhatsAppReady (used by other systems). */
    public static function isProvisioned(Tenant $t): bool
    {
        $s = self::current($t);
        return isset(self::RANK[$s]) && self::RANK[$s] >= self::RANK[self::WHATSAPP_READY];
    }
}
