<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Services\Bot\OrderCalculator;
use App\Services\Catalog\ProductWriteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** track_stock=false: a business that keeps no stock counts is never refused "out of stock"; others unchanged. */
class StockPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function quoteZeroStockItem(array $settings): array
    {
        $t = Tenant::factory()->create(['settings' => $settings + ['currency' => 'USD']]);
        app(TenantContext::class)->set($t->id);
        app(ProductWriteService::class)->create($t, ['name' => 'Test Kit Zero', 'price' => 400, 'stock' => 0]);

        return app(OrderCalculator::class)->quote($t, [['query' => 'Test Kit Zero', 'qty' => 1]], null, true)['lines'][0];
    }

    public function test_a_business_that_does_not_track_stock_is_quoted_at_stock_zero(): void
    {
        $line = $this->quoteZeroStockItem(['vertical' => 'isp', 'track_stock' => false]);
        $this->assertTrue((bool) $line['matched']);
        $this->assertEquals(400.0, (float) $line['price']);
        $this->assertEmpty($line['out_of_stock'] ?? false);
    }

    public function test_the_main_catalogue_never_says_out_of_stock_when_stock_is_not_tracked(): void
    {
        $t = \App\Models\Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'track_stock' => false]]);
        app(TenantContext::class)->set($t->id);
        $p = app(ProductWriteService::class)->create($t, ['name' => 'Test Kit Counted Zero', 'price' => 400, 'stock' => 0]);
        app(\App\Services\Inventory\InventoryState::class)->recordCount($p, 0);      // a fresh exact count of zero

        $m = new \ReflectionMethod(\App\Services\Bot\AiBrain::class, 'systemPrompt');
        $m->setAccessible(true);
        $prompt = (string) $m->invoke(app(\App\Services\Bot\AiBrain::class), $t);
        $line = collect(explode("\n", $prompt))->first(fn ($l) => str_contains($l, 'Test Kit Counted Zero'));

        $this->assertNotNull($line);
        $this->assertStringContainsString('stock not tracked', $line);
        $this->assertStringNotContainsString('OUT OF STOCK', $line);
    }

    public function test_every_other_business_keeps_refusing_a_zero_stock_item_on_the_bot_quote(): void
    {
        $line = $this->quoteZeroStockItem(['vertical' => 'grocery']);
        $this->assertTrue((bool) ($line['out_of_stock'] ?? false));
        $this->assertFalse((bool) $line['matched']);
    }
}
