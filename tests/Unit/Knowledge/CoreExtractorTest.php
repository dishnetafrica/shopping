<?php
namespace Tests\Unit\Knowledge;

use App\Apps\Core\CoreExtractor;
use PHPUnit\Framework\TestCase;

class CoreExtractorTest extends TestCase
{
    private CoreExtractor $e;
    protected function setUp(): void { $this->e = new CoreExtractor(); }

    public function test_price_emits_action_and_fact_with_entity_confidence(): void
    {
        $r = $this->e->extract('Tea 5k');
        $this->assertCount(1, $r->actions);
        $this->assertSame('set_price', $r->actions[0]->actionType);
        $this->assertSame('Tea', $r->actions[0]->target);
        $this->assertSame(5000, $r->actions[0]->params['price']);
        $this->assertNotEmpty($r->actions[0]->entities);          // per-entity confidence present
        $this->assertCount(1, $r->facts);                          // audit fact
        $this->assertSame('Price', $r->facts[0]->factType);
    }

    public function test_durable_policy_and_facility_are_facts(): void
    {
        $r = $this->e->extract('Free delivery above 50,000. Parking available.');
        $keys = array_map(fn ($f) => $f->key, $r->facts);
        $this->assertContains('delivery:free_threshold', $keys);
        $this->assertContains('parking', $keys);
        $val = array_values(array_filter($r->facts, fn ($f) => $f->key === 'delivery:free_threshold'))[0]->value;
        $this->assertSame(50000, $val['threshold']);
    }

    public function test_today_scoped_changes_are_gated_actions(): void
    {
        $r = $this->e->extract('Cash only today');
        $this->assertSame('set_operational', $r->actions[0]->actionType);
        $this->assertSame('today', $r->actions[0]->params['scope']);

        $r2 = $this->e->extract('Closed tomorrow');
        $this->assertSame('set_operational', $r2->actions[0]->actionType);
        $this->assertSame('dated', $r2->actions[0]->params['scope']);
    }
}
