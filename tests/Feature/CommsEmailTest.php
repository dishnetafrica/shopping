<?php

namespace Tests\Feature;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\CommSuppression;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\EmailService;
use App\Services\Comms\ProviderResult;
use App\Services\Comms\Providers\SendgridProvider;
use App\Services\Comms\Providers\SmtpProvider;
use App\Services\Comms\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Communication Center M1/M2 — engine tests.
 *
 * Providers are faked by container-binding the driver classes (EmailDispatcher
 * resolves them via app()), so the failover walk, backoff math, suppression
 * gate, webhook ingest and the go-live guard are all exercised with zero
 * network. Runs on the standard sqlite test DB (phpunit.xml).
 */
class CommsEmailTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $withProvider = true, bool $live = false): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);   // scoped reads (refresh()) need the ctx
        $t->putSetting('comms_email.from_email', 'shop@familyshoppers.net');
        $t->putSetting('comms_email.from_name', 'Family Shoppers');
        if ($live) $t->putSetting('comms_email_live', true);
        if ($withProvider) {
            CommProvider::withoutGlobalScope('tenant')->create([
                'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'Primary',
                'driver' => 'smtp', 'config' => ['host' => 'x'], 'priority' => 1, 'is_active' => true,
            ]);
        }
        return $t;
    }

    private function fake(string $driverClass, bool $ok, ?string $err = null): object
    {
        $impl = new class($ok, $err) implements EmailProvider {
            public int $calls = 0;
            public function __construct(private bool $ok, private ?string $err) {}
            public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
            {
                $this->calls++;
                return $this->ok ? ProviderResult::sent('fake-id-123') : ProviderResult::failed($this->err ?? 'boom');
            }
        };
        $this->app->instance($driverClass, $impl);
        return $impl;
    }

    // ------------------------------------------------------------ renderer

    public function test_renderer_escapes_html_but_passes_html_suffixed_vars(): void
    {
        $r = app(TemplateRenderer::class);
        $out = $r->renderString('Hi {{name}} {{table_html}} {{missing}}', [
            'name' => '<b>Asish</b>', 'table_html' => '<table><tr><td>x</td></tr></table>',
        ], true);

        $this->assertStringContainsString('&lt;b&gt;Asish&lt;/b&gt;', $out, 'plain vars must be escaped in html context');
        $this->assertStringContainsString('<table><tr><td>x</td></tr></table>', $out, '_html vars pass through raw');
        $this->assertStringNotContainsString('{{missing}}', $out, 'unknown vars render empty, never literal');
    }

    // ------------------------------------------------- happy path + failover

    public function test_send_via_first_provider_and_counters(): void
    {
        $t = $this->tenant();
        $smtp = $this->fake(SmtpProvider::class, true);

        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>');

        $m->refresh();
        $this->assertSame(CommMessage::ST_SENT, $m->status);
        $this->assertSame('fake-id-123', $m->provider_message_id);
        $this->assertSame(1, $smtp->calls);
        $this->assertSame(1, (int) CommProvider::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->value('sent_today'));
        $this->assertStringContainsString('/c/e/o/'.$m->token.'.gif', (string) $m->html_body, 'open pixel injected');
    }

    public function test_failover_walks_to_second_provider(): void
    {
        $t = $this->tenant();                       // provider 1: smtp (will fail)
        CommProvider::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'Backup',
            'driver' => 'sendgrid', 'config' => ['api_key' => 'k'], 'priority' => 2, 'is_active' => true,
        ]);
        $smtp = $this->fake(SmtpProvider::class, false, 'smtp down');
        $sg   = $this->fake(SendgridProvider::class, true);

        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>');

        $m->refresh();
        $this->assertSame(CommMessage::ST_SENT, $m->status, 'second provider must win');
        $this->assertSame(1, $smtp->calls);
        $this->assertSame(1, $sg->calls);
        $backup = CommProvider::withoutGlobalScope('tenant')->where('name', 'Backup')->first();
        $this->assertSame($backup->id, $m->provider_id, 'sent must be attributed to the backup');
        $primary = CommProvider::withoutGlobalScope('tenant')->where('name', 'Primary')->first();
        $this->assertSame('smtp down', $primary->last_error, 'failed provider records its error');
    }

    public function test_all_fail_backs_off_then_exhausts_to_failed(): void
    {
        $t = $this->tenant();
        $this->fake(SmtpProvider::class, false, 'always down');

        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>', null, ['max_attempts' => 2]);

        $m->refresh();
        $this->assertSame(CommMessage::ST_RETRY, $m->status);
        $this->assertSame(1, $m->attempts);
        $this->assertTrue($m->next_attempt_at->isFuture(), 'backoff must schedule the future');

        // Force-due, run the sweeper => attempt 2 of 2 => failed.
        $m->next_attempt_at = now()->subMinute();
        $m->save();
        $this->artisan('comms:email-sweep')->assertExitCode(0);

        $m->refresh();
        $this->assertSame(CommMessage::ST_FAILED, $m->status);
        $this->assertSame(2, $m->attempts);
        $this->assertStringContainsString('always down', (string) $m->last_error);
    }

    public function test_daily_limit_exhausted_provider_is_skipped(): void
    {
        $t = $this->tenant();
        CommProvider::withoutGlobalScope('tenant')->where('tenant_id', $t->id)->update([
            'daily_limit' => 5, 'sent_today' => 5, 'sent_date' => now()->toDateString(),
        ]);
        CommProvider::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'channel' => 'email', 'name' => 'Backup',
            'driver' => 'sendgrid', 'config' => ['api_key' => 'k'], 'priority' => 2, 'is_active' => true,
        ]);
        $smtp = $this->fake(SmtpProvider::class, true);   // would succeed, but has no budget
        $sg   = $this->fake(SendgridProvider::class, true);

        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>');

        $m->refresh();
        $this->assertSame(CommMessage::ST_SENT, $m->status);
        $this->assertSame(0, $smtp->calls, 'over-limit provider must not be called');
        $this->assertSame(1, $sg->calls);
    }

    // ------------------------------------------------------------ gates

    public function test_suppressed_recipient_is_cancelled_not_sent(): void
    {
        $t = $this->tenant();
        $smtp = $this->fake(SmtpProvider::class, true);
        CommSuppression::add($t->id, 'Bounced@Example.com', 'bounced', 'test');

        $m = app(EmailService::class)->queueRaw($t, 'bounced@example.com', 'Hello', '<p>Hi</p>');

        $this->assertSame(CommMessage::ST_CANCELLED, $m->refresh()->status);
        $this->assertSame(0, $smtp->calls);
    }

    public function test_no_active_provider_fails_with_clear_error(): void
    {
        $t = $this->tenant(withProvider: false);
        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>');
        $m->refresh();
        $this->assertSame(CommMessage::ST_FAILED, $m->status);
        $this->assertStringContainsString('no active email provider', (string) $m->last_error);
    }

    public function test_missing_template_logs_failed_row(): void
    {
        $t = $this->tenant();
        $m = app(EmailService::class)->queueTemplate($t, 'does_not_exist', 'a@b.com', []);
        $this->assertSame(CommMessage::ST_FAILED, $m->refresh()->status);
        $this->assertStringContainsString("template 'does_not_exist' not found", (string) $m->last_error);
    }

    // ------------------------------------------------------------ webhooks

    public function test_sendgrid_bounce_webhook_sets_flags_and_suppresses(): void
    {
        $t = $this->tenant();
        $this->fake(SmtpProvider::class, true);
        $m = app(EmailService::class)->queueRaw($t, 'buyer@example.com', 'Hello', '<p>Hi</p>');
        $secret = 'testsecret123';
        $t->putSetting('comms_email.webhook_secret', $secret);

        $this->postJson('/papi/comms/hook/sendgrid?t='.$t->id.'&s='.$secret, [
            ['event' => 'bounce', 'email' => 'buyer@example.com', 'comm_token' => $m->token],
        ])->assertOk();

        $m->refresh();
        $this->assertNotNull($m->bounced_at);
        $this->assertTrue(CommSuppression::isSuppressed($t->id, 'buyer@example.com'));
        $this->assertSame(1, (int) DB::table('comm_events')->where('message_id', $m->id)->where('event', 'bounced')->count());
    }

    public function test_webhook_rejects_bad_secret(): void
    {
        $t = $this->tenant();
        $t->putSetting('comms_email.webhook_secret', 'right');
        $this->postJson('/papi/comms/hook/sendgrid?t='.$t->id.'&s=wrong', [['event' => 'bounce']])->assertStatus(403);
    }

    // --------------------------------------------------------- go-live gate

    public function test_go_live_refused_without_provider(): void
    {
        $t = $this->tenant(withProvider: false);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);

        $this->postJson('/papi/comms/settings/save', [
            'from_email' => 'shop@familyshoppers.net', 'live' => true,
        ])->assertStatus(422);

        $this->assertFalse((bool) $t->fresh()->setting('comms_email_live', false));
    }

    public function test_go_live_saves_when_configured(): void
    {
        $t = $this->tenant();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);

        $this->postJson('/papi/comms/settings/save', [
            'from_email' => 'shop@familyshoppers.net', 'live' => true,
        ])->assertOk();

        $this->assertTrue((bool) $t->fresh()->setting('comms_email_live', false));
    }
}
