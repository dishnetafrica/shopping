<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Thin observability layer over bot_events (Stage 0 rev 2).
 *
 * Every onboarding event carries:
 *   - stage: named step (signup_complete, provision_start, wa_created, ...)
 *   - ms:    duration in milliseconds (nullable — some events are instantaneous)
 *   - detail: JSON string with { v:1, corr, retry, actor, extra? }
 *
 * Piggybacks on the existing bot_events table (no migration).
 * Filter with: WHERE trace='onboarding' AND tenant_id=X ORDER BY id
 */
final class OnboardingTrace
{
    /** Trace schema version — bump when the detail shape changes. */
    private const VERSION = 1;

    public static function log(
        int $tenantId,
        string $stage,
        ?int $durationMs = null,
        ?string $correlationId = null,
        int $retry = 0,
        string $actor = 'system',
        ?array $extra = null,
        ?string $phone = null,
    ): void {
        try {
            $detail = [
                'v'     => self::VERSION,
                'corr'  => $correlationId,
                'retry' => $retry,
                'actor' => $actor,
            ];
            if ($extra !== null && $extra !== []) {
                $detail['extra'] = $extra;
            }

            DB::table('bot_events')->insert([
                'tenant_id'  => $tenantId,
                'trace'      => 'onboarding',
                'phone'      => $phone ? substr($phone, 0, 32) : null,
                'stage'      => substr($stage, 0, 64),
                'detail'     => substr(json_encode($detail, JSON_UNESCAPED_SLASHES), 0, 300),
                'ms'         => $durationMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let observability break the operation it observes.
        }
    }

    /** Convenience: generate a fresh correlation ID for a new onboarding attempt. */
    public static function newCorrelationId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 12);
    }
}
