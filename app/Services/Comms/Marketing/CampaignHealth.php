<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\Tenant;

/**
 * Campaign health — GREEN / YELLOW / ORANGE / RED from delivery, failure and opt-out rates.
 *
 * DISPLAY ONLY in Phase 2: it never throttles, pauses, or changes sending. It is a signal for an
 * operator, computed from internal thresholds (tenant-configurable) that are explicitly NOT official
 * WhatsApp limits. Below a minimum sent volume it returns "new" rather than pretending a two-message
 * campaign has a meaningful failure rate.
 */
final class CampaignHealth
{
    public const MIN_VOLUME = 20;

    /** @param array<string,mixed> $stats  the CampaignService::stats() array */
    public static function assess(array $stats, Tenant $tenant): array
    {
        $sent = (int) ($stats['sent'] ?? 0);
        if ($sent < self::MIN_VOLUME) {
            return ['state' => 'new', 'label' => 'Too new to score', 'tone' => 'default', 'reasons' => []];
        }

        $fail    = (float) ($stats['failure_rate'] ?? 0);
        $optOut  = (float) ($stats['opt_out_rate'] ?? 0);
        $deliver = (float) ($stats['delivery_rate'] ?? 0);

        // Thresholds (percentages) — internal safety signals, configurable per tenant.
        $t = fn (string $k, float $d): float => (float) $tenant->setting($k, $d);
        [$failRed, $failOrange, $failYellow] = [$t('mkt_health_fail_red', 25), $t('mkt_health_fail_orange', 15), $t('mkt_health_fail_yellow', 8)];
        [$optRed, $optOrange, $optYellow]    = [$t('mkt_health_opt_red', 8), $t('mkt_health_opt_orange', 5), $t('mkt_health_opt_yellow', 3)];
        $deliverFloor = $t('mkt_health_delivery_floor', 70);

        $reasons = [];
        $state = 'green';
        $raise = function (string $to) use (&$state) {
            $rank = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];
            if ($rank[$to] > $rank[$state]) { $state = $to; }
        };

        if ($fail >= $failRed)        { $raise('red');    $reasons[] = "failure rate {$fail}%"; }
        elseif ($fail >= $failOrange) { $raise('orange'); $reasons[] = "failure rate {$fail}%"; }
        elseif ($fail >= $failYellow) { $raise('yellow'); $reasons[] = "failure rate {$fail}%"; }

        if ($optOut >= $optRed)        { $raise('red');    $reasons[] = "opt-out rate {$optOut}%"; }
        elseif ($optOut >= $optOrange) { $raise('orange'); $reasons[] = "opt-out rate {$optOut}%"; }
        elseif ($optOut >= $optYellow) { $raise('yellow'); $reasons[] = "opt-out rate {$optOut}%"; }

        if ($deliver > 0 && $deliver < $deliverFloor) { $raise('yellow'); $reasons[] = "delivery rate {$deliver}%"; }

        $label = ['green' => 'Healthy', 'yellow' => 'Watch', 'orange' => 'Review', 'red' => 'Stop & review'][$state];
        $tone  = ['green' => 'ok', 'yellow' => 'warn', 'orange' => 'warn', 'red' => 'danger'][$state];

        return ['state' => $state, 'label' => $label, 'tone' => $tone, 'reasons' => $reasons];
    }
}
