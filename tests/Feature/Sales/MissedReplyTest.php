<?php

namespace Tests\Feature\Sales;

use App\Contracts\WhatsAppGateway;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * bot:recover-missed with the real database: a customer whose reply never got through (WhatsApp dropped,
 * the retry was dropped as a duplicate) gets ONE late reply once the line is back — and nobody else does.
 * The AI is faked (it records the call and logs a reply); Evolution is mocked as open/closed.
 */
class MissedReplyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{from:string,text:string,media:array}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$calls = [];
        $this->app->instance(AiBrain::class, new class extends AiBrain {
            public function handle(Tenant $tenant, Conversation $convo, string $from, string $text, WhatsAppGateway $gateway, array $media = []): bool
            {
                MissedReplyTest::$calls[] = ['from' => $from, 'text' => $text, 'media' => $media];
                MessageLog::record($tenant->id, $from, (string) $tenant->whatsapp_instance, 'out', 'bot', 'Sorry for the late reply — yes, it works right away.');

                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function line(string $state = 'open'): void
    {
        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('configured')->andReturn(true);
        $evo->shouldReceive('state')->andReturn($state);
        $this->app->instance(EvolutionAdmin::class, $evo);
    }

    private function business(bool $recover = true): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'bot_mode' => 'ai', 'bot_recover_missed' => $recover]]);
        $t->whatsapp_instance = 'shopbot_t' . $t->id;
        $t->whatsapp_driver = 'evolution';
        $t->save();

        return $t;
    }

    /** A customer message the bot started on and never finished (the real failure of 16 Nov 2026). */
    private function lostReply(Tenant $t, string $phone, string $text, int $minutesAgo = 10, array $stages = ['queued', 'started', 'ai_usage']): Conversation
    {
        app(TenantContext::class)->set($t->id);
        MessageLog::record($t->id, $phone, (string) $t->whatsapp_instance, 'in', 'customer', $text);
        $at = now()->subMinutes($minutesAgo);
        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->firstOrFail();
        $c->last_inbound_at = $at;
        $c->save();
        foreach ($stages as $i => $stage) {
            DB::table('bot_events')->insert(['tenant_id' => $t->id, 'trace' => 'm1', 'phone' => $phone, 'stage' => $stage,
                'detail' => $stage === 'skipped' ? 'duplicate message id' : null, 'created_at' => $at->copy()->addSeconds($i + 1)]);
        }
        app(TenantContext::class)->reset();

        return $c;
    }

    public function test_a_lost_reply_is_answered_once_when_the_line_is_back(): void
    {
        $this->line('open');
        $t = $this->business();
        $c = $this->lostReply($t, '211925387392', 'Can work residential immediately', 10, ['queued', 'started', 'ai_usage', 'skipped']);

        $this->artisan('bot:recover-missed')->assertSuccessful();

        $this->assertCount(1, self::$calls);
        $this->assertSame('211925387392', self::$calls[0]['from']);
        $this->assertSame('Can work residential immediately', self::$calls[0]['text'], 'the customer\'s own words, unchanged');
        $this->assertTrue(self::$calls[0]['media']['late'] ?? false, 'the AI is told to open with an apology');
        $this->assertSame(0, (int) $c->fresh()->unread);
        $this->assertTrue((bool) ($c->fresh()->state['missed_reply']['done'] ?? false));
        $this->assertTrue(DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'missed_replied')->exists());

        $this->artisan('bot:recover-missed')->assertSuccessful();
        $this->assertCount(1, self::$calls, 'never twice');
    }

    public function test_nobody_else_gets_a_late_reply(): void
    {
        $this->line('open');
        $t = $this->business();
        $this->lostReply($t, '211900000001', 'Thanks', 10, ['started', 'ai_replied']);                 // the bot finished (it chose silence)
        $this->lostReply($t, '211900000002', 'Price?', 1);                                               // too recent
        $this->lostReply($t, '211900000003', 'Hello', 60 * 30);                                          // older than 24 h
        $this->lostReply($t, '211900000004', 'Staff test', 10);
        $t->putSetting('bot_muted', ['211900000004']);                                                   // internal number
        $taken = $this->lostReply($t, '211900000005', 'Need help', 10);
        $taken->agent_active = true;
        $taken->save();                                                                                   // a person took over

        $this->artisan('bot:recover-missed')->assertSuccessful();

        $this->assertSame([], self::$calls);
    }

    public function test_it_waits_while_whatsapp_is_down_and_is_off_unless_switched_on(): void
    {
        $this->line('connecting');
        $t = $this->business();
        $this->lostReply($t, '211925387392', 'Can work residential immediately');
        $this->artisan('bot:recover-missed')->assertSuccessful();
        $this->assertSame([], self::$calls, 'nothing is sent while the line is down');

        $this->line('open');
        $off = $this->business(false);
        $this->lostReply($off, '211911111111', 'Price of rice');
        $this->artisan('bot:recover-missed', ['--tenant' => $off->id])->assertSuccessful();
        $this->assertSame([], self::$calls, 'a business that did not switch it on is untouched');

        $this->artisan('bot:recover-missed', ['--tenant' => $t->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame([], self::$calls, 'a dry run sends nothing');
        $this->artisan('bot:recover-missed', ['--tenant' => $t->id])->assertSuccessful();
        $this->assertCount(1, self::$calls, 'and the line back open → the late reply goes out');
    }
}
