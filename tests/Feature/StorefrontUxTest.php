<?php

namespace Tests\Feature;

use Tests\TestCase;

/** UX deltas 2026-07-18: login nudge, dead-end rescue, honest badges (source-pinned). */
class StorefrontUxTest extends TestCase
{
    public function test_ux_deltas_are_present(): void
    {
        $html = file_get_contents(resource_path('storefront/shop.html'));

        $this->assertStringContainsString('Save my orders — see them & buy again', $html,
            'post-order login nudge must unlock the existing Buy-again rail');
        $this->assertStringContainsString('openLogin((CUST&&CUST.phone)', $html,
            'nudge must prefill the phone they JUST typed at checkout');
        $this->assertSame(3, substr_count($html, "+waRescue()+"),
            'all three filter/collection dead-ends must offer the WhatsApp rescue');
        $this->assertStringContainsString('_newRatio()>0.4', $html,
            'NEW badges must self-suppress on a freshly imported catalogue');
    }
}
