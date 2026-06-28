<?php
namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\InventoryState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inventory Confidence over snapshots: the bot's certainty matches what we know, and decays with age. */
class InventoryStateTest extends TestCase
{
    use RefreshDatabase;

    private InventoryState $inv;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = app(InventoryState::class);
        $t = Tenant::factory()->create();
        app(TenantContext::class)->set($t->id);
        $this->tenantId = $t->id;
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge(['tenant_id' => $this->tenantId, 'name' => 'Paneer', 'price' => 5000, 'active' => true], $attrs));
    }

    private function age(Product $p, $observedAt): void
    {
        $snap = \App\Models\InventorySnapshot::where('product_id', $p->id)->where('location', 'default')->first();
        $snap->forceFill(['observed_at' => $observedAt])->save();
    }

    public function test_recorded_count_is_exact_and_states_the_number(): void
    {
        $p = $this->product();
        $this->inv->recordCount($p, 17);
        $s = $this->inv->state($p);
        $this->assertSame(InventoryState::EXACT, $s['state']);
        $this->assertSame('17 available', $s['line']);
        $this->assertSame('in stock: 17', $this->inv->botTag($p));
    }

    public function test_zero_count_is_out_of_stock(): void
    {
        $p = $this->product();
        $this->inv->recordCount($p, 0);
        $this->assertSame('OUT OF STOCK', $this->inv->botTag($p));
    }

    public function test_exact_decays_to_estimated_after_the_exact_window(): void
    {
        $p = $this->product();
        $this->inv->recordCount($p, 17);
        $this->age($p, now()->subHours(24));            // past EXACT_HOURS (12), within ESTIMATED_HOURS (72)
        $this->assertSame(InventoryState::ESTIMATED, $this->inv->state($p)['state']);
    }

    public function test_exact_decays_to_unknown_after_the_estimated_window(): void
    {
        $p = $this->product();
        $this->inv->recordCount($p, 17);
        $this->age($p, now()->subDays(4));              // past ESTIMATED_HOURS (72)
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($p)['state']);
    }

    public function test_never_counted_is_unknown(): void
    {
        $p = $this->product();
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($p)['state']);
        $this->assertStringContainsString('confirm', strtolower($this->inv->botTag($p)));
    }

    public function test_sale_decrements_known_stock_but_not_unknown(): void
    {
        $known = $this->product();
        $this->inv->recordCount($known, 10);
        $this->inv->decrementOnSale($known, 3);
        $this->assertSame(7, $this->inv->state($known)['qty']);

        $unknown = $this->product(['name' => 'Milk']);
        $this->inv->decrementOnSale($unknown, 3);
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($unknown)['state']);
    }

    public function test_locations_are_independent(): void
    {
        $p = $this->product();
        $this->inv->recordCount($p, 5, 'count', 'warehouse');
        $this->assertSame(5, $this->inv->state($p, 'warehouse')['qty']);
        $this->assertSame(InventoryState::UNKNOWN, $this->inv->state($p, 'default')['state']);
    }
}
