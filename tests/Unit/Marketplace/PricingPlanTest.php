<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\Pricing\PricingPlanFactory;
use PHPUnit\Framework\TestCase;

/**
 * The pricing pipeline turns a base marketplace price into a customer price.
 * Pure unit test — config built directly, no app boot.
 */
class PricingPlanTest extends TestCase
{
    use MakesMarketplaceFixtures;

    public function test_ugx_native_applies_margin_only(): void
    {
        $plan = PricingPlanFactory::fromConfig($this->cfg(['ugxRate' => 0, 'margin' => 0.15]));
        $b = $plan->price(302000, 'UGX');

        $this->assertSame((int) ceil(302000 * 1.15), $b->sellPrice());
        $this->assertSame('UGX', $b->currency());
    }

    public function test_usd_shop_converts_after_margin(): void
    {
        $plan = PricingPlanFactory::fromConfig($this->cfg([
            'ugxRate' => 3600, 'displayCurrency' => 'USD', 'margin' => 0.15,
        ]));
        $b = $plan->price(302000, 'UGX');

        $this->assertSame((int) ceil(302000 * 1.15 / 3600), $b->sellPrice());
        $this->assertSame('USD', $b->currency());
    }

    public function test_full_landed_cost_chain_in_order(): void
    {
        // base 100000 -> +10000 shipping -> +5% import -> +25% duty -> +20% markup
        $plan = PricingPlanFactory::fromConfig($this->cfg([
            'ugxRate' => 0, 'margin' => 0.20,
            'shippingFlat' => 10000, 'importPct' => 0.05, 'dutyPct' => 0.25,
        ]));
        $b = $plan->price(100000, 'UGX');

        $expected = (int) ceil(((100000 + 10000) * 1.05 * 1.25) * 1.20);
        $this->assertSame($expected, $b->sellPrice());

        // Breakdown records base + each active stage (audit trail for future receipts).
        $labels = array_column($b->breakdown(), 'label');
        $this->assertContains('Base price', $labels);
        $this->assertContains('Shipping', $labels);
        $this->assertContains('Import', $labels);
        $this->assertContains('Duty', $labels);
        $this->assertContains('Markup', $labels);
    }

    public function test_zero_rate_stages_are_noops(): void
    {
        $plan = PricingPlanFactory::fromConfig($this->cfg(['margin' => 0.0]));
        $b = $plan->price(50000, 'UGX');

        $this->assertSame(50000, $b->sellPrice());
        // Only the base line — no shipping/import/duty/markup rows added.
        $this->assertCount(1, $b->breakdown());
    }

    public function test_round_step_rounds_up_to_nice_number(): void
    {
        $plan = PricingPlanFactory::fromConfig($this->cfg(['margin' => 0.0, 'roundStep' => 500]));
        $b = $plan->price(50100, 'UGX');
        $this->assertSame(50500, $b->sellPrice());
    }
}
