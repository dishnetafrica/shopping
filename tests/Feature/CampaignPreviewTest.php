<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use App\Services\Comms\Marketing\CampaignPreview;
use App\Services\Comms\Marketing\MarketingConsent;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — the dry-run preview must (a) send nothing and (b) agree with the real send path about who
 * is eligible, because both use CampaignSafetyGuard.
 */
class CampaignPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-test']);
        app(\App\Support\TenantContext::class)->set($t->id);
        $gw = new class implements WhatsAppGateway {
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { return ['key' => ['id' => 'WAID']]; }
            public function sendImage(string $i, string $to, string $md, string $c = ''): array { return []; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $p): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
        $this->app->instance(WhatsAppManager::class, new class($gw) extends WhatsAppManager {
            public function __construct(private WhatsAppGateway $gw) {}
            public function forTenant(Tenant $t): WhatsAppGateway { return $this->gw; }
        });
        return $t;
    }

    private function customer(Tenant $t, string $phone): void
    {
        DB::table('customer_profiles')->insert([
            'tenant_id' => $t->id, 'phone' => $phone, 'name' => 'C '.$phone,
            'marketing_opted_in_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_preview_sends_nothing_and_matches_the_real_send(): void
    {
        $t = $this->tenant();
        $this->customer($t, '256700000001');   // eligible
        $this->customer($t, '256700000002');   // opted out
        $this->customer($t, '256700000003');   // frequency capped
        MarketingConsent::optOut($t->id, '256700000002', 'test');
        DB::table('comm_messages')->insert([
            'tenant_id' => $t->id, 'channel' => 'whatsapp', 'to_email' => '256700000003',
            'token' => 'tk', 'status' => 'sent', 'related_type' => 'campaign', 'related_id' => 999,
            'sent_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $c = CommCampaign::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'name' => 'Foil promo', 'channel' => 'whatsapp',
            'text_body' => 'Hi {{customer_name}}, foil is back!', 'status' => CommCampaign::ST_DRAFT,
            'throttle_per_minute' => 60,
        ]);

        $preview = CampaignPreview::for($t, $c);

        $this->assertSame(3, $preview['opted_in']);
        $this->assertSame(1, $preview['suppressed'], 'opted-out counts as suppressed');
        $this->assertSame(1, $preview['frequency_capped']);
        $this->assertSame(1, $preview['eligible']);
        $this->assertStringContainsString('Aisha', $preview['example'], 'example is rendered');
        $this->assertArrayHasKey('data_caveat', $preview);

        // Preview must not have created ANY campaign message.
        $this->assertSame(0, CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->count(),
            'a dry run sends nothing');

        // Now actually run it: the number sent must equal what preview called eligible.
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');
        $sent = CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->where('status', 'sent')->count();

        $this->assertSame($preview['eligible'], $sent, 'preview eligibility == real send count (no drift)');
    }
}
