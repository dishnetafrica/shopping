<?php
namespace Tests\Feature\Orders;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Bot\OrderCalculator;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for production order ORD-86 (Pal's Snacks, 28 Jun 2026): a customer ordered
 * "Fafda 250gms / Sev khamni 250gms / Khaman 250gms" but the AI order path placed each at the
 * default 1 Kg variant (Fafda 1 Kg = 35,000 etc.). Root cause: the <<ORDER>> protocol carried no
 * weight, so OrderCalculator priced the base variant. This locks the fix: a weighted item must be
 * priced at the requested grams via WeightPricer, never the default variant.
 */
class OrderCalculatorWeightTest extends TestCase
{
    use RefreshDatabase;

    private function fafda(Tenant $t): Product
    {
        $p = Product::create([
            'tenant_id' => $t->id, 'name' => 'Fafda', 'base_price' => 35000, 'price' => 35000,
            'active' => true, 'sold_by_weight' => true,
            'reference_price' => 35000, 'reference_weight_grams' => 1000,
        ]);
        $p->weightVariants()->create(['weight_grams' => 250, 'price' => 12500]);  // merchant-set 250g price
        return $p;
    }

    public function test_weighted_item_is_priced_at_requested_grams_not_default_variant(): void
    {
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        $this->fafda($t);

        $q = app(OrderCalculator::class)->quote($t, [['query' => 'Fafda', 'qty' => 1, 'weight_grams' => 250]]);
        $line = $q['lines'][0];

        $this->assertSame(12500.0, (float) $line['price'], 'must use the 250g price, not the 1 Kg base');
        $this->assertStringContainsString('250', $line['name']);
        $this->assertSame(12500.0, (float) $q['total']);
    }

    public function test_no_grams_still_uses_base_variant(): void
    {
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        $this->fafda($t);

        $q = app(OrderCalculator::class)->quote($t, [['query' => 'Fafda', 'qty' => 1]]);
        $this->assertSame(35000.0, (float) $q['lines'][0]['price']);
    }
}
