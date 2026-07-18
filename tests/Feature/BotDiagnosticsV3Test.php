<?php

namespace Tests\Feature;

use Tests\TestCase;

/** V3 Bot Diagnostics (2026-07-18): the classic diagnostics page, migrated. */
class BotDiagnosticsV3Test extends TestCase
{
    public function test_capability_exists_registered_and_guarded(): void
    {
        $src = file_get_contents(app_path('Capability/Process/BotDiagnosticsCapability.php'));
        $this->assertStringContainsString('implements ProcessCapability', $src);
        $this->assertStringContainsString('bot_events', $src, 'the trace trail');
        $this->assertStringContainsString('never itself be down', $src, 'every probe guarded');
        $this->assertStringContainsString("'/papi/diag/queue-flush'", $src);
        $this->assertStringContainsString('diagnostics', file_get_contents(app_path('Capability/Registry/CoreCapabilityProvider.php')));
        $this->assertStringContainsString('diag/queue-flush', file_get_contents(base_path('routes/web.php')));
    }
}
