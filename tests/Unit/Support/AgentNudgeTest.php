<?php
namespace Tests\Unit\Support;

use App\Support\AgentNudge;
use PHPUnit\Framework\TestCase;

class AgentNudgeTest extends TestCase
{
    public function test_short_greetings_are_nudges(): void
    {
        foreach (['hi', 'Hello', 'hey', 'ok', 'yes', 'good morning', 'hi there', '👋', 'hi 👋', 'namaste'] as $t) {
            $this->assertTrue(AgentNudge::is($t), "expected nudge: {$t}");
        }
    }

    public function test_substantive_replies_are_takeovers(): void
    {
        foreach ([
            'Please let me know details about your product',
            'What is the price for 200 boxes?',
            'I will send the quote in 10 min',
            'Besan 5kg',
            'Sure, the total for 200 boxes is 14,000,000 UGX',
        ] as $t) {
            $this->assertFalse(AgentNudge::is($t), "expected takeover: {$t}");
        }
    }

    public function test_empty_is_not_a_nudge(): void
    {
        $this->assertFalse(AgentNudge::is('   '));
    }
}
