<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Dto\EntityBundle;
use App\Apps\Orders\Experiment\DiffClassifier;
use App\Apps\Orders\Experiment\DiffReason;
use PHPUnit\Framework\TestCase;

class DiffClassifierTest extends TestCase
{
    private DiffClassifier $c;
    protected function setUp(): void { $this->c = new DiffClassifier(); }

    public function test_agreement_is_match(): void
    {
        $r = $this->c->classify(
            [['name' => 'Khaman', 'weight_grams' => 250, 'quantity' => 1]],
            new EntityBundle(products: [['name' => 'Khaman', 'weight_grams' => 250, 'qty' => 1]]),
        );
        $this->assertFalse($r['differs']);
        $this->assertFalse($r['regressions']);
    }

    public function test_weight_disagreement_is_flagged(): void
    {
        $r = $this->c->classify(
            [['name' => 'Khaman', 'weight_grams' => 1000, 'quantity' => 1]],     // live: the 1kg bug
            new EntityBundle(products: [['name' => 'Khaman', 'weight_grams' => 250, 'qty' => 1]]),  // shadow: correct
        );
        $this->assertTrue($r['differs']);
        $this->assertSame(DiffReason::WEIGHT, $r['items'][0]['reason']);
    }

    public function test_shadow_missing_a_live_product_is_a_regression(): void
    {
        $r = $this->c->classify(
            [['name' => 'Fafda', 'weight_grams' => 250, 'quantity' => 1]],
            new EntityBundle(products: []),
        );
        $this->assertTrue($r['regressions']);
        $this->assertSame(DiffClassifier::LIVE_ONLY, $r['items'][0]['status']);
    }

    public function test_shadow_captured_address_is_improvement_not_regression(): void
    {
        $r = $this->c->classify(
            [['name' => 'Fafda', 'weight_grams' => 250, 'quantity' => 1]],
            new EntityBundle(products: [['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1]], deliveryArea: 'Ntinda'),
        );
        $this->assertTrue($r['differs']);            // a difference exists…
        $this->assertFalse($r['regressions']);       // …but it's the shadow capturing MORE (address)
        $addr = array_values(array_filter($r['items'], fn ($i) => $i['field'] === 'address'))[0];
        $this->assertSame(DiffClassifier::SHADOW_ONLY, $addr['status']);
    }
}
