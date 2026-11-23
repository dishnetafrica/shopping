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

    /** @var array<int,array{from:string,text:string,media:array,instance:string}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$calls = [];
        $this->app->instance(AiBrain::class, new class extends AiBrain {
            public function handle(Tenant $tenant, Conversation $convo, string $from, string $text, WhatsAppGateway $gateway, array $media = []): bool
            {
                MissedReplyTest::$calls[] = ['from' => $from, 'text' => $text, 'media' => $media, 'instance' => (string) $tenant->whatsapp_instance];
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

    /** @param string|array<string,string> $state one state for every number, or instance => state */
    private function line(string|array $state = 'open'): void
    {
        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('configured')->andReturn(true);
        $evo->shouldReceive('state')->andReturnUsing(fn ($i) => is_array($state) ? ($state[$i] ?? 'close') : $state);
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
    private function lostReply(Tenant $t, string $phone, string $text, int $minutesAgo = 10, array $stages = ['queued', 'started', 'ai_usage'], ?string $instance = null): Conversation
    {
        app(TenantContext::class)->set($t->id);
        $instance ??= (string) $t->whatsapp_instance;
        $waId = 'WA' . $t->id . '-' . substr(md5($phone . $instance . $text), 0, 12);   // the WhatsApp message id = its step-log trace
        MessageLog::record($t->id, $phone, $instance, 'in', 'customer', $text, $waId);
        $at = now()->subMinutes($minutesAgo);
        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where('instance', $instance)->firstOrFail();
        $c->last_inbound_at = $at;
        $c->save();
        foreach ($stages as $i => $stage) {
            DB::table('bot_events')->insert(['tenant_id' => $t->id, 'trace' => $waId, 'phone' => $phone, 'stage' => $stage,
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

    /** Codex review on #155: the late reply answers THIS chat's words and goes out on the number a normal reply would use. */
    public function test_it_answers_this_chats_words_and_replies_from_the_right_number(): void
    {
        $t = $this->business();
        $second = $t->whatsapp_instance . '_b';
        \App\Models\WhatsappChannel::create(['tenant_id' => $t->id, 'instance' => $second, 'driver' => 'evolution', 'number' => '211900000099', 'is_primary' => false, 'active' => true]);

        // The same customer wrote to both numbers; only the primary chat lost its reply, and it is older.
        $this->lostReply($t, '211925387392', 'Words on the primary number', 20);
        $other = $this->lostReply($t, '211925387392', 'Words on the second number', 5, ['started', 'ai_replied'], $second);
        $this->assertSame($second, $other->instance);

        // Primary down, second number connected and set as the fallback → reply from the second number.
        $t->putSetting('wa_send_instance', $second);
        $this->line([$t->whatsapp_instance => 'close', $second => 'open']);
        $this->artisan('bot:recover-missed', ['--tenant' => $t->id])->assertSuccessful();

        $this->assertCount(1, self::$calls);
        $this->assertSame('Words on the primary number', self::$calls[0]['text'], 'never the other chat\'s message');
        $this->assertSame($second, self::$calls[0]['instance'], 'the connected fallback, as a normal reply would');
        $this->assertSame($t->whatsapp_instance, $t->fresh()->whatsapp_instance, 'the business itself is not changed');
    }

    /**
     * DishNet, 23 Nov 2026: someone asked from the phone "Please share your email address", the customer sent it
     * a minute later, the bot stood down for the person (MissedReply::HELD) — and nobody ever answered.
     */
    private function heldChat(Tenant $t, string $phone, string $text, int $pauseEndsInMinutes): Conversation
    {
        $c = $this->lostReply($t, $phone, $text, 20, ['queued', 'started', 'email_captured']);
        DB::table('bot_events')->insert(['tenant_id' => $t->id, 'trace' => 'WA' . $t->id . '-' . substr(md5($phone . $t->whatsapp_instance . $text), 0, 12),
            'phone' => $phone, 'stage' => 'skipped', 'detail' => \App\Services\Bot\MissedReply::HELD, 'created_at' => now()->subMinutes(20)->addSeconds(5)]);
        // The person's own reply came BEFORE the customer's message, and paused the bot.
        DB::table('messages')->insert(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $c->instance, 'direction' => 'out',
            'sender' => 'human', 'body' => 'Please share your email address', 'created_at' => now()->subMinutes(21), 'updated_at' => now()->subMinutes(21)]);
        $c->state = ['human_until' => time() + $pauseEndsInMinutes * 60];
        $c->unread = 0;                                  // someone opened the chat in the panel: that is not a reply
        $c->save();

        return $c;
    }

    public function test_a_message_held_for_a_person_who_never_replied_is_answered_when_the_pause_ends(): void
    {
        $this->line('open');
        $t = $this->business();

        $this->heldChat($t, '211929577612', 'Seralomin@gmail.com', 5);
        $this->artisan('bot:recover-missed')->assertSuccessful();
        $this->assertSame([], self::$calls, 'while the pause lasts, the person has it');

        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', '211929577612')->first();
        $c->state = ['human_until' => time() - 60];
        $c->save();
        $this->artisan('bot:recover-missed')->assertSuccessful();
        $this->assertCount(1, self::$calls, 'the pause is over and nobody replied');
        $this->assertSame('Seralomin@gmail.com', self::$calls[0]['text']);
        $this->assertTrue(self::$calls[0]['media']['late'] ?? false, 'it opens with an apology');

        $this->artisan('bot:recover-missed')->assertSuccessful();
        $this->assertCount(1, self::$calls, 'never twice');
    }

    public function test_a_held_message_the_person_did_answer_is_left_alone(): void
    {
        $this->line('open');
        $t = $this->business();
        $c = $this->heldChat($t, '211900000007', 'Can you install today?', -1);
        DB::table('messages')->insert(['tenant_id' => $t->id, 'customer_phone' => '211900000007', 'instance' => $c->instance, 'direction' => 'out',
            'sender' => 'human', 'body' => 'Yes, at 3 PM', 'created_at' => now()->subMinutes(15), 'updated_at' => now()->subMinutes(15)]);

        $this->artisan('bot:recover-missed')->assertSuccessful();

        $this->assertSame([], self::$calls, 'the person replied after the customer: nothing from the bot');
    }

    /** Codex on #177: the reply was typed on the phone while the customer's message waited to be processed. */
    public function test_a_phone_reply_logged_before_the_delayed_message_still_counts_as_the_answer(): void
    {
        $this->line('open');
        $t = $this->business();
        $c = $this->heldChat($t, '211900000008', 'Is it available today?', -1);
        // The message ARRIVED (webhook "queued") before the person's reply at -21 min, though it was stamped at -20.
        DB::table('bot_events')->where('tenant_id', $t->id)->where('phone', '211900000008')->where('stage', 'queued')
            ->update(['created_at' => now()->subMinutes(22)]);

        $this->artisan('bot:recover-missed')->assertSuccessful();

        $this->assertSame([], self::$calls, 'the person answered it: no second reply from the bot');
    }

    /** Codex on #177: an old pause on a chat whose latest message was not held never takes a place in the batch. */
    public function test_a_stale_pause_is_not_a_candidate(): void
    {
        $this->line('open');
        $t = $this->business();
        $c = $this->lostReply($t, '211900000009', 'Thanks', 10, ['started', 'ai_no_reply']);
        $c->state = ['human_until' => time() - 3600];
        $c->unread = 0;
        $c->save();

        $this->artisan('bot:recover-missed', ['--dry-run' => true])->doesntExpectOutputToContain('211900000009')->assertSuccessful();
    }

    public function test_with_no_fallback_it_waits_for_the_number_the_customer_wrote_to(): void
    {
        $t = $this->business();
        $this->lostReply($t, '211925387392', 'Can work residential immediately');
        $this->line([$t->whatsapp_instance => 'connecting']);
        $this->artisan('bot:recover-missed', ['--tenant' => $t->id])->assertSuccessful();
        $this->assertSame([], self::$calls);
    }
}
