<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeAction;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Models\Tenant;
use App\Services\Knowledge\BusinessMemory;
use App\Services\Knowledge\CapabilityRegistry;
use App\Services\Knowledge\Contracts\Classifier;
use App\Services\Knowledge\Dto\Fact;
use App\Services\Knowledge\KnowledgeEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Knowledge\TestCapability;
use Tests\TestCase;

/**
 * DB-backed proof of the engine's hard invariants. Runs in the app (RefreshDatabase); these
 * are the architecture guarantees the review asked for.
 */
class EnginePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(string $forcedIntent): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);

        $registry = new CapabilityRegistry();
        $registry->register(new TestCapability());

        $classifier = new class($forcedIntent) implements Classifier {
            public function __construct(private string $i) {}
            public function classify(string $text, array $profile = []): string { return $this->i; }
        };

        return [new KnowledgeEngine($registry, $classifier, new BusinessMemory()), $tenant];
    }

    public function test_event_captured_and_extracted(): void
    {
        [$engine, $tenant] = $this->engine(TestCapability::INTENT_GREETING);
        $event = $engine->ingest($tenant->id, 'Hello');
        $this->assertDatabaseHas('knowledge_events', ['id' => $event->id, 'status' => 'extracted', 'capability' => 'test_capability']);
        $this->assertSame(2, KnowledgeAction::where('event_id', $event->id)->count());   // multiple actions
        $this->assertSame(2, KnowledgeFact::where('event_id', $event->id)->count());      // multiple facts
    }

    public function test_unknown_capability_fails_gracefully_preserving_event(): void
    {
        [$engine, $tenant] = $this->engine('intent_no_capability_handles');
        $event = $engine->ingest($tenant->id, 'Some message');
        $this->assertDatabaseHas('knowledge_events', ['id' => $event->id]);   // event preserved
        $this->assertNull($event->capability);
        $this->assertSame(0, KnowledgeAction::where('event_id', $event->id)->count());
    }

    public function test_projector_failure_does_not_lose_event_or_action(): void
    {
        [$engine, $tenant] = $this->engine(TestCapability::INTENT_GREETING);
        $event = $engine->ingest($tenant->id, 'BOOM please');     // TestProjector throws on BOOM
        $action = KnowledgeAction::where('event_id', $event->id)->where('action_type', 'log_greeting')->first();
        $ok = $engine->applyAction($action);
        $this->assertFalse($ok);
        $this->assertDatabaseHas('knowledge_events', ['id' => $event->id]);                  // event intact
        $this->assertDatabaseHas('knowledge_actions', ['id' => $action->id, 'status' => 'rejected']); // action intact
    }

    public function test_facts_are_append_only_in_db(): void
    {
        [$engine, $tenant] = $this->engine(TestCapability::INTENT_GREETING);
        $mem = new BusinessMemory();
        $mem->record($tenant->id, new Fact('core', 'Price', 'tea:price', ['price' => 4000]));
        $mem->record($tenant->id, new Fact('core', 'Price', 'tea:price', ['price' => 5000]));
        $mem->record($tenant->id, new Fact('core', 'Price', 'tea:price', ['price' => 5000])); // no change → no new version

        $all = KnowledgeFact::where('tenant_id', $tenant->id)->where('key', 'tea:price')->get();
        $this->assertCount(2, $all);                                       // v1 retained, v2 added; unchanged skipped
        $this->assertSame(1, $all->where('is_current', true)->count());    // exactly one current
        $this->assertSame(5000, (int) $mem->current($tenant->id, 'core', 'Price', 'tea:price')->value_json['price']);
    }
}
