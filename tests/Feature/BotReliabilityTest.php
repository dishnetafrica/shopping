<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Bot reliability net (2026-07-18): silence toward a customer is forbidden, structurally. */
class BotReliabilityTest extends TestCase
{
    public function test_the_job_can_never_die_silently(): void
    {
        $src = file_get_contents(app_path('Jobs/ProcessIncomingMessage.php'));
        $this->assertStringContainsString('public $tries = 2', $src, 'one retry for transient hiccups');
        $this->assertStringContainsString('public function failed(\Throwable $e)', $src);
        $this->assertStringContainsString('We received your message', $src, 'the customer always hears SOMETHING');
        $this->assertStringContainsString('NotifyOwner::dispatch', $src, 'a human always gets the alarm');
    }

    public function test_the_watchdog_watches_everyone_including_takeovers(): void
    {
        $src = file_get_contents(app_path('Console/Commands/BotWatchdogCommand.php'));
        $this->assertStringContainsString("setting('bot_mode', 'ai')", $src,
            'an unset bot_mode must not silently disable the safety net');
        $this->assertStringContainsString("agent_active', true", $src, 'the takeover lane exists');
        $this->assertStringContainsString('takeover_auto_released', $src,
            'a forgotten takeover releases the bot instead of orphaning the customer');
    }
}
