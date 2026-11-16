<?php

namespace Tests\Feature\Sales;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Quotation;
use App\Models\SalesCoachSuggestion;
use App\Models\SalesPipelineEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductWriteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sales dashboard and quality monitoring (DishNet plan, Phases 7–8) through the real workspace API,
 * reading only records the other phases write. Values are test values.
 */
class SalesInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '211911000001';

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp'): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function user(Tenant $t, string $role, string $name): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => $name, 'role' => $role, 'phone' => '2119220' . random_int(10000, 99999), 'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    private function message(Tenant $t, string $dir, string $sender, string $body, ?User $by, \DateTimeInterface $at): void
    {
        $m = Message::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => self::PHONE, 'instance' => 'main', 'direction' => $dir, 'sender' => $sender,
            'body' => $body, 'sender_user_id' => $by?->id]);
        DB::table('messages')->where('id', $m->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /** Akol: one lead 2h ago, first reply 30 min later (with an unapproved price and coverage claim), won, one quotation, one coach suggestion. */
    private function scenario(): array
    {
        $t = $this->business();
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $bob = $this->user($t, 'sales', 'Bob Lado');
        app(ProductWriteService::class)->create($t, ['name' => 'Fibre Standard 40', 'price' => 75, 'billing' => 'monthly', 'service_line' => 'fiber']);

        $lead = Lead::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => self::PHONE, 'customer_name' => 'Test Customer', 'intent' => 'lead',
            'status' => 'won', 'stage' => 'won', 'owner_user_id' => $akol->id, 'source' => 'whatsapp']);
        DB::table('leads')->where('id', $lead->id)->update(['created_at' => now()->subHours(2)]);
        $this->message($t, 'in', 'customer', 'How much is fibre?', null, now()->subHours(2));
        $this->message($t, 'out', 'agent', 'Fibre Standard is $75 a month. A cheaper plan is $45. We cover Gudele Two.', $akol, now()->subMinutes(90));
        SalesPipelineEvent::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'lead_id' => $lead->id, 'from_stage' => 'negotiating', 'to_stage' => 'won', 'user_id' => $akol->id, 'created_at' => now()->subHour()]);
        Quotation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'quote_no' => 'QUO-T-1', 'customer_phone' => self::PHONE, 'currency' => 'USD', 'total' => 75, 'status' => 'accepted', 'source' => 'test', 'sales_exec_id' => $akol->id]);
        SalesCoachSuggestion::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'conversation_id' => 1, 'user_id' => $akol->id, 'customer_phone' => self::PHONE, 'suggestion' => 'x', 'guard_removed' => 1]);

        return [$t, $mgr, $akol, $bob];
    }

    public function test_the_dashboard_shows_each_salespersons_results_and_a_salesperson_only_their_own(): void
    {
        [$t, $mgr, $akol] = $this->scenario();

        $this->signIn($mgr);
        $j = $this->getJson('/api/v1/workspace/salesDashboard')->assertOk()->json();
        $rows = collect($j['table']['rows'])->keyBy(fn ($r) => $r['person']['value']);
        $this->assertSame(['Akol Deng', 'Bob Lado', 'Mary Manager'], $rows->keys()->sort()->values()->all());
        $a = $rows['Akol Deng'];
        $this->assertSame(['1', '30 min', '1', '1 won · 0 lost', '100%', '1'], [$a['new']['value'], $a['reply']['value'], $a['quotes']['value'], $a['won']['value'], $a['conv']['value'], $a['coach']['value']]);
        $this->assertSame('0', $rows['Bob Lado']['new']['value']);
        $this->assertContains('quality', collect($j['actions'])->pluck('key')->all());

        $this->signIn($akol);
        $mine = $this->getJson('/api/v1/workspace/salesDashboard')->assertOk()->json();
        $this->assertSame(['Akol Deng'], collect($mine['table']['rows'])->pluck('person.value')->all());
        $this->assertNotContains('quality', collect($mine['actions'])->pluck('key')->all());
    }

    public function test_quality_applies_the_bots_rules_to_people_and_counts_the_bots_corrections(): void
    {
        [$t, $mgr, $akol] = $this->scenario();
        DB::table('bot_events')->insert([['tenant_id' => $t->id, 'trace' => 'isp', 'phone' => self::PHONE, 'stage' => 'isp_price_stripped', 'detail' => 'x2', 'created_at' => now()]]);
        // A second customer whose message is the last one, 3 hours old.
        $c = Conversation::create(['tenant_id' => $t->id, 'customer_phone' => '211911000009', 'instance' => 'main', 'state' => [], 'cart' => []]);
        $c->forceFill(['last_inbound_at' => now()->subHours(3), 'last_message_at' => now()->subHours(3)])->save();

        $this->signIn($mgr);
        $j = $this->getJson('/api/v1/workspace/salesQuality')->assertOk()->json();
        $cards = collect($j['financials'])->keyBy('key');
        $this->assertSame(['2', '1 of 1', '2', '1'], [$cards['bot']['value'], $cards['coach']['value'], $cards['people']['value'], $cards['waiting']['value']]);
        $found = collect($j['table']['rows'])->map(fn ($r) => $r['what']['value'] . ': ' . $r['detail']['value'])->sort()->values()->all();
        $this->assertSame(['Coverage not published: "We cover Gudele Two."', 'Customer waiting: No reply for 3 h', 'Price not approved: "A cheaper plan is $45."'], $found);
        $this->assertSame('Akol Deng', collect($j['table']['rows'])->firstWhere('what.value', 'Price not approved')['who']['value']);

        $this->signIn($akol);
        $this->assertSame('For owners and managers', $this->getJson('/api/v1/workspace/salesQuality')->assertOk()->json('object.subtitle'));
    }

    public function test_another_business_type_has_neither_screen(): void
    {
        $t = $this->business('grocery');
        $this->signIn($this->user($t, 'owner', 'Shop Owner'));

        $this->assertSame('Available for Internet providers', $this->getJson('/api/v1/workspace/salesDashboard')->assertOk()->json('object.subtitle'));
        $this->assertSame('Available for Internet providers', $this->getJson('/api/v1/workspace/salesQuality')->assertOk()->json('object.subtitle'));
    }
}
