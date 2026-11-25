<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * A salesperson's own WhatsApp number (DishNet, 25 Nov 2026: "Aida has a separate number and we want her to work
 * the same as Justus"), and the alert for a line that receives messages late (24 Nov 2026: 7 hours late while the
 * line said "open"). Names and numbers are test values.
 */
class SalesLineTest extends TestCase
{
    use RefreshDatabase;

    private User $justus;

    private User $aida;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'main_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => $settings + [
            'vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'business_hours' => '24/7', 'isp_quote_followup' => true,
            'owner_alert_phone' => '211900000100',
        ]]);
        app(TenantContext::class)->set($t->id);
        $this->justus = User::create(['tenant_id' => $t->id, 'name' => 'Justus', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'j' . $t->id . '@t.test', 'password' => 'secret']);
        $this->aida = User::create(['tenant_id' => $t->id, 'name' => 'Aida', 'role' => 'sales', 'phone' => '211922000002', 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);
        WhatsappChannel::create(['tenant_id' => $t->id, 'instance' => 'aida_' . $t->id, 'driver' => 'evolution', 'number' => '211922000002',
            'label' => 'Aida', 'is_primary' => false, 'active' => true, 'owner_user_id' => $this->aida->id]);

        return $t;
    }

    public function test_a_new_customer_on_a_salespersons_number_becomes_theirs_and_nobody_else_is_moved(): void
    {
        $t = $this->business();
        $o = app(SalesOwnership::class);

        $this->assertSame($this->aida->id, $o->ownerForLine($t, '211911000001', 'aida_' . $t->id)?->id, 'a new customer on her number is hers');
        $this->assertSame($this->aida->id, (int) CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211911000001')->value('owner_user_id'));
        $this->assertSame($this->aida->id, $o->ownerForEnquiry($t, '211911000001')->id, 'quotations, alerts and follow-ups then go to her');

        $o->ownerForEnquiry($t, '211911000002');                                        // Justus's customer already (first in turn)
        $this->assertSame($this->justus->id, $o->ownerForLine($t, '211911000002', 'aida_' . $t->id)->id, 'an existing customer keeps their salesperson');

        $this->assertNull($o->ownerForLine($t, '211911000003', (string) $t->whatsapp_instance), 'the business\'s own number: decided as before (in turn)');
        $this->assertNull(CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211911000003')->value('owner_user_id'));

        $this->aida->update(['role' => 'staff']);
        $this->assertNull($o->ownerForLine($t, '211911000004', 'aida_' . $t->id), 'the number\'s owner must still be a salesperson');

        $shop = Tenant::factory()->create(['settings' => ['vertical' => 'grocery']]);
        $this->assertNull($o->ownerForLine($shop, '211911000005', 'aida_' . $t->id), 'other businesses: nothing changes');

        // The message job and the missed-call handler use it before anything else picks a salesperson.
        $this->assertStringContainsString('->ownerForLine($tenant, $from, (string) ($this->incoming[\'instance\'] ?? \'\'))', file_get_contents(app_path('Jobs/ProcessIncomingMessage.php')));
        $missed = file_get_contents(app_path('Services/Isp/MissedCall.php'));
        $this->assertLessThan(strpos($missed, '->ownerForEnquiry($t, $phone'), strpos($missed, '->ownerForLine($t, $phone, $instance)'));
    }

    public function test_the_owner_of_a_number_is_set_by_command_and_must_be_a_salesperson(): void
    {
        $t = $this->business();
        WhatsappChannel::create(['tenant_id' => $t->id, 'instance' => 'spare_' . $t->id, 'driver' => 'evolution', 'is_primary' => false, 'active' => true]);
        $staff = User::create(['tenant_id' => $t->id, 'name' => 'Clerk', 'role' => 'staff', 'phone' => '211922000009', 'email' => 'c' . $t->id . '@t.test', 'password' => 'secret']);

        $this->artisan('wa:line-owner', ['tenant' => $t->id, 'instance' => 'spare_' . $t->id, '--user' => $staff->id])->assertFailed();
        $this->assertNull(WhatsappChannel::where('instance', 'spare_' . $t->id)->value('owner_user_id'));

        $this->artisan('wa:line-owner', ['tenant' => $t->id, 'instance' => 'spare_' . $t->id, '--user' => $this->justus->id])
            ->expectsOutputToContain('now belongs to Justus')->assertSuccessful();
        $this->assertSame($this->justus->id, (int) WhatsappChannel::where('instance', 'spare_' . $t->id)->value('owner_user_id'));

        $this->artisan('wa:line-owner', ['tenant' => $t->id, 'instance' => 'spare_' . $t->id, '--clear' => true])->assertSuccessful();
        $this->assertNull(WhatsappChannel::where('instance', 'spare_' . $t->id)->value('owner_user_id'));

        $this->artisan('wa:line-owner', ['tenant' => $t->id])->expectsOutputToContain('belongs to: Aida')->assertSuccessful();
    }

    public function test_a_follow_up_to_a_customer_of_her_number_goes_out_from_her_number_in_her_name(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $line = 'aida_' . $t->id;
        $gw = new class implements \App\Contracts\WhatsAppGateway {
            public array $sent = [];
            public function sendText(string $i, string $to, string $m, ?array $q = null): array { $this->sent[] = [$i, $to, $m]; return ['key' => ['id' => 'X']]; }
            public function sendImage(string $i, string $to, string $m, string $c = ''): array { return []; }
            public function markRead(string $i, string $id): void {}
            public function parseIncoming(array $payload): ?array { return null; }
            public function fetchContacts(string $i): array { return []; }
        };
        $this->app->instance(WhatsAppManager::class, new class($gw) extends WhatsAppManager {
            public function __construct(private \App\Contracts\WhatsAppGateway $g) {}
            public function forTenant(Tenant $t): \App\Contracts\WhatsAppGateway { return $this->g; }
        });
        app(SalesOwnership::class)->ownerForLine($t, '211911000066', $line);
        foreach ([['in', 'How much is Starlink for my shop in Munuki?', 30], ['out', 'The Starlink Mini suits a shop. Which area are you in?', 29.9]] as [$dir, $body, $h]) {
            $m = Message::create(['tenant_id' => $t->id, 'customer_phone' => '211911000066', 'instance' => $line, 'direction' => $dir,
                'sender' => $dir === 'in' ? 'customer' : 'bot', 'body' => $body]);
            $m->forceFill(['created_at' => now()->subMinutes((int) ($h * 60))])->save();
        }
        Conversation::firstOrCreate(['tenant_id' => $t->id, 'customer_phone' => '211911000066', 'instance' => $line], ['state' => [], 'cart' => [], 'last_message_at' => now()->subHours(29)]);

        $this->artisan('isp:quote-followup', ['--tenant' => $t->id, '--no-pause' => true])->assertSuccessful();

        $this->assertCount(1, $gw->sent);
        $this->assertSame($line, $gw->sent[0][0], 'from her number, the one the customer wrote to');
        $this->assertStringContainsString('this is Aida from', $gw->sent[0][2]);
        $this->assertSame($line, Message::where('customer_phone', '211911000066')->where('direction', 'out')->latest('id')->value('instance'), 'and it shows in that chat');
        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211922000002');
    }

    public function test_a_line_that_receives_messages_late_alerts_the_business_once(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->business();
        $now = now()->getTimestamp();
        $rec = fn (string $id, int $agoMin) => ['key' => ['id' => $id, 'remoteJid' => '211911000001@s.whatsapp.net', 'fromMe' => false],
            'message' => ['conversation' => 'What are the available data plans for Starlink?'], 'messageTimestamp' => $now - $agoMin * 60];
        $store = [$rec('LATE1', 40), $rec('LATE2', 90), $rec('OK1', 30), $rec('NEW1', 2)];
        DB::table('bot_events')->insert(['tenant_id' => $t->id, 'trace' => 'OK1', 'phone' => '211911000001', 'stage' => 'queued', 'created_at' => now()]);
        $evo = Mockery::mock(EvolutionAdmin::class);
        $evo->shouldReceive('configured')->andReturn(true);
        $evo->shouldReceive('state')->andReturnUsing(fn ($i) => $i === (string) $t->whatsapp_instance ? 'open' : 'close');
        $evo->shouldReceive('findMessages')->andReturn($store);
        $this->app->instance(EvolutionAdmin::class, $evo);

        $this->artisan('wa:delivery-check', ['--tenant' => $t->id])->expectsOutputToContain('2 not received (oldest 90 min)')->assertSuccessful();

        Bus::assertDispatched(NotifyOwner::class, fn ($j) => $j->to === '211900000100' && str_contains($j->text, 'is receiving messages late') && str_contains($j->text, '2 customer messages'));
        $this->assertTrue(DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'wa_late')->exists());

        $this->artisan('wa:delivery-check', ['--tenant' => $t->id])->assertSuccessful();
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);   // once per line every 2 hours

        // Everything received: no alert.
        DB::table('bot_events')->insert([['tenant_id' => $t->id, 'trace' => 'LATE1', 'phone' => 'x', 'stage' => 'queued', 'created_at' => now()],
            ['tenant_id' => $t->id, 'trace' => 'LATE2', 'phone' => 'x', 'stage' => 'queued', 'created_at' => now()]]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->artisan('wa:delivery-check', ['--tenant' => $t->id])->expectsOutputToContain('0 not received')->assertSuccessful();
        Bus::assertDispatchedTimes(NotifyOwner::class, 1);
    }
}
