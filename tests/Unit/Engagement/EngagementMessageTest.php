<?php

namespace Tests\Unit\Engagement;

use App\Services\Engagement\CampaignType;
use App\Services\Engagement\EngagementMessage;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2b — the fixed, personalised outbound copy (NOT AI-generated). Pure unit test.
 * Every message greets by first name only, names the shop, and always carries a STOP opt-out line.
 */
class EngagementMessageTest extends TestCase
{
    public function test_personalisation_and_optout(): void
    {
        $m = EngagementMessage::compose([
            'name' => 'John Doe', 'shop' => 'Family Shoppers',
            'product' => 'Kabras Sugar', 'campaign_type' => CampaignType::REPLENISHMENT,
        ]);
        $this->assertStringContainsString('Hi John', $m);
        $this->assertStringNotContainsString('Doe', $m);            // first name only
        $this->assertStringContainsString('Family Shoppers', $m);
        $this->assertStringContainsString('Kabras Sugar', $m);
        $this->assertStringContainsString('STOP', $m);              // opt-out always present
    }

    public function test_neutral_greeting_and_no_phone_as_name(): void
    {
        $this->assertStringContainsString('Hello', EngagementMessage::compose(['name' => '', 'shop' => 'Shop', 'campaign_type' => CampaignType::NEW_STOCK]));
        $this->assertStringNotContainsString('256', EngagementMessage::compose(['name' => '+256772123456', 'shop' => 'Shop', 'campaign_type' => CampaignType::NEW_STOCK]));
    }

    public function test_copy_varies_by_type_and_works_without_product(): void
    {
        $win = EngagementMessage::compose(['name' => 'Amina', 'shop' => 'Shop', 'campaign_type' => CampaignType::DORMANT_WINBACK]);
        $new = EngagementMessage::compose(['name' => 'Amina', 'shop' => 'Shop', 'campaign_type' => CampaignType::NEW_STOCK]);
        $this->assertNotSame($win, $new);
        $this->assertStringContainsString('STOP', EngagementMessage::compose(['name' => 'Amina', 'shop' => 'Shop', 'campaign_type' => CampaignType::REPLENISHMENT]));
    }
}
