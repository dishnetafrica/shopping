<?php

namespace Tests\Feature\Sales;

use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Sales\QuoteExpiry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Internet-provider quotations past their validity date become expired; nothing else changes. */
class QuoteExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function quote(Tenant $t, string $status, string $validUntil, string $no): Quotation
    {
        return Quotation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'quote_no' => $no, 'customer_phone' => '211911000001',
            'currency' => 'USD', 'total' => 100, 'status' => $status, 'source' => 'test', 'valid_until' => $validUntil]);
    }

    public function test_waiting_quotations_past_their_date_expire_and_agreed_or_current_ones_do_not(): void
    {
        $isp = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'timezone' => 'Africa/Juba']]);
        $shop = Tenant::factory()->create(['settings' => ['vertical' => 'grocery']]);
        $yesterday = now('Africa/Juba')->subDay()->toDateString();
        $tomorrow = now('Africa/Juba')->addDay()->toDateString();

        $old      = $this->quote($isp, 'sent', $yesterday, 'Q-OLD');
        $viewed   = $this->quote($isp, 'viewed', $yesterday, 'Q-VIEWED');
        $accepted = $this->quote($isp, 'accepted', $yesterday, 'Q-ACCEPTED');
        $current  = $this->quote($isp, 'sent', $tomorrow, 'Q-CURRENT');
        $shopOld  = $this->quote($shop, 'sent', $yesterday, 'Q-SHOP');

        $this->artisan('quotes:expire')->assertExitCode(0);

        $status = fn (Quotation $q) => Quotation::withoutGlobalScopes()->find($q->id)->status;
        $this->assertSame(['expired', 'expired'], [$status($old), $status($viewed)]);
        $this->assertSame('accepted', $status($accepted), 'a quotation the customer agreed to is never expired');
        $this->assertSame('sent', $status($current));
        $this->assertSame('sent', $status($shopOld), 'other businesses keep today\'s behaviour');
    }

    public function test_the_rule(): void
    {
        $this->assertTrue(QuoteExpiry::due('sent', '2026-11-15', '2026-11-16'));
        $this->assertFalse(QuoteExpiry::due('sent', '2026-11-16', '2026-11-16'), 'valid through its last day');
        $this->assertFalse(QuoteExpiry::due('accepted', '2026-01-01', '2026-11-16'));
        $this->assertFalse(QuoteExpiry::due('converted', '2026-01-01', '2026-11-16'));
        $this->assertFalse(QuoteExpiry::due('sent', null, '2026-11-16'), 'no date: never expires on its own');
    }
}
