<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Extract\AddressRecognizer;
use PHPUnit\Framework\TestCase;

class AddressRecognizerTest extends TestCase
{
    public function test_recognises_condo_landmark_and_maps(): void
    {
        $this->assertNotNull(AddressRecognizer::detect('503 Silver Condos')['address']);
        $this->assertNotNull(AddressRecognizer::detect('near Shoprite')['landmark']);
        $this->assertNotNull(AddressRecognizer::detect('here https://maps.app.goo.gl/abc123')['maps']);
        $this->assertNotNull(AddressRecognizer::detect('0.3476, 32.5825')['maps']);
        $this->assertNull(AddressRecognizer::detect('250g vanela')['address']);   // a product is not an address
    }
}
