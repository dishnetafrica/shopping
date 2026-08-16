<?php

namespace Tests\Feature;

use App\Models\CommCampaign;
use App\Models\Tenant;
use App\Services\Comms\Marketing\CampaignAttribution;
use App\Services\Comms\Marketing\CampaignHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignHealthAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        return $t;
    }

    public function test_health_is_new_below_min_volume(): void
    {
        $t = $this->tenant();
        $h = CampaignHealth::assess(['sent' => 5, 'failure_rate' => 40], $t);
        $this->assertSame('new', $h['state'], 'a tiny campaign is not scored');
    }

    public function test_health_states_from_rates(): void
    {
        $t = $this->tenant();
        $this->assertSame('green', CampaignHealth::assess(['sent' => 100, 'failure_rate' => 2, 'opt_out_rate' => 1, 'delivery_rate' => 95], $t)['state']);
        $this->assertSame('yellow', CampaignHealth::assess(['sent' => 100, 'failure_rate' => 10, 'opt_out_rate' => 1, 'delivery_rate' => 95], $t)['state']);
        $this->assertSame('red', CampaignHealth::assess(['sent' => 100, 'failure_rate' => 30, 'opt_out_rate' => 1, 'delivery_rate' => 95], $t)['state']);
        $this->assertSame('red', CampaignHealth::assess(['sent' => 100, 'failure_rate' => 1, 'opt_out_rate' => 9, 'delivery_rate' => 95], $t)['state'], 'opt-out drives it too');
    }

    public function test_attribution_is_correlation_only(): void
    {
        $t = $this->tenant();
        $c = CommCampaign::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'name' => 'Promo', 'channel' => 'whatsapp',
            'status' => CommCampaign::ST_SENDING, 'started_at' => now()->subDay(), 'throttle_per_minute' => 60,
        ]);
        // We sent this campaign to one number...
        DB::table('comm_messages')->insert([
            'tenant_id' => $t->id, 'channel' => 'whatsapp', 'to_email' => '256700000001', 'token' => 'tk',
            'status' => 'sent', 'related_type' => 'campaign', 'related_id' => $c->id,
            'sent_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        // ...and that customer placed an order the next day, plus an unrelated customer ordered too.
        DB::table('orders')->insert([
            'tenant_id' => $t->id, 'order_no' => 'O1', 'customer_phone' => '256700000001',
            'total' => 50000, 'status' => 'Delivered', 'channel' => 'whatsapp', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'tenant_id' => $t->id, 'order_no' => 'O2', 'customer_phone' => '256799999999',
            'total' => 99999, 'status' => 'Delivered', 'channel' => 'whatsapp', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $a = CampaignAttribution::for($t, $c, 7);
        $this->assertSame('correlation', $a['basis']);
        $this->assertSame(1, $a['orders'], 'only the messaged customer, not the unrelated one');
        $this->assertSame(50000.0, $a['revenue']);
        $this->assertStringContainsString('does NOT prove', $a['caveat']);
    }
}
