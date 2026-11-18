<?php

namespace Tests\Feature\Sales;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\HistorySync;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Copying a WhatsApp line's history into ShopBot (HistorySync) with the real database and a stand-in for
 * Evolution's store (DishNet, 18 Nov 2026). Every conversation here is made up.
 */
class HistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '225683989098601';   // a hidden-id (@lid) chat, keyed by its digits as live chats are

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function setUpLine(array $records): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => ['vertical' => 'isp', 'currency' => 'USD']]);
        app(TenantContext::class)->set($t->id);
        $this->app->instance(EvolutionAdmin::class, new class($records) extends EvolutionAdmin {
            public function __construct(private array $records) {}
            public function configured(): bool { return true; }
            public function findMessages(string $instance, int $page = 1, int $offset = 200): array { return $page === 1 ? $this->records : []; }
            public function findChats(string $instance): array { return [['remoteJid' => '211911000099@s.whatsapp.net', 'pushName' => '211911000099']]; }
        });

        return $t;
    }

    private function rec(string $id, bool $me, string $text, int $ts, bool $arrayTime = false): array
    {
        return ['key' => ['id' => $id, 'fromMe' => $me, 'remoteJid' => self::CUSTOMER . '@lid'], 'messageTimestamp' => $arrayTime ? ['low' => $ts, 'high' => 0] : $ts,
            'message' => ['conversation' => $text]];
    }

    public function test_history_lands_with_its_own_times_marked_imported_once_and_the_ai_never_reads_it(): void
    {
        $aug = strtotime('2026-08-20 15:49:00');
        $t = $this->setUpLine([
            $this->rec('A1', false, 'Starlink is how much please', $aug),
            $this->rec('B1', true, 'Starlink Mini Kit is $299 one-time, and the Residential plan is $80/month.', $aug + 30, true),
            $this->rec('A2', false, 'What services do you offer?', strtotime('2026-11-15 21:12:00')),
        ]);
        // Today's live chat, logged by ShopBot without a WhatsApp id; Evolution has it too (id C1).
        $live = MessageLog::record($t->id, self::CUSTOMER, $t->whatsapp_instance, 'out', 'bot', 'Hello! Starlink or Fiber?');
        $this->app->instance(EvolutionAdmin::class, new class(array_merge(app(EvolutionAdmin::class)->findMessages('', 1), [
            ['key' => ['id' => 'C1', 'fromMe' => true, 'remoteJid' => self::CUSTOMER . '@lid'], 'messageTimestamp' => $live->created_at->getTimestamp() + 20, 'message' => ['conversation' => 'Hello! Starlink or Fiber?']],
        ])) extends EvolutionAdmin {
            public function __construct(private array $records) {}
            public function configured(): bool { return true; }
            public function findMessages(string $instance, int $page = 1, int $offset = 200): array { return $page === 1 ? $this->records : []; }
            public function findChats(string $instance): array { return [['remoteJid' => '211911000099@s.whatsapp.net', 'pushName' => '211911000099']]; }
        });

        $res = app(HistorySync::class)->run($t);

        $this->assertSame(3, $res['imported']);
        $this->assertSame(1, $res['skipped_known'], 'the live-logged message is not copied again');
        $rows = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::CUSTOMER)->orderBy('created_at')->get();
        $this->assertCount(4, $rows);
        $this->assertSame('2026-08-20 15:49:30', $rows[1]->created_at->format('Y-m-d H:i:s'), 'the {low, high} time is read, not 1970');
        $this->assertSame('evolution', $rows[0]->meta['imported']);
        $this->assertNull($live->fresh()->meta, 'the live message stays as it was');

        $this->assertSame(0, app(HistorySync::class)->run($t)['imported'], 're-running adds nothing');

        // The AI's memory: today's live message only, never the old bot's prices.
        $m = new \ReflectionMethod(AiBrain::class, 'historyMessages');
        $m->setAccessible(true);
        $history = $m->invoke(app(AiBrain::class), $t->id, self::CUSTOMER, 10);
        $this->assertSame([['role' => 'assistant', 'content' => 'Hello! Starlink or Fiber?']], $history);

        // The contact with no messages: listed, not "now"; an id is not a name.
        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', '211911000099')->first();
        $this->assertNotNull($c);
        $this->assertNull($c->last_message_at);
        $this->assertNull(\App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211911000099')->value('name'));
    }

    public function test_the_command_reports_what_it_copied(): void
    {
        $t = $this->setUpLine([$this->rec('A1', false, 'hi', strtotime('2026-09-01 10:00:00'))]);

        $this->artisan('wa:sync-history', ['tenant' => $t->id])->expectsOutputToContain('added 1')->assertSuccessful();

        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', self::CUSTOMER)->first();
        $this->assertSame('2026-09-01 10:00:00', $c->last_message_at->format('Y-m-d H:i:s'), 'at its own time, not now');
        $this->assertNotNull($c->summarized_at, 'dormant history is not queued for the AI summariser');
        $this->assertSame(0, (int) $c->unread);
    }
}
