<?php

namespace Tests\Feature\Sales;

use App\Jobs\NotifyOwner;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `php artisan isp:winback` with the real database and a stand-in for Evolution's stored history: a dry run
 * writes nothing; --apply gives each past customer to a salesperson as a lead with a suggested first
 * message, alerts each salesperson once, sends nothing to customers, and is safe to re-run.
 * Every conversation here is made up.
 */
class IspWinbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function rec(string $phone, int $daysAgo, bool $me, string $text, string $name = ''): array
    {
        static $i = 0;

        return ['key' => ['remoteJid' => $phone . '@s.whatsapp.net', 'fromMe' => $me, 'id' => 'M' . (++$i)], 'pushName' => $name,
            'messageTimestamp' => time() - $daysAgo * 86400, 'message' => ['conversation' => $text]];
    }

    private function setUpBusiness(): Tenant
    {
        $t = Tenant::factory()->create(['whatsapp_instance' => 'inst_' . uniqid(), 'whatsapp_driver' => 'evolution', 'settings' => ['vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'bot_muted' => ['211900000009']]]);
        app(TenantContext::class)->set($t->id);
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'a' . $t->id . '@t.test', 'password' => 'secret']);
        User::create(['tenant_id' => $t->id, 'name' => 'Nyibol Garang', 'role' => 'sales', 'phone' => '211922000002', 'email' => 'n' . $t->id . '@t.test', 'password' => 'secret']);

        $records = [
            $this->rec('211900000001', 20, false, 'I want starlink mini, where is your office?', 'Mary Akech'),
            $this->rec('211900000002', 60, false, 'how much is fiber 20 mbps', 'John'),
            $this->rec('211900000002', 60, true, 'Here are our plans.'),
            $this->rec('211900000003', 30, false, 'Hello! Can I get more info on this?'),
            $this->rec('211900000004', 10, false, 'I got 150,000 SSP! You should try it too https://scam.example/x'),
            $this->rec('211900000005', 10, true, "\u{1F514} HOT LEAD / From: someone"),
            $this->rec('211900000005', 10, false, 'Client not yet replied'),
            $this->rec('211900000009', 10, false, 'staff colleague on the muted list'),
            $this->rec('211900000006', 5, false, 'price?'),
            $this->rec('211900000006', 4, false, 'not interested, stop'),
            $this->rec('211900000007', 15, false, 'my starlink has a red light'),
        ];
        $evo = new class($records) extends EvolutionAdmin {
            public function __construct(private array $records) {}
            public function configured(): bool { return true; }
            public function findMessages(string $instance, int $page = 1, int $offset = 200): array { return $page === 1 ? $this->records : []; }
        };
        $this->app->instance(EvolutionAdmin::class, $evo);

        return $t;
    }

    public function test_a_dry_run_writes_nothing_and_names_the_staff_alert_numbers(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->setUpBusiness();

        $this->artisan('isp:winback', ['tenant' => $t->id])
            ->expectsOutputToContain('[DRY RUN')
            ->expectsOutputToContain('To chase: 4')
            ->expectsOutputToContain('211900000005')                     // received the old bot's staff alerts
            ->assertSuccessful();

        $this->assertSame(0, Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->count());
        $this->assertSame(0, CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNotNull('owner_user_id')->count(), 'no salesperson assigned on a dry run');
        Bus::assertNotDispatched(NotifyOwner::class);
    }

    public function test_apply_gives_each_past_customer_to_a_salesperson_with_a_first_message_and_is_safe_to_rerun(): void
    {
        Bus::fake([NotifyOwner::class]);
        $t = $this->setUpBusiness();

        $this->artisan('isp:winback', ['tenant' => $t->id, '--apply' => true, '--per-day' => 1])->assertSuccessful();

        $leads = Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->get()->keyBy('customer_phone');
        $this->assertEqualsCanonicalizing(['211900000001', '211900000002', '211900000003', '211900000007'], $leads->keys()->all(),
            'scam-only, staff-alert, muted and "stop" numbers are left out');
        $mary = $leads['211900000001'];
        $this->assertSame(['winback', 'winback', 'Starlink', 'Mary Akech'], [$mary->source, $mary->tag, $mary->interest, $mary->customer_name]);
        $this->assertNotNull($mary->owner_user_id, 'given to a salesperson');
        $this->assertSame('assigned', $mary->status);
        $owner = User::find($mary->owner_user_id);
        $this->assertStringContainsString("Hello Mary, this is {$owner->name} from {$t->name}.", (string) $mary->notes);
        $this->assertStringContainsString('WE NEVER ANSWERED', (string) $mary->notes);
        $this->assertSame((int) $mary->owner_user_id, (int) CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211900000001')->value('owner_user_id'), 'the customer now belongs to that salesperson');
        $this->assertStringContainsString('account manager', (string) $leads['211900000007']->notes, 'an existing customer hears from their account manager');
        foreach ($leads as $l) {
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d|USD\s?\d/', (string) $l->notes, 'no amount in a first message');
        }

        // Spread: at most one due per salesperson per day; the best lead of each is due today.
        foreach ($leads->groupBy('owner_user_id') as $list) {
            $this->assertSame($list->count(), $list->pluck('next_followup_at')->map(fn ($d) => $d->toDateString())->unique()->count());
        }

        // One summary per salesperson; nothing sent to any customer.
        Bus::assertDispatchedTimes(NotifyOwner::class, $leads->pluck('owner_user_id')->unique()->count());
        Bus::assertNotDispatched(NotifyOwner::class, fn ($j) => str_starts_with((string) $j->to, '2119000'));

        $this->artisan('isp:winback', ['tenant' => $t->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(4, Lead::withoutGlobalScopes()->where('tenant_id', $t->id)->count(), 're-running adds nothing');
    }

    public function test_only_an_internet_provider(): void
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'grocery']]);
        $this->artisan('isp:winback', ['tenant' => $t->id])->assertFailed();
    }
}
