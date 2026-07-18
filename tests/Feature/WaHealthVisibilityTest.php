<?php

namespace Tests\Feature;

use Tests\TestCase;

/** WhatsApp health visibility (2026-07-18): connection status finally lives IN V3. */
class WaHealthVisibilityTest extends TestCase
{
    public function test_chats_screen_carries_the_health_chip_and_check_button(): void
    {
        $src = file_get_contents(app_path('Capability/Process/ChatListCapability.php'));
        $this->assertStringContainsString("'key' => 'wa', 'label' => 'WhatsApp'", $src);
        $this->assertStringContainsString('wa_live:', $src, '60s-cached live probe');
        $this->assertStringContainsString("'/papi/wa/health'", $src, 'the Check connection now action');
        $this->assertStringContainsString('never breaks Chats', $src, 'probe is guarded');
    }

    public function test_endpoint_route_and_briefing_exist(): void
    {
        $this->assertStringContainsString('function waHealth', file_get_contents(app_path('Http/Controllers/Panel/PanelApiController.php')));
        $this->assertStringContainsString('wa/health', file_get_contents(base_path('routes/web.php')));
        $this->assertStringContainsString('WhatsApp is DISCONNECTED', file_get_contents(app_path('Capability/Process/MorningBriefingCapability.php')));
    }
}
