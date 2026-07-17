<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Comms\CommCenter;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use App\Services\Comms\Providers\SmtpProvider;
use App\Services\Comms\WhatsAppService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Communication Center M3 — WhatsApp transport + routing matrix.
 *
 * The frozen WhatsAppManager is container-faked (public API only), so these
 * tests exercise the adapter, the channel-guarded claims, the sweeper's
 * channel routing and the routing endpoints with zero network.
 */
class CommsWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var object fake gateway with ->sent[] / ->fail flags */
    private object $gw;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst-test']);
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('comms_email.from_email', 'shop@familyshoppers.net');
        return $t;
    }

    private function fakeWhatsApp(bool $ok = true): void
    {
        $gw = new class($ok) implements WhatsAppGateway {
            public array $sent = [];
            public function __construct(public bool $ok) {}
            public function sendText(string $instance, string $to, string $message, ?array $quoted = null): array
            {
                if (! $this->ok) throw new \RuntimeException('wa gateway down');
                $this->sent[] = [$instance, $to, $message];
                return ['key' => ['id' => 'WAID-77']];
            }
            public function sendImage(string $instance, string $to, string $media, string $caption = ''): array { return []; }
            public function markRead(string $instance, string $messageId): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $instance): array { return []; }
        };
        $this->gw = $gw;

        $mgr = new class($gw) extends WhatsAppManager {
            public function __construct(private WhatsAppGateway $gw) {}
            public function forTenant(Tenant $t): WhatsAppGateway { return $this->gw; }
        };
        $this->app->instance(WhatsAppManager::class, $mgr);
    }

    private function seedWaTemplate(Tenant $t, string $key = 'otp', string $text = 'Code: {{otp_code}} from {{business_name}}'): void
    {
        DB::table('comm_templates')->insert([
            'tenant_id' => $t->id, 'channel' => 'whatsapp', 'key' => $key, 'locale' => 'en',
            'name' => $key, 'subject' => null, 'html_body' => null, 'text_body' => $text,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- transport

    public function test_whatsapp_template_send_records_wa_id_and_transcript(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp(ok: true);
        $this->seedWaTemplate($t);

        $m = app(WhatsAppService::class)->queueTemplate($t, 'otp', '+256 700 123456', ['otp_code' => '4471']);

        $m->refresh();
        $this->assertSame(CommMessage::ST_SENT, $m->status);
        $this->assertSame('whatsapp', $m->channel);
        $this->assertSame('WAID-77', $m->provider_message_id);
        $this->assertSame('256700123456', $m->to_email, 'phone normalised to digits');
        $this->assertStringContainsString('4471', $this->gw->sent[0][2]);
        $this->assertStringNotContainsString('{{', $this->gw->sent[0][2], 'all vars rendered');
        $this->assertSame(1, DB::table('messages')->where('tenant_id', $t->id)->where('direction', 'out')->count(),
            'outbound must land in the Chats transcript (MessageLog)');
    }

    public function test_whatsapp_failure_backs_off_with_retry(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp(ok: false);
        $this->seedWaTemplate($t);

        $m = app(WhatsAppService::class)->queueTemplate($t, 'otp', '256700123456', ['otp_code' => '1']);

        $m->refresh();
        $this->assertSame(CommMessage::ST_RETRY, $m->status);
        $this->assertSame(1, $m->attempts);
        $this->assertTrue($m->next_attempt_at->isFuture());
        $this->assertStringContainsString('wa gateway down', (string) $m->last_error);
    }

    public function test_missing_text_template_fails_loud(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp();

        $m = app(WhatsAppService::class)->queueTemplate($t, 'no_such_key', '256700123456', []);
        $this->assertSame(CommMessage::ST_FAILED, $m->refresh()->status);
        $this->assertStringContainsString('no whatsapp/email text template', (string) $m->last_error);
    }

    public function test_email_template_text_body_is_the_fallback(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp();
        DB::table('comm_templates')->insert([
            'tenant_id' => $t->id, 'channel' => 'email', 'key' => 'invoice', 'locale' => 'en',
            'name' => 'Invoice', 'subject' => 'x', 'html_body' => '<p>x</p>',
            'text_body' => 'Invoice {{invoice_number}} due', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $m = app(WhatsAppService::class)->queueTemplate($t, 'invoice', '256700123456', ['invoice_number' => 'INV-9']);

        $this->assertSame(CommMessage::ST_SENT, $m->refresh()->status);
        $this->assertStringContainsString('INV-9', $this->gw->sent[0][2]);
    }

    // -------------------------------------------------------- routing matrix

    public function test_publish_routes_to_both_channels_when_matrix_says_so(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp();
        $this->seedWaTemplate($t, 'invoice', 'Invoice {{invoice_number}}');
        DB::table('comm_templates')->insert([
            'tenant_id' => $t->id, 'channel' => 'email', 'key' => 'invoice', 'locale' => 'en',
            'name' => 'Invoice', 'subject' => 'Invoice {{invoice_number}}', 'html_body' => '<p>{{invoice_number}}</p>',
            'text_body' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        CommProvider::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'P', 'driver' => 'smtp',
            'config' => ['host' => 'x'], 'priority' => 1, 'is_active' => true,
        ]);
        $this->app->instance(SmtpProvider::class, new class implements EmailProvider {
            public function send($a, $m, $f): ProviderResult { return ProviderResult::sent('mid'); }
        });
        $t->putSetting('comms_routes', ['invoice' => ['email', 'whatsapp']]);

        $out = app(CommCenter::class)->publish($t, 'invoice',
            ['email' => 'a@b.com', 'phone' => '256700123456', 'name' => 'Asish'],
            ['invoice_number' => 'INV-3']);

        $this->assertCount(2, $out);
        $channels = collect($out)->map(fn ($m) => $m->refresh()->channel)->sort()->values()->all();
        $this->assertSame(['email', 'whatsapp'], $channels);
        foreach ($out as $m) $this->assertSame(CommMessage::ST_SENT, $m->status);
    }

    public function test_default_matrix_stays_email_only(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp();
        $this->seedWaTemplate($t, 'invoice', 'x');

        // No comms_routes override, no email address given => defaults route email-only
        // and there is no email recipient, so NOTHING sends on WhatsApp.
        $out = app(CommCenter::class)->publish($t, 'invoice', ['phone' => '256700123456'], []);
        $this->assertCount(0, $out, 'whatsapp must be explicit opt-in via the matrix');
    }

    // ------------------------------------------------------- sweeper routing

    public function test_sweeper_routes_rows_to_the_matching_channel(): void
    {
        $t = $this->tenant();
        $this->fakeWhatsApp();
        $this->seedWaTemplate($t);
        CommProvider::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'P', 'driver' => 'smtp',
            'config' => ['host' => 'x'], 'priority' => 1, 'is_active' => true,
        ]);
        $this->app->instance(SmtpProvider::class, new class implements EmailProvider {
            public function send($a, $m, $f): ProviderResult { return ProviderResult::sent('mid'); }
        });

        // Two scheduled-in-the-past rows (so the enqueue-time dispatch didn't fire).
        $mkA = app(\App\Services\Comms\EmailService::class)->queueRaw($t, 'a@b.com', 'S', '<p>x</p>', null,
            ['scheduled_at' => now()->addHour()]);
        $mkB = app(WhatsAppService::class)->queueTemplate($t, 'otp', '256700123456', ['otp_code' => '9'],
            ['scheduled_at' => now()->addHour()]);
        DB::table('comm_messages')->whereIn('id', [$mkA->id, $mkB->id])
            ->update(['scheduled_at' => now()->subMinute(), 'created_at' => now()->subMinutes(2)]);

        $this->artisan('comms:email-sweep')->assertExitCode(0);

        $this->assertSame(CommMessage::ST_SENT, $mkA->refresh()->status);
        $this->assertSame(CommMessage::ST_SENT, $mkB->refresh()->status);
        $this->assertSame('WAID-77', $mkB->provider_message_id);
    }

    // ------------------------------------------------------- routes endpoint

    public function test_routes_save_validates_events_and_channels(): void
    {
        $t = $this->tenant();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);

        $this->postJson('/papi/comms/routes/save', ['routes' => [
            'invoice'      => ['email', 'whatsapp', 'pigeon'],   // pigeon dropped
            'made_up_evt'  => ['email'],                          // unknown event dropped
            'otp'          => [],                                 // muted
        ]])->assertOk();

        $saved = $t->fresh()->setting('comms_routes');
        $this->assertSame(['email', 'whatsapp'], $saved['invoice']);
        $this->assertArrayNotHasKey('made_up_evt', $saved);
        $this->assertSame([], $saved['otp']);

        $get = $this->getJson('/papi/comms/routes')->assertOk()->json();
        $inv = collect($get['events'])->firstWhere('event', 'invoice');
        $this->assertSame(['email', 'whatsapp'], $inv['channels']);
    }
}
