<?php

namespace Tests\Unit\Engagement;

use App\Services\Engagement\CampaignType;
use App\Services\Engagement\ContactScore;
use App\Services\Engagement\EngagementEligibility as EE;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — the pure decision logic of the Customer Re-engagement Engine: scoring, the strict
 * consent/cooldown gate, and campaign-type classification. Pure unit test (no DB).
 * Sandbox mirror: tests/Engagement/engagement_harness.php.
 */
class EngagementLogicTest extends TestCase
{
    private function score(array $s): int
    {
        return ContactScore::score($s)['score'];
    }

    public function test_score_ranks_strong_above_weak_and_clamps(): void
    {
        $vip  = ['health' => 90, 'flags' => ['VIP'], 'reorder_state' => 'overdue', 'reorder_confidence' => 0.8, 'orders' => 8, 'value' => 5000, 'replied_before' => true, 'days_since_contacted' => 40];
        $weak = ['health' => 20, 'reorder_state' => 'healthy', 'orders' => 2, 'value' => 50, 'marketed_30d' => 3, 'days_since_contacted' => 5];

        $this->assertGreaterThan(70, $this->score($vip));
        $this->assertLessThan(35, $this->score($weak));
        $this->assertGreaterThan($this->score($weak), $this->score($vip));
        $this->assertLessThanOrEqual(100, $this->score($vip));
        $this->assertGreaterThanOrEqual(0, $this->score($weak));
        $this->assertNotEmpty(ContactScore::score($vip)['reasons']);
    }

    public function test_score_prefers_overdue_over_lapsed_and_penalises_fatigue_and_no_orders(): void
    {
        $overdue = ['health' => 60, 'reorder_state' => 'overdue', 'reorder_confidence' => 0.9, 'orders' => 5, 'value' => 1000];
        $lapsed  = ['health' => 60, 'reorder_state' => 'lapsed', 'reorder_confidence' => 0.9, 'orders' => 5, 'value' => 1000];
        $this->assertGreaterThan($this->score($lapsed), $this->score($overdue));

        $fresh = ['health' => 50, 'orders' => 4, 'value' => 500, 'marketed_30d' => 0];
        $fatig = ['health' => 50, 'orders' => 4, 'value' => 500, 'marketed_30d' => 3, 'replied_before' => false];
        $this->assertLessThan($this->score($fresh), $this->score($fatig));

        $this->assertLessThan($this->score(['health' => 40, 'orders' => 3, 'value' => 300]), $this->score(['health' => 40, 'orders' => 0]));
    }

    public function test_eligibility_enforces_consent_rules(): void
    {
        $this->assertTrue(EE::decide(['opted_in' => true, 'days_since_contacted' => 30], 14)['eligible']);
        $this->assertSame(EE::OPTED_OUT, EE::decide(['opted_out' => true, 'opted_in' => true], 14)['blocked']);
        $this->assertSame(EE::SUPPRESSED, EE::decide(['suppressed' => true, 'opted_in' => true], 14)['blocked']);
        $this->assertSame(EE::NOT_OPTED_IN, EE::decide(['opted_in' => false], 14)['blocked']);
        $this->assertSame(EE::ACTIVE_CONVERSATION, EE::decide(['opted_in' => true, 'active_conversation' => true], 14)['blocked']);
        // a prior inbound is NOT consent
        $this->assertFalse(EE::decide([], 14)['eligible']);
        // opt-out has precedence over not-opted-in
        $this->assertSame(EE::OPTED_OUT, EE::decide(['opted_out' => true, 'opted_in' => false], 14)['blocked']);
    }

    public function test_cooldown_rotation(): void
    {
        $blocked = EE::decide(['opted_in' => true, 'days_since_contacted' => 5], 14);
        $this->assertSame(EE::COOLDOWN, $blocked['blocked']);
        $this->assertSame(9, $blocked['next_eligible_in_days']);

        $this->assertTrue(EE::decide(['opted_in' => true, 'days_since_contacted' => 20], 14)['eligible']);
        $this->assertTrue(EE::decide(['opted_in' => true, 'days_since_contacted' => null], 21)['eligible']);
        $this->assertTrue(EE::decide(['opted_in' => true, 'days_since_contacted' => 21], 21)['eligible']);   // boundary
        $this->assertSame(EE::COOLDOWN, EE::decide(['opted_in' => true, 'days_since_contacted' => 20], 21)['blocked']);
    }

    public function test_campaign_type_classification(): void
    {
        $this->assertSame(CampaignType::DORMANT_WINBACK, CampaignType::decide(['stage' => 'DORMANT']));
        $this->assertSame(CampaignType::DORMANT_WINBACK, CampaignType::decide(['flags' => ['WINBACK']]));
        $this->assertSame(CampaignType::DORMANT_WINBACK, CampaignType::decide(['reorder_state' => 'lapsed']));
        $this->assertSame(CampaignType::REPLENISHMENT, CampaignType::decide(['reorder_state' => 'overdue']));
        $this->assertSame(CampaignType::REPLENISHMENT, CampaignType::decide(['reorder_state' => 'due']));
        $this->assertSame(CampaignType::VIP_OFFER, CampaignType::decide(['flags' => ['VIP'], 'reorder_state' => 'healthy']));
        $this->assertSame(CampaignType::RECOMMENDATION, CampaignType::decide(['has_recommendation' => true]));
        $this->assertSame(CampaignType::NEW_STOCK, CampaignType::decide([]));
        // most-specific wins
        $this->assertSame(CampaignType::DORMANT_WINBACK, CampaignType::decide(['stage' => 'DORMANT', 'flags' => ['VIP']]));
    }
}
