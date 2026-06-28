<?php
namespace Tests\Feature\Orders;

use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\DraftMerge;
use App\Apps\Orders\Extract\EntityExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Data-driven regression corpus grown from production. Reads tests/Fixtures/order_replays.json
 * (cases promoted from reviewed shadow disagreements) and replays each through the Draft Order
 * extractor + merge, asserting the human-confirmed expected draft. Starts empty and grows from the
 * review loop — every production failure becomes a reproducible test with no hand-written code.
 */
class ProductionReplayTest extends TestCase
{
    public function test_production_replay_corpus(): void
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/order_replays.json';
        $corpus = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        if ($corpus === []) { $this->markTestSkipped('replay corpus empty — fills as shadow diffs are promoted'); }

        $ex = new EntityExtractor(); $merge = new DraftMerge();
        foreach ($corpus as $case) {
            $draft = new DraftOrder(phone: $case['expected']['phone'] ?? null);
            foreach (($case['messages'] ?? []) as $i => $msg) {
                [$draft] = $merge->apply($draft, $ex->extract((string) $msg), 'replay', $i + 1);
            }
            $exp = $case['expected'] ?? [];
            if (isset($exp['products'])) {
                $this->assertCount(count($exp['products']), $draft->lines, "case {$case['id']}: product count");
                foreach ($exp['products'] as $idx => $p) {
                    if (isset($p['weight_grams'])) {
                        $this->assertSame($p['weight_grams'], $draft->lines[$idx]->weightGrams ?? null, "case {$case['id']}: weight");
                    }
                }
            }
            foreach (['delivery_area' => 'deliveryArea', 'payment_method' => 'paymentMethod', 'delivery_time' => 'deliveryTime'] as $k => $prop) {
                if (isset($exp[$k])) $this->assertSame($exp[$k], $draft->$prop, "case {$case['id']}: {$k}");
            }
        }
    }
}
