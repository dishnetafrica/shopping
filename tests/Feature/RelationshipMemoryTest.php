<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Relationship\ProfileContext;
use App\Services\Relationship\RelationshipExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V4.0 Memory Loop: extraction merges, the stage machine holds, data floors win, the brain reads. */
class RelationshipMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function tenant(bool $flag = true): Tenant
    {
        $this->t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($this->t->id);
        if ($flag) $this->t->putSetting('relationship_memory', true);
        return $this->t;
    }

    private function extractorReturning(?array $fixture): RelationshipExtractor
    {
        return new class($fixture) extends RelationshipExtractor {
            public function __construct(private ?array $fx) {}
            protected function callAi(string $s, string $tr): ?array { return $this->fx; }
        };
    }

    private function settledConvo(Tenant $t, string $phone): Conversation
    {
        $c = Conversation::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'i1',
            'state' => [], 'cart' => [], 'last_message_at' => now()->subHour(),
        ]);
        Message::withoutGlobalScope('tenant')->create([
            'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => 'i1',
            'direction' => 'in', 'sender' => 'customer', 'body' => 'I need 20 cartons of foil, can you do 60 per carton? Also send your pricelist.',
        ]);
        return $c;
    }

    public function test_extraction_merges_facts_validates_stage_and_stores_guardian_review(): void
    {
        $t = $this->tenant();
        $c = $this->settledConvo($t, '211900000001');

        $x = $this->extractorReturning([
            'customer' => [
                'facts' => ['usual_products' => 'aluminium foil cartons', 'current_objection' => 'wants 60 per carton',
                    'wanted_unavailable' => ['foil 45cm gold']],
                'stage_proposal' => 'NEGOTIATING',
                'sentiment' => 'positive',
                'summary' => "Wholesale foil buyer.\nNegotiating carton price.",
            ],
            'review' => [
                'all_questions_answered' => false,
                'missed_items' => [['type' => 'unsent_quote', 'evidence' => 'send your pricelist', 'suggested_action' => 'Send the price list PDF']],
                'promises' => [], 'ended' => 'abandoned', 'quality' => 'needs_attention',
                'quality_reasons' => ['Price list was requested but never sent'],
            ],
        ]);
        $this->assertTrue($x->process($t, $c));

        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211900000001')->first();
        $this->assertSame('NEGOTIATING', $p->stage, 'NEW → NEGOTIATING is an allowed AI transition');
        $this->assertSame('aluminium foil cartons', $p->facts['usual_products']);
        $this->assertSame(['foil 45cm gold'], $p->facts['wanted_unavailable']);
        $this->assertStringContainsString('Negotiating', $p->ai_summary);

        $c->refresh();
        $this->assertSame('needs_attention', $c->quality);
        $this->assertSame('unsent_quote', $c->review['missed_items'][0]['type'], 'Guardian findings persist from day one');
        $this->assertNotNull($c->summarized_at, 'idempotency stamp set');
    }

    public function test_ai_cannot_set_data_owned_stages(): void
    {
        $t = $this->tenant();
        $c = $this->settledConvo($t, '211900000002');
        $x = $this->extractorReturning([
            'customer' => ['facts' => [], 'stage_proposal' => 'VIP', 'sentiment' => 'neutral', 'summary' => 's'],
            'review' => [],
        ]);
        $x->process($t, $c);

        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211900000002')->first();
        $this->assertSame('NEW', $p->stage, 'VIP/PURCHASED/LOST etc. belong to data and humans, never the AI');
    }

    public function test_stage_floor_and_health_come_from_order_math(): void
    {
        $t = $this->tenant();
        CustomerProfile::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'phone' => '211900000003', 'stage' => 'INTERESTED']);
        for ($i = 0; $i < 3; $i++) {
            Order::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => '211900000003',
                'customer_name' => 'Azam', 'items' => [], 'total' => 500, 'status' => 'completed',
                'created_at' => now()->subDays(5 + $i)]);
        }

        $this->artisan('relationship:stages', ['--tenant' => $t->id])->assertExitCode(0);

        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', '211900000003')->first();
        $this->assertContains($p->stage, ['REPEAT', 'VIP'], '3 orders floors the stage to at least REPEAT');
        $this->assertGreaterThan(50, (int) $p->health, 'recent triple buyer must be healthy — and health is pure math');
    }

    public function test_profile_block_feeds_the_brain_and_respects_the_flag(): void
    {
        $t = $this->tenant();
        CustomerProfile::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'phone' => '211900000004',
            'name' => 'Azam Supermarket', 'stage' => 'REPEAT',
            'facts' => ['usual_products' => 'foil, cups'], 'ai_summary' => 'Reliable weekly buyer.']);

        $block = ProfileContext::block($t, '211900000004');
        $this->assertStringContainsString('Azam Supermarket', $block);
        $this->assertStringContainsString('REPEAT', $block);
        $this->assertStringContainsString('Reliable weekly buyer.', $block);

        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('ProfileContext::block($tenant, $from)', $src,
            'the brain must actually read the memory — THE V4.0 loop closure');

        $t->putSetting('relationship_memory', false);
        $this->assertSame('', ProfileContext::block($t, '211900000004'), 'flag OFF = zero context, zero risk');
    }
}
