<?php

namespace Tests\Feature;

use App\Models\CampaignMessage;
use App\Models\MessageReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DB-backed proof of the idempotency claims. Runs on deploy (needs the migrations).
 */
class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_receipt_claim_is_idempotent(): void
    {
        $tenantId = 1; $conv = 10; $mid = 'wamid.AAA';

        $this->assertTrue(MessageReceipt::claim($tenantId, $conv, $mid));   // first wins
        $this->assertFalse(MessageReceipt::claim($tenantId, $conv, $mid));  // duplicate skipped
        $this->assertSame(1, MessageReceipt::where('whatsapp_message_id', $mid)->where('tenant_id', $tenantId)->count());

        // same id, different tenant is independent
        $this->assertTrue(MessageReceipt::claim(2, 99, $mid));
    }

    public function test_campaign_recipient_claim_is_idempotent(): void
    {
        $this->assertTrue(CampaignMessage::claim(1, 7, '256700111222'));
        $this->assertFalse(CampaignMessage::claim(1, 7, '256700111222'));
        $this->assertSame(1, CampaignMessage::where('campaign_id', 7)->where('recipient', '256700111222')->count());
    }

    public function test_order_idempotency_key_unique(): void
    {
        // Two inserts with the same idempotency_key must not both succeed.
        $key = \App\Support\Idempotency::orderKey(1, 10, 'tok');
        \App\Models\Order::create(['idempotency_key' => $key, 'customer_phone' => '256700111222', 'total' => 1000, 'status' => 'New', 'channel' => 'whatsapp']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\Order::create(['idempotency_key' => $key, 'customer_phone' => '256700111222', 'total' => 1000, 'status' => 'New', 'channel' => 'whatsapp']);
    }
}
