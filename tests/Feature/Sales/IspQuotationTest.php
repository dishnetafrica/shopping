<?php

namespace Tests\Feature\Sales;

use App\Capability\Process\ProductListCapability;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\QuotationService;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\QuotationWriteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Internet-provider catalogue and quotation (DishNet South Sudan plan, Phase 2). Real database and
 * the real quotation path (price calculator → PDF → saved quotation). Product names and prices here
 * are test values, not DishNet's.
 */
class IspQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp'): Tenant
    {
        $t = Tenant::factory()->create(['name' => 'DishNet Africa Limited', 'settings' => [
            'vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'quote_validity_days' => 7,
            'address' => "Plot 1, Test Road\nJuba, South Sudan",
        ]]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function catalogue(Tenant $t): void
    {
        $w = app(ProductWriteService::class);
        $w->create($t, ['name' => 'Test Kit', 'price' => 100, 'stock' => 5, 'billing' => 'one_off', 'service_line' => 'starlink']);
        $w->create($t, ['name' => 'Test Plan', 'price' => 50, 'stock' => 5, 'billing' => 'monthly', 'service_line' => 'starlink', 'min_term_months' => 6]);
        $w->create($t, ['name' => 'Test Installation', 'price' => 30, 'stock' => 5]);
    }

    private function ispHtml(Tenant $t, array $quote, array $totals): string
    {
        $m = new \ReflectionMethod(QuotationService::class, 'ispDoc');
        $m->setAccessible(true);

        return $m->invoke(app(QuotationService::class), $t, 'QUO-TEST-1', '211911000001', 'Test Customer', $quote, $totals, 0.0, 0.0)['html'];
    }

    public function test_products_carry_one_time_or_monthly_and_the_service_and_only_valid_values_are_kept(): void
    {
        $t = $this->business();
        $this->catalogue($t);
        app(ProductWriteService::class)->create($t, ['name' => 'Odd', 'price' => 1, 'billing' => 'yearly', 'service_line' => 'satellite-tv']);

        $p = fn ($n) => Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $n)->sole();
        $this->assertSame(['monthly', 'starlink', 6], [$p('Test Plan')->billing, $p('Test Plan')->service_line, (int) $p('Test Plan')->min_term_months]);
        $this->assertNull($p('Test Installation')->billing, 'not set = a one-time charge, as products have always been');
        $this->assertSame([null, null], [$p('Odd')->billing, $p('Odd')->service_line], 'unknown values are not stored');

        app(ProductWriteService::class)->update($t, $p('Test Plan'), ['price' => 55]);
        $this->assertSame('monthly', $p('Test Plan')->billing, 'an edit that does not send the field leaves it alone');
    }

    public function test_the_product_form_shows_the_fields_only_for_an_internet_provider(): void
    {
        $isp = $this->business();
        $shop = $this->business('grocery');

        $this->assertArrayHasKey('billing', ProductListCapability::formSchema($isp->id));
        $this->assertArrayHasKey('service_line', ProductListCapability::formSchema($isp->id));
        $this->assertArrayNotHasKey('billing', ProductListCapability::formSchema($shop->id));
    }

    public function test_a_quotation_keeps_one_time_and_monthly_charges_apart_and_records_who_prepared_it(): void
    {
        $t = $this->business();
        $this->catalogue($t);
        $akol = User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'akol@t.test', 'password' => 'secret']);
        $this->actingAs($akol);

        $r = app(QuotationWriteService::class)->createFor($t, '211911000001', 'Test Customer',
            [['name' => 'Test Kit', 'qty' => 1], ['name' => 'Test Plan', 'qty' => 1], ['name' => 'Test Installation', 'qty' => 1]], null, false);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));

        $q = Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->latest('id')->firstOrFail();
        $this->assertEquals(130.0, (float) $q->total, 'the stored total is what is paid once: kit + installation');
        $this->assertEquals(50.0, (float) ($q->meta['monthly_total'] ?? -1), 'the monthly plan is kept beside it, never added in');
        $this->assertSame(['starlink'], $q->meta['service_lines'] ?? null);
        $this->assertSame((int) $akol->id, (int) $q->sales_exec_id);
        $items = QuotationItem::withoutGlobalScopes()->where('quotation_id', $q->id)->pluck('billing', 'name')->all();
        $this->assertSame(['Test Installation' => 'one_off', 'Test Kit' => 'one_off', 'Test Plan' => 'monthly'], collect($items)->sortKeys()->all());
        $this->assertSame(now()->addDays(7)->toDateString(), $q->valid_until->toDateString(), 'the business\'s own validity period');
    }

    public function test_the_page_is_the_dishnet_layout_and_never_prints_the_platforms_default_terms(): void
    {
        $t = $this->business();
        $this->catalogue($t);
        $akol = User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'akol2@t.test', 'password' => 'secret']);
        $this->actingAs($akol);
        $quote = app(\App\Services\Bot\OrderCalculator::class)->quote($t, [['query' => 'Test Kit', 'qty' => 1], ['query' => 'Test Plan', 'qty' => 1]]);
        $m = new \ReflectionMethod(QuotationService::class, 'priced');
        $m->setAccessible(true);
        $totals = $m->invoke(app(QuotationService::class), $quote, 0.0, 0.0, false);

        $html = $this->ispHtml($t, $quote, $totals);

        foreach (['One-time charges', 'Monthly charges', '>STARLINK<', 'Akol Deng', 'Sales: +211922000001', 'Juba, South Sudan'] as $want) {
            $this->assertStringContainsString($want, $html);
        }
        foreach (['Kampala', '3% PER MONTH', 'Ex-stock', 'Yash'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $html, "must not print: $never");
        }
        $this->assertStringNotContainsString('agree to the above terms', $html, 'no terms are published yet, so none are printed');
    }

    public function test_without_a_pdf_the_saved_quotation_still_keeps_monthly_charges_apart(): void
    {
        $t = $this->business();
        $this->catalogue($t);
        $quote = app(\App\Services\Bot\OrderCalculator::class)->quote($t, [['query' => 'Test Kit', 'qty' => 1], ['query' => 'Test Plan', 'qty' => 1]]);

        // As the creation routes do when the PDF engine is missing: a plain total of every line.
        $q = app(QuotationService::class)->persist($t, '211911000001', 'Test Customer', $quote,
            ['no' => 'QUO-NOPDF', 'total' => (float) $quote['total'], 'path' => ''], 'v3');

        $this->assertEquals(100.0, (float) $q->total, 'the plan is not added into the one-time total');
        $this->assertEquals(50.0, (float) $q->meta['monthly_total']);
    }

    public function test_a_grocery_quotation_is_exactly_as_before(): void
    {
        $t = $this->business('grocery');
        app(ProductWriteService::class)->create($t, ['name' => 'Sugar 1kg', 'price' => 5, 'stock' => 10]);

        $r = app(QuotationWriteService::class)->createFor($t, '256711000001', 'Shop Customer', [['name' => 'Sugar 1kg', 'qty' => 2]], null, false);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));

        $q = Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->latest('id')->firstOrFail();
        $this->assertEquals(10.0, (float) $q->total);
        $this->assertNull($q->meta, 'no monthly figures on a shop quotation');
        $this->assertNull(QuotationItem::withoutGlobalScopes()->where('quotation_id', $q->id)->value('billing'));
    }
}
