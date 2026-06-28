<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Extract\EntityExtractor;
use PHPUnit\Framework\TestCase;

class EntityExtractorTest extends TestCase
{
    public function test_extracts_all_entities_from_one_message(): void
    {
        $b = (new EntityExtractor())->extract('Send 250g Vanela, 250g Fafda, Ntinda, 8 AM, Mobile Money');

        $this->assertCount(2, $b->products);
        $this->assertSame(250, $b->products[0]['weight_grams']);
        $this->assertSame(250, $b->products[1]['weight_grams']);
        $this->assertStringContainsStringIgnoringCase('vanela', $b->products[0]['name']);
        $this->assertStringContainsStringIgnoringCase('fafda', $b->products[1]['name']);
        $this->assertSame('Ntinda', $b->deliveryArea);
        $this->assertSame('08:00', $b->deliveryTime);
        $this->assertSame('Mobile Money', $b->paymentMethod);
    }

    public function test_address_only_message_yields_no_products(): void
    {
        $b = (new EntityExtractor())->extract('503 silver condos');
        $this->assertNotNull($b->address);
        $this->assertSame([], $b->products);     // never reaches the product matcher
    }
}
