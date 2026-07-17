<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\CommSuppression;
use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use App\Services\Comms\Providers\SmtpProvider;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Communication Center M4 — campaign engine. Zero network: the smtp driver and
 * the WhatsApp manager are container-faked; sends flow through the REAL
 * EmailService/WhatsAppService so suppression, unsub injection and the
 * comm_messages spine are exercised end-to-end.
 */
class CommsCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-test']);
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('comms_email.from_email', 'shop@familyshoppers.net');
        CommProvider::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'P', 'driver' => 'smtp',
            'config' => ['host' => 'x'], 'priority' => 1, 'is_active' => true,
        ]);
        $this->app->instance(SmtpProvider::class, new class implements EmailProvider {
            public function send($a, $m, $f): ProviderResult { return ProviderResult::sent('mid'); }
        });
        $gw = new class implements WhatsAppGateway {
            public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array { return ['key' => ['id' => 'WAID']]; }
            public function sendImage(string $instance, string $to, string $media, string $caption = ''): array { return []; }
            public function markRead(string $instance, string $messageId): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $instance): array { return []; }
        };
        $this->app->instance(WhatsAppManager::class, new class($gw) extends WhatsAppManager {
            public function __construct(private WhatsAppGateway $gw) {}
            public function forTenant(Tenant $t): WhatsAppGateway { return $this->gw; }
        });
        return $t;
    }

    private function profile(Tenant $t, string $phone, array $extra = []): void
    {
        DB::table('customer_profiles')->insert(array_merge([
            'tenant_id' => $t->id, 'phone' => $phone, 'name' => 'C '.$phone,
            'email' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function campaign(Tenant $t, array $attrs = []): CommCampaign
    {
        return CommCampaign::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $t->id, 'name' => 'Promo', 'channel' => 'email',
            'subject' => 'Big sale {{customer_name}}', 'html_body' => '<p>Hello {{customer_name}}</p>',
            'status' => CommCampaign::ST_DRAFT, 'throttle_per_minute' => 60,
        ], $attrs));
    }

    // -------------------------------------------------------------- estimate

    public function test_estimate_honours_segment_and_channel_address(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', ['email' => 'a@x.com', 'segment' => 'wholesale']);
        $this->profile($t, '256700000002', ['email' => 'b@x.com', 'segment' => 'retail']);
        $this->profile($t, '256700000003', ['segment' => 'wholesale']);   // phone only

        $svc = app(CampaignService::class);
        $est = $svc->estimate($t, ['segment' => 'wholesale'], 'both');

        $this->assertSame(1, $est['email'], 'only the wholesale profile WITH an email');
        $this->assertSame(2, $est['whatsapp'], 'both wholesale profiles have phones');
        $this->assertSame(3, $est['total_profiles']);
    }

    public function test_estimate_not_ordered_within_is_the_winback_filter(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', ['email' => 'a@x.com']);
        $this->profile($t, '256700000002', ['email' => 'b@x.com']);
        \App\Models\Order::create([
            'tenant_id' => $t->id, 'order_no' => 'O1', 'customer_name' => 'A',
            'customer_phone' => '256700000001', 'total' => 100, 'amount_paid' => 0,
            'status' => 'Delivered', 'channel' => 'whatsapp',
        ]);

        $est = app(CampaignService::class)->estimate($t, ['not_ordered_within_days' => 30], 'email');
        $this->assertSame(1, $est['email'], 'only the customer with NO recent order');
    }

    // -------------------------------------------------- batches, dedupe, done

    public function test_throttled_batches_dedupe_and_finish(): void
    {
        $t = $this->tenant();
        for ($i = 1; $i <= 5; $i++) $this->profile($t, '25670000000'.$i, ['email' => "c{$i}@x.com"]);

        $c = $this->campaign($t, ['throttle_per_minute' => 2]);
        $svc = app(CampaignService::class);
        $svc->launch($c);
        $this->assertSame(CommCampaign::ST_SCHEDULED, $c->status);
        $this->assertSame(5, $c->total_estimated);

        // Sweep 1: batch of 2.
        $this->artisan('comms:campaign-sweep')->assertExitCode(0);
        $c->refresh();
        $this->assertSame(CommCampaign::ST_SENDING, $c->status);
        $this->assertSame(2, $c->enqueued_count);

        // Sweep 2 + 3: 2 more, then the final 1 => done. Dedupe means re-sweeps add nothing.
        $this->artisan('comms:campaign-sweep');
        $this->artisan('comms:campaign-sweep');
        $c->refresh();
        $this->assertSame(CommCampaign::ST_DONE, $c->status);
        $this->assertSame(5, $c->enqueued_count);
        $this->assertNotNull($c->finished_at);

        $this->artisan('comms:campaign-sweep');
        $this->assertSame(5, $c->refresh()->enqueued_count, 'done campaigns are never re-processed');

        $sent = CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->get();
        $this->assertCount(5, $sent);
        $this->assertSame(['sent'], $sent->pluck('status')->unique()->values()->all());
    }

    public function test_suppressed_recipient_lands_cancelled_and_campaign_continues(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', ['email' => 'ok@x.com']);
        $this->profile($t, '256700000002', ['email' => 'stop@x.com']);
        CommSuppression::add($t->id, 'stop@x.com', 'unsubscribed', 'test');

        $c = $this->campaign($t);
        $svc = app(CampaignService::class);
        $svc->launch($c);
        $this->artisan('comms:campaign-sweep');

        $stats = $svc->stats($c->refresh());
        $this->assertSame(CommCampaign::ST_DONE, $c->status);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['cancelled'], 'suppressed recipient is logged as cancelled, not silently skipped');
    }

    public function test_campaign_email_always_gets_an_unsubscribe_link(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', ['email' => 'a@x.com']);

        $c = $this->campaign($t, ['html_body' => '<p>No unsub here</p>']);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->first();
        $this->assertStringContainsString('/c/e/u/'.$m->token, (string) $m->html_body,
            'unsubscribe footer must be auto-appended and the URL resolved');
    }

    public function test_pause_stops_enqueueing_resume_continues(): void
    {
        $t = $this->tenant();
        for ($i = 1; $i <= 4; $i++) $this->profile($t, '25670000000'.$i, ['email' => "c{$i}@x.com"]);

        $c = $this->campaign($t, ['throttle_per_minute' => 2]);
        $svc = app(CampaignService::class);
        $svc->launch($c);
        $this->artisan('comms:campaign-sweep');
        $this->assertSame(2, $c->refresh()->enqueued_count);

        $svc->pause($c);
        $this->artisan('comms:campaign-sweep');
        $this->assertSame(2, $c->refresh()->enqueued_count, 'paused => no new batches');

        $svc->resume($c);
        $this->artisan('comms:campaign-sweep');
        $c->refresh();
        $this->assertSame(4, $c->enqueued_count);
        $this->assertSame(CommCampaign::ST_DONE, $c->status);
    }

    public function test_both_channel_sends_email_and_whatsapp_per_customer(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', ['email' => 'a@x.com']);   // both addresses
        $this->profile($t, '256700000002', []);                        // phone only

        $c = $this->campaign($t, ['channel' => 'both', 'text_body' => 'Hi {{customer_name}}, big sale!']);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $rows = CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->get();
        $this->assertCount(3, $rows, 'customer1: email+wa, customer2: wa only');
        $this->assertSame(2, $rows->where('channel', 'whatsapp')->count());
        $this->assertSame(1, $rows->where('channel', 'email')->count());
        $this->assertSame(CommCampaign::ST_DONE, $c->refresh()->status);
        $this->assertSame(3, $c->enqueued_count);
    }
}
