<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Promise↔delivery coupling (2026-07-18): the AI can only promise what the system will send. */
class SendPromiseCouplingTest extends TestCase
{
    public function test_the_mouth_and_hands_are_wired_together(): void
    {
        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('<<SEND', $src);
        $this->assertStringContainsString('deliverPriceList($tenant, $from, $gateway, true)', $src,
            'an explicit promise bypasses intent gates and suppression');
        $this->assertStringContainsString('send_block_failed', $src, 'a failed promise alerts a human');
        $this->assertStringContainsString("NEVER say 'I", $src, 'the honesty rule');
        $this->assertStringContainsString('starring the exact name', $src, 'photos couple via *starred* names');
    }

    public function test_ad_leads_get_the_red_carpet_not_the_terms_and_conditions(): void
    {
        $job = file_get_contents(app_path('Jobs/ProcessIncomingMessage.php'));
        $this->assertStringContainsString('[NEW AD LEAD]', $job);
        $this->assertStringContainsString('ad_lead_tagged', $job, 'tagged once per conversation');
        $this->assertStringContainsString('lead source: WhatsApp/Facebook ad', $job,
            'the extractor and learner see where the customer came from');

        $brain = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('AD LEADS', $brain);
        $this->assertStringContainsString('they have not seen any list yet', $brain);
        $this->assertStringContainsString('hotel, restaurant, or shop', $brain, 'one qualifying question');
    }

    public function test_the_318_product_incident_can_not_recur(): void
    {
        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('$count > 1000', $src,
            'the 300-product silent self-disable is gone');
        $this->assertStringContainsString("'list of the products'", $src, 'the phrasing that slipped through');
    }
}
