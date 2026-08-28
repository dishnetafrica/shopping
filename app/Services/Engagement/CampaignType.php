<?php declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * Phase 1 — decide WHICH kind of re-engagement a customer should get. PURE.
 *
 * Deterministic classification into the five campaign types from the customer's own signals, checked
 * most-specific first. This only labels the intent for the daily plan; Phase 1 sends nothing and picks
 * no template — the label is what the dashboard shows and what Phase 2 will map to an approved template.
 */
final class CampaignType
{
    public const DORMANT_WINBACK = 'dormant_winback';
    public const REPLENISHMENT   = 'replenishment';
    public const VIP_OFFER       = 'vip_offer';
    public const RECOMMENDATION  = 'recommendation';
    public const NEW_STOCK       = 'new_stock';

    public const LABEL = [
        self::DORMANT_WINBACK => 'Win-back (dormant)',
        self::REPLENISHMENT   => 'Replenishment',
        self::VIP_OFFER       => 'VIP offer',
        self::RECOMMENDATION  => 'Recommendation',
        self::NEW_STOCK       => 'New stock',
    ];

    /**
     * @param  array<string,mixed>  $s  flags(string[]), stage, reorder_state, has_recommendation(bool)
     */
    public static function decide(array $s): string
    {
        $flags = array_map('strtoupper', (array) ($s['flags'] ?? []));
        $stage = strtoupper((string) ($s['stage'] ?? ''));
        $state = (string) ($s['reorder_state'] ?? '');

        // 1. A dormant/lapsed past buyer (or explicit win-back flag) → bring them back first.
        if ($stage === 'DORMANT' || in_array('WINBACK', $flags, true) || $state === 'lapsed') {
            return self::DORMANT_WINBACK;
        }

        // 2. A live buyer who is due/overdue on their own cadence → replenishment reminder.
        if ($state === 'due' || $state === 'overdue') {
            return self::REPLENISHMENT;
        }

        // 3. A top customer with nothing urgent → priority/VIP offer.
        if (in_array('VIP', $flags, true) || $stage === 'VIP') {
            return self::VIP_OFFER;
        }

        // 4. We have a concrete cross-sell to suggest → product recommendation.
        if (! empty($s['has_recommendation'])) {
            return self::RECOMMENDATION;
        }

        // 5. Default: a relevant new-stock nudge.
        return self::NEW_STOCK;
    }
}
