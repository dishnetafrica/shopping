<?php

namespace Tests\Unit\Comms;

use App\Services\Comms\Marketing\OptInKeywords;
use App\Services\Comms\Marketing\OptOutKeywords;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2a — inbound marketing opt-in keyword detection (the mirror of STOP). Pure unit test.
 * Consent must be unambiguous: explicit phrases opt a customer in; ordinary ordering chatter never does.
 * Sandbox mirror: tests/Engagement/engagement_harness.php.
 */
class OptInKeywordsTest extends TestCase
{
    public function test_explicit_phrases_opt_in(): void
    {
        $this->assertTrue(OptInKeywords::isOptIn('SUBSCRIBE'));
        $this->assertTrue(OptInKeywords::isOptIn('subscribe me'));
        $this->assertTrue(OptInKeywords::isOptIn('JOIN'));
        $this->assertTrue(OptInKeywords::isOptIn('opt in'));
        $this->assertTrue(OptInKeywords::isOptIn('opt-in'));
        $this->assertTrue(OptInKeywords::isOptIn('send me offers'));
        $this->assertTrue(OptInKeywords::isOptIn('  Subscribe! '));   // case + punctuation tolerant
    }

    public function test_ordinary_chatter_is_not_consent(): void
    {
        $this->assertFalse(OptInKeywords::isOptIn('yes'));            // bare yes is ambiguous
        $this->assertFalse(OptInKeywords::isOptIn('yes 2kg'));
        $this->assertFalse(OptInKeywords::isOptIn('start with rice'));
        $this->assertFalse(OptInKeywords::isOptIn('can you send my order'));
        $this->assertFalse(OptInKeywords::isOptIn(''));
    }

    public function test_optin_and_optout_do_not_collide(): void
    {
        $this->assertFalse(OptInKeywords::isOptIn('STOP'));
        $this->assertTrue(OptOutKeywords::isStop('STOP'));
        $this->assertFalse(OptOutKeywords::isStop('SUBSCRIBE'));
    }
}
