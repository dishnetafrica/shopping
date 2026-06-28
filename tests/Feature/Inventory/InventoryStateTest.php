<?php
namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\InventoryState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inventory Confidence primitive: the bot's certainty must match what we actually know. */
class InventoryStateTest extends TestCase
{
    use RefreshDatabase;

    private InventoryState $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = app(InventoryState::class);
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        $this->tenantId = $t->id;
    }
    private int $tenantId;

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge(['tenant_id' => $this->tenantId, 'name' => 'Paneer', 'price' => 5000, 'active' => true], $attrs));
    }

    public function test_recorded_count_is_exact_and_states_the_number(): void
    {
        $p = $this->inv->recordCount($this->product(), 17);
        $s = $this->inv->state($p);
        $this->assertSame(InventoryState::EXACT, $s['state']);
        $this->assertSame('17 available', $s['line']);
        $this->assertSame('in stock: 17', $this->inv->botTag($p));
    }

    public function test_zero_count_is_out_of_stock(): void
    {
        $p = $this->inv->recordCount($this->product(), 0);
        $this->assertSame('Out of stock', $this->inv->state($p)['line']);
        $this->assertSame('OUT OF STOCK', $this->inv->botTag($p));
    }

    public function test_stale_exact_count_degrades_to_estimated(): void
    {
        $p = $this->inv->recordCount($this->product(), 17);
        $p->forceFill(['stock_checked_at' => now()->subDays(2)])->save();   // went stale
        $this->assertSame(InventoryState::ESTIMATED, $this->inv->state($p)['state']);
    }

    public function test_unknown_makes_the_bot_promise_nothing(): void
    {
        $p = $this->product();   // never counted → unknown by default
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($p)['state']);
        $this->assertStringContainsString('confirm', strtolower($this->inv->botTag($p)));
    }

    public function test_sale_decrements_known_stock_but_not_unknown(): void
    {
        $known = $this->inv->recordCount($this->product(), 10);
        $this->inv->decrementOnSale($known, 3);
        $this->assertSame(7, (int) $known->refresh()->stock);

        $unknown = $this->product(['name' => 'Milk']);
        $this->inv->decrementOnSale($unknown, 3);
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($unknown->refresh())['state']);
    }
}
