<?php
namespace Tests\Unit\Orders;

use App\Services\Bot\Pricing\QuantityNormalizer as Q;
use PHPUnit\Framework\TestCase;

class QuantityNormalizerTest extends TestCase
{
    public function test_weight_units_become_grams_with_single_qty(): void
    {
        $this->assertSame(['qty' => 1, 'weight_grams' => 250], Q::normalize(250, 'g'));
        $this->assertSame(['qty' => 1, 'weight_grams' => 250], Q::normalize(250, 'gms'));
        $this->assertSame(['qty' => 1, 'weight_grams' => 1000], Q::normalize(1, 'kg'));
        $this->assertSame(['qty' => 1, 'weight_grams' => 1500], Q::normalize(1.5, 'kg'));
    }

    public function test_pieces_never_receive_a_weight(): void   // protects mixed orders (the samosa case)
    {
        $this->assertSame(['qty' => 4, 'weight_grams' => null], Q::normalize(4, 'pcs'));
        $this->assertSame(['qty' => 4, 'weight_grams' => null], Q::normalize(4, 'pieces'));
        $this->assertSame(['qty' => 12, 'weight_grams' => null], Q::normalize(1, 'dozen'));
        $this->assertSame(['qty' => 2, 'weight_grams' => null], Q::normalize(2, 'pack'));
    }

    public function test_unknown_or_empty_unit_is_treated_as_count(): void
    {
        $this->assertSame(['qty' => 3, 'weight_grams' => null], Q::normalize(3, ''));
        $this->assertSame(['qty' => 1, 'weight_grams' => null], Q::normalize(1, 'box'));
    }
}
