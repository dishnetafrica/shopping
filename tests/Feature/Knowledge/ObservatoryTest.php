<?php
namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeMetric;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeEngine;
use App\Services\Knowledge\Observatory\BehaviorObservatory;
use App\Services\Knowledge\Observatory\ObservingEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Behavior Observatory records the full extraction outcome for every owner message. */
class ObservatoryTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        return $tenant;
    }

    public function test_buckets_success_partial_and_unknown(): void
    {
        $t = $this->boot();
        $obs = app(ObservingEngine::class);

        $obs->ingest($t->id, 'Tea 5000');          // price → action → success
        $obs->ingest($t->id, 'Parking available'); // facility fact, no action → partial
        $obs->ingest($t->id, 'thanks bhai');       // NOTE → no capability → unknown

        $this->assertSame('success', KnowledgeMetric::where('tenant_id', $t->id)->where('intent', 'price')->value('result'));
        $this->assertSame('partial', KnowledgeMetric::where('tenant_id', $t->id)->where('intent', 'facility')->value('result'));
        $this->assertSame('unknown', KnowledgeMetric::where('tenant_id', $t->id)->where('intent', 'note')->value('result'));
        $this->assertSame(3, KnowledgeMetric::where('tenant_id', $t->id)->count());
    }

    public function test_records_processing_time_language_and_sample(): void
    {
        $t = $this->boot();
        app(ObservingEngine::class)->ingest($t->id, 'Tea 5000');
        $m = KnowledgeMetric::where('tenant_id', $t->id)->first();
        $this->assertNotNull($m->processing_ms);
        $this->assertSame('en', $m->language);
        $this->assertSame('Tea 5000', $m->sample);
    }

    public function test_confirmation_is_recorded(): void
    {
        $t = $this->boot();
        $event = app(ObservingEngine::class)->ingest($t->id, 'Tea 5000');
        app(BehaviorObservatory::class)->recordConfirmation($event->id, true);
        $this->assertTrue((bool) KnowledgeMetric::where('event_id', $event->id)->value('confirmed'));
    }

    public function test_observation_failure_never_breaks_ingest(): void
    {
        $t = $this->boot();
        $throwing = new class extends BehaviorObservatory {
            public function record(\App\Models\KnowledgeEvent $e, int $ms): \App\Models\KnowledgeMetric { throw new \RuntimeException('metrics down'); }
        };
        $engine = new ObservingEngine(app(KnowledgeEngine::class), $throwing);
        $event = $engine->ingest($t->id, 'Tea 5000');     // must not throw
        $this->assertNotNull($event->id);
    }
}
