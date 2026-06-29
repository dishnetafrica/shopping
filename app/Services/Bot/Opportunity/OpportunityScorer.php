<?php
namespace App\Services\Bot\Opportunity;

/**
 * Turns an opportunity profile into a 0-100 lead score (with a transparent breakdown)
 * and a size band. Every captured fact raises knowledge/score, so a salesperson sees
 * priority at a glance. Pure function of the profile — deterministic, explainable, and
 * later replaceable by a data-driven model once we have enough won/lost outcomes.
 */
final class OpportunityScorer
{
    /** @return array{score:int,breakdown:array<string,int>} */
    public function score(string $type, array $profile): array
    {
        $b = [];

        // Clear B2B intent is the entry signal.
        $b['intent'] = 25;

        // Already reselling someone else's product = proven buyer, not a tyre-kicker.
        if (! empty($profile['existing_reseller']) || ! empty($profile['current_brand'])) $b['existing_reseller'] = 20;

        // Owns a real outlet / channel.
        if (! empty($profile['has_outlet']) || ! empty($profile['business_name'])) $b['has_outlet'] = 15;

        // Knows where they operate.
        if (! empty($profile['area'])) $b['area'] = 10;

        // Volume is the single biggest driver of priority.
        if (! empty($profile['monthly_volume'])) $b['volume'] = 15;

        // Competitor price captured = we can build a real proposal.
        if (! empty($profile['buying_price'])) $b['competitor_price'] = 15;

        // Reachable decision maker / contact.
        if (! empty($profile['decision_maker']) || ! empty($profile['contact_email'])) $b['decision_maker'] = 5;

        $score = max(0, min(100, array_sum($b)));
        return ['score' => $score, 'breakdown' => $b];
    }

    /** Hot / Warm / Cold — kept consistent with LeadScorer bands. */
    public function band(int $score): string
    {
        if ($score >= 70) return 'Hot';
        if ($score >= 45) return 'Warm';
        return 'Cold';
    }

    /** Opportunity size from monthly carton volume. Tells sales whom to call first. */
    public function sizeBand(array $profile): ?string
    {
        $v = (int) ($profile['monthly_volume'] ?? 0);
        if ($v <= 0)   return null;
        if ($v <= 10)  return 'small';
        if ($v <= 50)  return 'medium';
        if ($v <= 200) return 'large';
        return 'strategic';
    }
}
