<?php

namespace Tests\Unit;

use App\Support\Idempotency;
use PHPUnit\Framework\TestCase;

class IdempotencyTest extends TestCase
{
    public function test_order_key_is_stable_across_retries(): void
    {
        $this->assertSame(
            Idempotency::orderKey(1, 10, 'tok-abc'),
            Idempotency::orderKey(1, 10, 'tok-abc'),
        );
    }

    public function test_order_key_differs_per_tenant_conversation_token(): void
    {
        $base = Idempotency::orderKey(1, 10, 'tok');
        $this->assertNotSame($base, Idempotency::orderKey(2, 10, 'tok'));
        $this->assertNotSame($base, Idempotency::orderKey(1, 11, 'tok'));
        $this->assertNotSame($base, Idempotency::orderKey(1, 10, 'tok2'));
    }

    public function test_conversation_lock_isolation(): void
    {
        $a = Idempotency::conversationLock(1, 'i1', '256700111222');
        $this->assertSame($a, Idempotency::conversationLock(1, 'i1', '256700111222'));
        $this->assertNotSame($a, Idempotency::conversationLock(1, 'i1', '256700999000'));
        $this->assertNotSame($a, Idempotency::conversationLock(2, 'i1', '256700111222'));
    }

    public function test_recipient_normalisation(): void
    {
        $this->assertSame(Idempotency::recipient('256700111222'), Idempotency::recipient('+256 700-111-222'));
    }
}
