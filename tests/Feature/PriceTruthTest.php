<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Price-drift fix 2026-07-18: `price` is the ONE selling truth, everywhere, forever. */
class PriceTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function tenant(): Tenant
    {
        $this->t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($this->t->id);
        return $this->t;
    }

    public function test_saving_a_product_syncs_base_price_to_price(): void
    {
        $t = $this->tenant();
        $p = Product::create(['tenant_id' => $t->id, 'name' => 'Foil 8389', 'price' => 47.90, 'base_price' => 47.90, 'active' => true]);

        $p->price = 67.00;   // an edit path that "forgets" base_price
        $p->save();

        $this->assertSame(67.00, (float) $p->fresh()->base_price,
            'drift must be structurally impossible — whichever screen edits the price');
    }

    public function test_sync_command_repairs_existing_drift_and_honors_dry(): void
    {
        $t = $this->tenant();
        $p = new Product(['tenant_id' => $t->id, 'name' => 'Drifted', 'price' => 67.00, 'active' => true]);
        $p->save();
        // force historical drift beneath the hook
        \Illuminate\Support\Facades\DB::table('products')->where('id', $p->id)->update(['base_price' => 47.90]);

        $this->artisan('products:price-sync', ['tenant' => $t->id, '--dry' => true])->assertExitCode(0);
        $this->assertSame(47.90, (float) $p->fresh()->base_price, 'dry must not write');

        $this->artisan('products:price-sync', ['tenant' => $t->id])->assertExitCode(0);
        $this->assertSame(67.00, (float) $p->fresh()->base_price);
    }

    public function test_cogs_uses_the_real_cost_field(): void
    {
        $src = file_get_contents(app_path('Services/Ledger/OrderLedgerService.php'));
        $this->assertStringContainsString("pluck('cost_price', 'id')", $src,
            'COGS must come from cost_price — base_price mirrors the SELLING price');
        $sell = file_get_contents(app_path('Http/Controllers/Storefront/StorefrontController.php'));
        $this->assertStringContainsString('$p->price ?? $p->base_price', $sell,
            'storefront must sell at the panel price');
        $bot = file_get_contents(app_path('Services/Bot/OrderCalculator.php'));
        $this->assertStringContainsString('$p->price ?? $p->base_price', $bot,
            'the bot must charge the panel price');
    }
}
