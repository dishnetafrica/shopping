<?php
namespace Tests\Unit\Orders\Resolve;

use App\Apps\Orders\Resolve\WeightResolver;
use PHPUnit\Framework\TestCase;

class WeightResolverTest extends TestCase
{
    private function product(bool $allowProrata = true): array
    {
        return [
            'sold_by_weight' => true, 'price' => null, 'reference_price' => 50000, 'reference_weight_grams' => 1000,
            'variants' => [250 => ['id' => 11, 'price' => 12500], 500 => ['id' => 12, 'price' => 25000], 1000 => ['id' => 13, 'price' => 50000]],
            'allow_prorata' => $allowProrata, 'min_grams' => 100, 'default_variant_id' => null,
        ];
    }

    public function test_exact_variant_wins(): void
    {
        $r = (new WeightResolver())->resolve($this->product(), 250);
        $this->assertTrue($r['ok']);
        $this->assertSame('variant', $r['source']);
        $this->assertSame(11, $r['variant_id']);
        $this->assertSame(12500.0, $r['unit_price']);
    }

    public function test_prorata_when_allowed(): void
    {
        $p = ['sold_by_weight' => true, 'reference_price' => 50000, 'reference_weight_grams' => 1000,
              'variants' => [], 'allow_prorata' => true, 'min_grams' => 100];
        $r = (new WeightResolver())->resolve($p, 750);
        $this->assertTrue($r['ok']);
        $this->assertSame('prorata', $r['source']);
        $this->assertSame(37500.0, $r['unit_price']);
    }

    public function test_no_silent_upsize_when_prorata_disallowed(): void
    {
        $p = ['sold_by_weight' => true, 'reference_price' => 50000, 'reference_weight_grams' => 1000,
              'variants' => [500 => ['id' => 12, 'price' => 25000], 1000 => ['id' => 13, 'price' => 50000]],
              'allow_prorata' => false, 'min_grams' => 100];
        $r = (new WeightResolver())->resolve($p, 750);     // 750 not a variant, prorata off
        $this->assertFalse($r['ok']);
        $this->assertSame('needs_weight_clarify', $r['reason']);
    }

    public function test_below_minimum_asks(): void
    {
        $this->assertSame('min', (new WeightResolver())->resolve($this->product(), 50)['reason']);
    }

    public function test_unit_product_uses_unit_price(): void
    {
        $r = (new WeightResolver())->resolve(['sold_by_weight' => false, 'price' => 3000, 'default_variant_id' => null], null);
        $this->assertTrue($r['ok']);
        $this->assertSame('unit', $r['source']);
        $this->assertSame(3000.0, $r["unit_price"]);
    }
}
