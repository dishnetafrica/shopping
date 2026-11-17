<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Services\Catalog\ProductWriteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An Internet-provider business (DishNet South Sudan) sells a SERVICE through a named salesperson, so its
 * customer-facing AI is not given the shop rules (wholesale cartons, per-piece prices, delivery towns,
 * solar advice, price-list PDFs, bot-made orders and quotations). Every other business keeps all of them.
 * Names and prices are test values.
 */
class IspShopRulesTest extends TestCase
{
    use RefreshDatabase;

    /** Text that only the shop rules contain. */
    private const SHOP_RULES = [
        'MINIMUM ORDER (WHOLESALE)',
        'QUOTE PER PIECE FIRST',
        'BEST VALUE FIRST',
        'SOLAR / POWER SYSTEM ADVISOR',
        'DELIVERY & PRICING POLICY',
        'LEARN THEIR ROUTE',
        'BROWSE BY CATEGORY',
        'AD LEADS',
        '<<SEND {"doc":"price_list"}>>',
        'PLACING AN ORDER',
        '<<ORDER',
        'SENDING A QUOTATION (PDF)',
        '<<QUOTE',
        'LARGE / WHOLESALE ORDERS',
        'CATALOGUE RANGE',
        'a detailed sample with exact names',
        'WE ARE THE SHOP',
        'SPECIAL ORDER',
        'cups → lids',
    ];

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical): Tenant
    {
        $t = Tenant::factory()->create(['name' => 'Test Net', 'settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function prompt(Tenant $t): string
    {
        $m = new \ReflectionMethod(AiBrain::class, 'systemPrompt');
        $m->setAccessible(true);

        return (string) $m->invoke(app(AiBrain::class), $t);
    }

    public function test_an_internet_provider_gets_no_shop_rules_and_says_monthly_or_one_time(): void
    {
        $t = $this->business('isp');
        app(ProductWriteService::class)->create($t, ['name' => 'Test Plan 20 Mbps', 'price' => 50, 'billing' => 'monthly', 'service_line' => 'fiber']);
        app(ProductWriteService::class)->create($t, ['name' => 'Test Kit Mini', 'price' => 400, 'billing' => 'one_off', 'service_line' => 'starlink']);
        $p = $this->prompt($t);

        foreach (self::SHOP_RULES as $rule) {
            $this->assertStringNotContainsString($rule, $p, "shop rule reached an internet provider: {$rule}");
        }
        $this->assertStringContainsString('an internet provider (Starlink and fibre)', $p, 'its own persona when none is set');
        $this->assertStringContainsString('INTERNET SALES', $p, 'the internet-provider rules are still there');
        $this->assertStringContainsString('salesperson prepares the quotation', $p);
        $this->assertStringContainsString('ADVERT LEADS', $p, 'advert clicks get the internet-provider welcome');
        $this->assertStringContainsString('This question is REQUIRED even after a direct price or availability answer', $p, 'the ANSWER FIRST rule does not drop the qualifier');
        $this->assertStringContainsString('Never mention the [NEW AD LEAD] tag', $p, 'the internal tag never reaches a customer');
        $this->assertStringContainsString('Detect the customer\'s language', $p, 'the general rules stay');
        $this->assertStringContainsString('OUR PRICES ARE FIXED', $p);

        $lines = collect(explode("\n", $p));
        $plan = $lines->first(fn ($l) => str_starts_with(ltrim($l), "\u{2022} Test Plan 20 Mbps"));
        $kit = $lines->first(fn ($l) => str_starts_with(ltrim($l), "\u{2022} Test Kit Mini"));
        $this->assertNotNull($plan);
        $this->assertNotNull($kit);
        $this->assertStringContainsString('charged monthly', $plan);
        $this->assertStringContainsString('one-time charge', $kit);
    }

    public function test_a_persona_the_owner_wrote_is_kept(): void
    {
        $t = $this->business('isp');
        $t->putSetting('ai_persona', 'OWNER PERSONA TEXT.');
        $p = $this->prompt($t->fresh());

        $this->assertStringStartsWith('OWNER PERSONA TEXT.', $p);
        $this->assertStringNotContainsString('an internet provider (Starlink and fibre)', $p);
    }

    public function test_every_other_business_keeps_all_the_shop_rules(): void
    {
        $t = $this->business('grocery');
        app(ProductWriteService::class)->create($t, ['name' => 'Test Rice 5kg', 'price' => 12]);
        $p = $this->prompt($t);

        foreach (self::SHOP_RULES as $rule) {
            $this->assertStringContainsString($rule, $p, "shop rule missing for a grocery: {$rule}");
        }
        $this->assertStringContainsString('You are a helpful sales & support assistant for this shop.', $p);
        $this->assertStringNotContainsString('INTERNET SALES', $p);
        $this->assertStringNotContainsString('charged monthly', $p);
        $this->assertStringNotContainsString('one-time charge', $p);
    }

    /**
     * Real chat, 17 Nov 2026: with stock tracking on (the default) and the plan at stock 0, the AI told a
     * customer "the Starlink Residential Plan is currently out of stock". A monthly plan is a service.
     */
    public function test_a_monthly_plan_is_never_out_of_stock_even_when_stock_is_tracked(): void
    {
        $t = $this->business('isp');   // track_stock not set → tracked (the default)
        $plan = app(ProductWriteService::class)->create($t, ['name' => 'Test Residential Plan', 'price' => 50, 'stock' => 0, 'billing' => 'monthly', 'service_line' => 'starlink']);
        $kit = app(ProductWriteService::class)->create($t, ['name' => 'Test Kit Counted', 'price' => 400, 'stock' => 0, 'billing' => 'one_off', 'service_line' => 'starlink']);
        app(\App\Services\Inventory\InventoryState::class)->recordCount($plan, 0);
        app(\App\Services\Inventory\InventoryState::class)->recordCount($kit, 0);

        $lines = collect(explode("\n", $this->prompt($t)));
        $planLine = $lines->first(fn ($l) => str_starts_with(ltrim($l), "\u{2022} Test Residential Plan"));
        $kitLine = $lines->first(fn ($l) => str_starts_with(ltrim($l), "\u{2022} Test Kit Counted"));
        $this->assertNotNull($planLine);
        $this->assertStringContainsString('never out of stock', $planLine);
        $this->assertStringNotContainsString('OUT OF STOCK', $planLine);
        $this->assertStringNotContainsString('stock unknown', $planLine);
        $this->assertStringContainsString('OUT OF STOCK', (string) $kitLine, 'hardware keeps its honest stock tag while stock is tracked');

        $m = new \ReflectionMethod(AiBrain::class, 'relevantProductsBlock');
        $m->setAccessible(true);
        $block = (string) $m->invoke(app(AiBrain::class), $t, 'I need test residential plan and test kit');
        $this->assertStringContainsString('Test Residential Plan', $block);
        $this->assertDoesNotMatchRegularExpression('/Test Residential Plan[^\n]*\[out of stock\]/', $block, 'a plan is never tagged out of stock');
        $this->assertMatchesRegularExpression('/Test Kit Counted[^\n]*\[out of stock\]/', $block, 'hardware search tag unchanged');
    }
}
