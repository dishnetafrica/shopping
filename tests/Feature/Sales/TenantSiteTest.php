<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Services\Catalog\ProductWriteService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DishNet's own multi-page website served by ShopBot (site bundle resources/tenant-sites/dishnet). No page carries an
 * amount of its own: every price is a token filled from the SWITCHED-ON catalogue when the page is served; a product
 * ShopBot has no approved price for reads "ask our sales team"; SSP amounts and rates are gone. Coverage stays held
 * (→ the live published list). Prices below are test values.
 */
class TenantSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function dishnet(array $extra = []): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'site_bundle' => 'dishnet'] + $extra]);
        app(TenantContext::class)->set($t->id);
        $w = app(ProductWriteService::class);
        foreach ([['Starlink Mini (Gen 4)', 401, 'one_off', 'starlink'], ['Starlink Standard', 551, 'one_off', 'starlink'],
            ['Starlink Residential Plan', 51, 'monthly', 'starlink'], ['Starlink Roam Plan', 81, 'monthly', 'starlink'],
            ['Fiber Basic 20 Mbps', 52, 'monthly', 'fiber'], ['Fiber Standard 40 Mbps', 76, 'monthly', 'fiber'],
            ['Fiber Premium 60 Mbps', 101, 'monthly', 'fiber'], ['Fiber Installation (includes Wi-Fi router)', 151, 'one_off', 'fiber']] as [$n, $p, $b, $l]) {
            $w->create($t, ['name' => $n, 'price' => $p, 'billing' => $b, 'service_line' => $l, 'active' => true]);
        }
        app(TenantContext::class)->reset();

        return $t;
    }

    public function test_every_page_shows_live_prices_and_no_old_price_ssp_or_token(): void
    {
        $t = $this->dishnet();
        $s = '/' . $t->slug;

        $mini = $this->get("$s/device-mini.html")->assertOk()->getContent();
        $this->assertStringContainsString('<title>Starlink Mini Kit — $401 | DishNet Africa Ltd</title>', $mini);
        $this->assertStringContainsString('"price":"401"', $mini, 'Google product data carries the live price');
        $this->assertStringContainsString('Free in Juba', $mini, 'approved installation wording, not "+$50"');
        $this->assertStringContainsString('$81/mo for Roam or $51/mo for Residential', $mini);

        $actuated = $this->get("$s/device-actuated.html")->assertOk()->getContent();
        $this->assertStringContainsString('Ask our sales team', $actuated, 'no approved price → ask our sales team');
        $this->assertStringNotContainsString('"offers"', $actuated, 'no price data for Google without an approved price');

        $this->assertStringContainsString('within 7 days of termination', $this->get("$s/terms.html")->assertOk()->getContent(), 'the approved return period');
        $this->assertStringContainsString('confirmed by our Accounts team', $this->get("$s/pay.html")->assertOk()->getContent());
        $this->assertStringContainsString('Ask our sales team', $this->get("$s/hotspot.html")->assertOk()->getContent());

        foreach (['index.html', 'fiber.html', 'faq.html', 'services.html', 'hotspot.html', 'reseller.html', 'device-v4.html', 'device-hp.html',
            'blog-fiber-vs-starlink.html', 'blog-starlink-south-sudan.html', 'blog-setup-starlink.html', 'why-dishnet.html', 'testimonials.html',
            'pay.html', 'tutorials/view-pay-invoice.html', 'tutorials/send-invoice-to-whatsapp.html', 'about.html'] as $page) {
            $html = $this->get("$s/$page")->assertOk()->getContent();
            $this->assertStringNotContainsString('{{', $html, "$page: every token is filled");
            foreach (['$299', '$550', '$80', '$65', '$112', '$189', '$336', '$650', '$2,600', '6,200', 'SSP 1', 'SSP 4', 'SSP 6', '%2450', '%24299'] as $old) {
                $this->assertStringNotContainsString($old, $html, "$page still shows the old amount $old");
            }
        }
        $this->assertStringContainsString('$52', $this->get("$s/fiber.html")->getContent(), 'Fibre plans at the live price');
    }

    public function test_a_switched_off_or_hidden_product_reads_ask_our_sales_team(): void
    {
        $t = $this->dishnet();
        \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', 'Starlink Mini (Gen 4)')->update(['active' => false]);
        \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', 'Starlink Standard')->update(['hidden' => true]);
        $html = $this->get('/' . $t->slug . '/device-mini.html')->assertOk()->getContent();
        $this->assertStringNotContainsString('$401', $html);
        $this->assertStringContainsString('ask our sales team', $html);
        $v4 = $this->get('/' . $t->slug . '/device-v4.html')->assertOk()->getContent();
        $this->assertStringNotContainsString('$551', $v4, 'a product hidden from customers shows no price, as on the plans page');
    }

    public function test_pages_css_and_the_held_coverage_page(): void
    {
        $t = $this->dishnet();
        $s = '/' . $t->slug;
        $this->assertStringContainsString('About Us', $this->get("$s/about.html")->assertOk()->getContent());
        $this->get("$s/tutorials/index.html")->assertOk();
        $this->assertStringContainsString('text/css', (string) $this->get("$s/styles.css")->assertOk()->headers->get('Content-Type'));
        $this->get("$s/coverage.html")->assertRedirect("$s/shop#coverage");
        $this->get("$s/no-such-page.html")->assertNotFound();
        $this->get("$s/site.json")->assertNotFound();
        $this->get("$s/p/starlink-mini-12")->assertStatus(301)->assertRedirect("$s/shop#plans");
        $this->get("$s/c/starlink-hardware")->assertStatus(301)->assertRedirect("$s/shop#plans");
    }

    public function test_the_plans_page_links_home_and_the_site_pages(): void
    {
        $t = $this->dishnet(['web_chat' => true]);
        $html = $this->get('/' . $t->slug)->assertOk()->getContent();
        $this->assertStringContainsString('var ISP = {', $html, 'on the platform host /{shop} stays the plans page');
        $this->assertStringContainsString('{"href":"/' . $t->slug . '/index.html","label":"Home"}', $html);
        $this->assertStringContainsString('{"href":"/' . $t->slug . '/about.html","label":"About"}', $html);
        $this->assertStringNotContainsString('/' . $t->slug . '/coverage.html', $html, 'a held page is never linked');
    }

    public function test_on_its_own_domain_the_home_page_is_the_root_and_the_plans_page_is_shop(): void
    {
        $t = $this->dishnet();
        $t->custom_domain = 'dishnet-site.test';
        $t->save();

        $home = $this->get('http://dishnet-site.test/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>DishNet Africa Ltd', $home);
        $this->assertStringContainsString('$401', $home);
        $this->assertStringNotContainsString('{{', $home);
        $this->assertStringContainsString('var ISP = {', $this->get('http://dishnet-site.test/shop')->assertOk()->getContent());
        $this->get('http://dishnet-site.test/about.html')->assertOk();
        $this->get('http://www.dishnet-site.test/tutorials/index.html')->assertOk();
        $held = $this->get('http://dishnet-site.test/coverage.html')->assertStatus(302);
        $this->assertStringEndsWith('/shop#coverage', (string) $held->headers->get('Location'));

        $xml = $this->get('http://dishnet-site.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/shop</loc>', $xml);
        $this->assertStringContainsString('/fiber.html</loc>', $xml);
        $this->assertStringContainsString('/tutorials/change-wifi-password.html</loc>', $xml);
        $this->assertStringNotContainsString('/coverage.html', $xml, 'a held page is not advertised');
        $this->assertStringNotContainsString('/p/', $xml);
    }

    public function test_a_business_without_a_site_bundle_has_no_such_pages(): void
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'grocery']]);
        $this->get('/' . $t->slug . '/about.html')->assertNotFound();
        $bad = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'site_bundle' => '../panel']]);
        $this->get('/' . $bad->slug . '/about.html')->assertNotFound();
    }

    /** Guard: a stored page never states an amount — prices come only from live tokens filled from the catalogue. */
    public function test_no_stored_page_contains_an_amount(): void
    {
        $dir = resource_path('tenant-sites/dishnet');
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (! str_ends_with($f->getPathname(), '.html')) {
                continue;
            }
            $raw = (string) file_get_contents($f->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\bUSD\s?\d|\d\s?USD\b|\bSSP\s?\d|1 USD =|"price"\s*:\s*"\d|%24\d/u', $raw,
                basename($f->getPathname()) . ' states an amount — use a {{price:…}} token');
            $checked++;
        }
        $this->assertGreaterThanOrEqual(45, $checked);
    }
}
