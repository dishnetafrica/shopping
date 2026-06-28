<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\Dto\EntityBundle;
use App\Apps\Orders\DraftMerge;
use PHPUnit\Framework\TestCase;

class DraftMergeTest extends TestCase
{
    public function test_messages_accumulate_into_one_draft_with_weight(): void
    {
        $merge = new DraftMerge();
        $draft = new DraftOrder();

        [$draft] = $merge->apply($draft, new EntityBundle(
            products: [['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1, 'confidence' => 0.9]],
            confidence: ['lines' => 0.9],
        ));
        [$draft] = $merge->apply($draft, new EntityBundle(deliveryArea: 'Ntinda', confidence: ['delivery_area' => 0.95]));
        [$draft, $events] = $merge->apply($draft, new EntityBundle(paymentMethod: 'Mobile Money', deliveryTime: '08:00', confidence: ['payment_method' => 0.96, 'delivery_time' => 0.97]));

        $this->assertCount(1, $draft->lines);
        $this->assertSame(250, $draft->lines[0]->weightGrams);   // weight is first-class — no 1kg default
        $this->assertSame('Ntinda', $draft->deliveryArea);
        $this->assertSame('Mobile Money', $draft->paymentMethod);
        $this->assertSame('phone', $draft->waitingFor);          // area+time+payment known -> only phone missing
        $this->assertNotEmpty($events);                          // timeline events emitted
    }

    public function test_same_product_and_weight_replaces_not_duplicates(): void
    {
        $merge = new DraftMerge();
        $draft = new DraftOrder();
        $bundle = new EntityBundle(products: [['name' => 'Vanela', 'weight_grams' => 250, 'qty' => 1, 'confidence' => 0.9]]);
        [$draft] = $merge->apply($draft, $bundle);
        [$draft] = $merge->apply($draft, $bundle);
        $this->assertCount(1, $draft->lines);
    }
}
