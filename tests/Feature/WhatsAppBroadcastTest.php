<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use App\Services\Comms\Marketing\CampaignSafetyGuard;
use App\Services\Comms\Marketing\MarketingConsent;
use App\Services\Comms\WhatsAppStatusIngest;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WhatsApp marketing broadcast — the safety engine (Phase 1).
 *
 * Extends the Communication Center. Zero network: the WhatsApp gateway is container-faked and its send
 * result is switchable per test, so the real CampaignService → guard → WhatsAppService → dispatcher
 * path (suppression, consent, caps, idempotency, the _ok gate, status ingestion) is exercised
 * end-to-end. Queue is `sync` under phpunit.xml, so a sweep both enqueues AND sends in one call.
 */
class WhatsAppBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array the fake gateway's next sendText() return */
    private array $sendResult = ['key' => ['id' => 'WAID']];

    private function tenant(array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-test']);
        app(\App\Support\TenantContext::class)->set($t->id);
        foreach ($settings as $k => $v) {
            $t->putSetting($k, $v);
        }

        $test = $this;
        $gw = new class($test) implements WhatsAppGateway {
            public function __construct(private $test) {}
            public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array { return $this->test->sendResult; }
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

    /** Insert a customer profile. Opted in by default; pass optIn:false for a non-consented one. */
    private function profile(Tenant $t, string $phone, bool $optIn = true, array $extra = []): void
    {
        DB::table('customer_profiles')->insert(array_merge([
            'tenant_id' => $t->id, 'phone' => $phone, 'name' => 'C ' . $phone,
            'marketing_opted_in_at' => $optIn ? now() : null,
            'marketing_opt_in_source' => $optIn ? 'test' : null,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function campaign(Tenant $t, array $attrs = []): CommCampaign
    {
        return CommCampaign::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $t->id, 'name' => 'Promo', 'channel' => 'whatsapp',
            'text_body' => 'Hi {{customer_name}}, sale on today!',
            'status' => CommCampaign::ST_DRAFT, 'throttle_per_minute' => 60,
        ], $attrs));
    }

    private function rows(CommCampaign $c)
    {
        return CommMessage::withoutGlobalScope('tenant')
            ->where('related_type', 'campaign')->where('related_id', $c->id)->get();
    }

    // 1. opted-in customer receives
    public function test_opted_in_customer_receives(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $rows = $this->rows($c);
        $this->assertCount(1, $rows);
        $row = $rows->first();
        // DIAGNOSTIC (temporary): if the send didn't reach 'sent', surface why so CI prints the
        // exact reason instead of a bare 'retry' vs 'sent' mismatch. Remove once Phase-1 is green.
        $this->assertSame('sent', $row->status, sprintf(
            'send did not complete: status=%s attempts=%s skip_reason=%s last_error=%s',
            $row->status, $row->attempts, $row->skip_reason ?? '(null)', $row->last_error ?? '(null)'
        ));
    }

    // 2. non-opted-in customer is skipped (never in the audience)
    public function test_non_opted_in_customer_is_not_sent(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', false);   // no consent

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->assertSame(0, $c->total_estimated, 'no consented customers => empty audience');
        $this->artisan('comms:campaign-sweep');

        $this->assertCount(0, $this->rows($c), 'a non-consented customer is never messaged');
    }

    // 3. opted-out customer is skipped
    public function test_opted_out_customer_is_skipped(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);
        MarketingConsent::optOut($t->id, '256700000001', 'test-stop');

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $sent = $this->rows($c)->where('status', 'sent');
        $this->assertCount(0, $sent, 'an opted-out number is never sent a marketing message');
    }

    // 4. wrong-tenant customer cannot receive
    public function test_tenant_isolation(): void
    {
        $a = $this->tenant();
        $bId = Tenant::factory()->create()->id;
        DB::table('customer_profiles')->insert([
            'tenant_id' => $bId, 'phone' => '256999999999', 'name' => 'B',
            'marketing_opted_in_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = $this->campaign($a);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $this->assertCount(0, $this->rows($c), "tenant A's campaign must never reach tenant B's customer");
        $this->assertFalse(
            CommMessage::withoutGlobalScope('tenant')->where('to_email', '256999999999')->exists()
        );
    }

    // 5. per-customer frequency limit
    public function test_frequency_cap_skips_recently_marketed(): void
    {
        $t = $this->tenant(['marketing_min_interval_days' => 3]);
        $this->profile($t, '256700000001', true);
        // A marketing message already went out yesterday.
        DB::table('comm_messages')->insert([
            'tenant_id' => $t->id, 'channel' => 'whatsapp', 'to_email' => '256700000001',
            'token' => 'tok1', 'status' => 'sent', 'related_type' => 'campaign', 'related_id' => 9999,
            'sent_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $skipped = $this->rows($c)->where('status', 'skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame(CampaignSafetyGuard::R_FREQUENCY, $skipped->first()->skip_reason);
        $this->assertCount(0, $this->rows($c)->where('status', 'sent'));
    }

    // 6 + 7. daily tenant limit is enforced and never exceeded
    public function test_daily_cap_is_enforced_and_not_exceeded(): void
    {
        $t = $this->tenant(['marketing_daily_limit' => 2]);
        for ($i = 1; $i <= 4; $i++) {
            $this->profile($t, '25670000000' . $i, true);
        }

        $c = $this->campaign($t, ['throttle_per_minute' => 60]);
        app(CampaignService::class)->launch($c);

        // Several sweeps in the same day must never send more than the cap.
        $this->artisan('comms:campaign-sweep');
        $this->artisan('comms:campaign-sweep');
        $this->artisan('comms:campaign-sweep');

        $sent = $this->rows($c)->where('status', 'sent');
        $this->assertCount(2, $sent, 'exactly the daily cap, never more');
        $this->assertNotSame(CommCampaign::ST_DONE, $c->refresh()->status, 'campaign waits for tomorrow, not done');
    }

    // 8. duplicate job does not send a duplicate message
    public function test_duplicate_send_is_idempotent(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $m = $this->rows($c)->first();
        $this->assertSame('sent', $m->status);

        // Re-dispatch the same row: the dispatcher's atomic claim only grabs pending/retry rows.
        app(\App\Services\Comms\WhatsAppDispatcher::class)->attempt($m->id);
        $m->refresh();
        $this->assertSame('sent', $m->status);
        $this->assertSame(1, $this->rows($c)->where('status', 'sent')->count(), 'no duplicate send');
    }

    // 11 + 12. skip reason is recorded; provider id is stored on success
    public function test_provider_id_stored_and_skip_reason_recorded(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);           // will send
        $this->profile($t, '256700000002', true);           // will be opted out
        MarketingConsent::optOut($t->id, '256700000002', 'test');

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $sent = $this->rows($c)->firstWhere('status', 'sent');
        $this->assertNotNull($sent);
        $this->assertSame('WAID', $sent->provider_message_id, 'provider message id captured');

        $skipped = $this->rows($c)->firstWhere('status', 'skipped');
        $this->assertNotNull($skipped);
        $this->assertSame(CampaignSafetyGuard::R_OPTED_OUT, $skipped->skip_reason);
    }

    // 14. provider rejection (_ok=false) does NOT count as sent — protects the safety controls
    public function test_provider_rejection_is_not_counted_as_sent(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);
        $this->sendResult = ['_ok' => false, '_status' => 401];   // gateway took it, WhatsApp refused

        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $m = $this->rows($c)->first();
        $this->assertNotSame('sent', $m->status, 'a rejected send must never read as sent');
        $this->assertContains($m->status, ['retry', 'failed']);
        $this->assertNull($m->sent_at);
        $this->assertSame(0, \App\Services\Comms\Marketing\DailyLimiter::sentToday($t->refresh()),
            'a rejected send must not consume the daily budget');
    }

    // 10. paused and cancelled campaigns do not send
    public function test_paused_and_cancelled_do_not_send(): void
    {
        $t = $this->tenant();
        for ($i = 1; $i <= 3; $i++) {
            $this->profile($t, '25670000000' . $i, true);
        }
        $c = $this->campaign($t, ['throttle_per_minute' => 1]);
        $svc = app(CampaignService::class);
        $svc->launch($c);

        $this->artisan('comms:campaign-sweep');           // sends 1
        $this->assertSame(1, $this->rows($c)->where('status', 'sent')->count());

        $svc->pause($c);
        $this->artisan('comms:campaign-sweep');           // paused => nothing
        $this->assertSame(1, $this->rows($c)->where('status', 'sent')->count(), 'paused campaign sends nothing');

        $svc->resume($c);
        $svc->cancel($c);
        $this->artisan('comms:campaign-sweep');           // cancelled => nothing
        $this->assertSame(1, $this->rows($c)->where('status', 'sent')->count(), 'cancelled campaign sends nothing');
    }

    // 13. campaign statistics are correct
    public function test_stats_are_correct(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);   // sent
        $this->profile($t, '256700000002', true);   // opted out -> skipped
        MarketingConsent::optOut($t->id, '256700000002', 'test');

        $c = $this->campaign($t);
        $svc = app(CampaignService::class);
        $svc->launch($c);
        $this->artisan('comms:campaign-sweep');

        $stats = $svc->stats($c->refresh());
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $stats['opted_out']);
    }

    // 15a. status webhook maps provider id -> delivered/read and writes an audit event
    public function test_status_ingest_records_delivered_and_read(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);
        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        $m = $this->rows($c)->firstWhere('status', 'sent');
        $this->assertNotNull($m->provider_message_id);

        WhatsAppStatusIngest::apply($t->id, $m->provider_message_id, 'delivered', 'evolution', []);
        WhatsAppStatusIngest::apply($t->id, $m->provider_message_id, 'read', 'evolution', []);
        $m->refresh();

        $this->assertNotNull($m->delivered_at, 'delivered ack recorded');
        $this->assertNotNull($m->opened_at, 'read receipt recorded on opened_at');
        $this->assertTrue(
            DB::table('comm_events')->where('message_id', $m->id)->whereIn('event', ['delivered', 'opened'])->count() >= 2,
            'delivered + read audit events written'
        );
    }

    // 15b. a reply is attributed to the most recent marketing message
    public function test_reply_attribution(): void
    {
        $t = $this->tenant();
        $this->profile($t, '256700000001', true);
        $c = $this->campaign($t);
        app(CampaignService::class)->launch($c);
        $this->artisan('comms:campaign-sweep');

        WhatsAppStatusIngest::recordReply($t->id, '256700000001');

        $m = $this->rows($c)->firstWhere('status', 'sent');
        $this->assertNotNull($m->refresh()->replied_at, 'inbound reply credited to the campaign message');
    }
}
