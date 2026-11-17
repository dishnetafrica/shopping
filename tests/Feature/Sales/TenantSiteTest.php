<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DishNet's own multi-page website served by ShopBot (site bundle resources/tenant-sites/dishnet). Pages that
 * carry prices, SSP amounts, coverage or terms management has not approved are HELD: they send the visitor to
 * the live plans / coverage / chat page instead. Every page served as-is must hold no amount at all.
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
        return Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'site_bundle' => 'dishnet'] + $extra]);
    }

    public function test_the_site_pages_are_served_and_held_pages_go_to_the_live_page(): void
    {
        $t = $this->dishnet();
        $s = '/' . $t->slug;

        $about = $this->get("$s/about.html")->assertOk();
        $this->assertStringContainsString('text/html', (string) $about->headers->get('Content-Type'));
        $this->assertStringContainsString('About Us', $about->getContent());
        $this->get("$s/tutorials/index.html")->assertOk();
        $this->get("$s/tutorials/change-wifi-password.html")->assertOk();
        $this->assertStringContainsString('text/css', (string) $this->get("$s/styles.css")->assertOk()->headers->get('Content-Type'));
        $this->get("$s/tutorials/tutorial-shared.css")->assertOk();

        $this->get("$s/index.html")->assertRedirect($s);
        $this->get("$s/device-mini.html")->assertRedirect("$s#plans");
        $this->get("$s/coverage.html")->assertRedirect("$s#coverage");
        $this->get("$s/terms.html")->assertRedirect("$s#chat");
        $this->get("$s/tutorials/view-pay-invoice.html")->assertRedirect("$s#chat");

        $this->get("$s/no-such-page.html")->assertNotFound();
        $this->get("$s/site.json")->assertNotFound();
    }

    public function test_the_plans_page_links_to_the_site_pages_but_never_to_a_held_one(): void
    {
        $t = $this->dishnet(['web_chat' => true]);
        $html = $this->get('/' . $t->slug)->assertOk()->getContent();
        $this->assertStringContainsString('{"href":"/' . $t->slug . '/about.html","label":"About"}', $html);
        $this->assertStringContainsString('"href":"/' . $t->slug . '/tutorials/index.html"', $html);
        foreach (['faq.html', 'device-mini.html', 'terms.html', 'coverage.html'] as $held) {
            $this->assertStringNotContainsString('/' . $t->slug . '/' . $held, $html, "held page linked: $held");
        }
    }

    public function test_on_its_own_domain_the_pages_are_at_the_root(): void
    {
        $t = $this->dishnet();
        $t->custom_domain = 'dishnet-site.test';
        $t->save();

        $this->get('http://dishnet-site.test/about.html')->assertOk();
        $this->get('http://www.dishnet-site.test/tutorials/index.html')->assertOk();
        $held = $this->get('http://dishnet-site.test/fiber.html')->assertStatus(302);
        $this->assertStringEndsWith('/#plans', (string) $held->headers->get('Location'));
    }

    /** Codex review on #157: old product / category links and the sitemap must not lead back to a basket. */
    public function test_product_and_category_links_go_to_the_plans_page_and_the_sitemap_lists_the_site(): void
    {
        $t = $this->dishnet();
        $s = '/' . $t->slug;
        $this->get("$s/p/starlink-mini-12")->assertStatus(301)->assertRedirect("$s#plans");
        $this->get("$s/c/starlink-hardware")->assertStatus(301)->assertRedirect("$s#plans");

        $t->custom_domain = 'dishnet-site.test';
        $t->save();
        $xml = $this->get('http://dishnet-site.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/about.html</loc>', $xml);
        $this->assertStringContainsString('/tutorials/change-wifi-password.html</loc>', $xml);
        $this->assertStringNotContainsString('/fiber.html', $xml, 'a held page is not advertised');
        $this->assertStringNotContainsString('/p/', $xml);
        $this->assertStringNotContainsString('/c/', $xml);
    }

    public function test_a_business_without_a_site_bundle_has_no_such_pages(): void
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'grocery']]);
        $this->get('/' . $t->slug . '/about.html')->assertNotFound();
        $bad = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'site_bundle' => '../panel']]);
        $this->get('/' . $bad->slug . '/about.html')->assertNotFound();
    }

    /** Guard: a page served as-is never states an amount — an amount needs approval, so its page must be held. */
    public function test_no_page_served_as_is_contains_a_price_or_ssp_amount(): void
    {
        $dir = resource_path('tenant-sites/dishnet');
        $hold = (array) (json_decode((string) file_get_contents($dir . '/site.json'), true)['hold'] ?? []);
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
            if (! str_ends_with($rel, '.html') || array_key_exists($rel, $hold)) {
                continue;
            }
            $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#si', ' ', (string) file_get_contents($f->getPathname()))));
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\bUSD\s?\d|\d\s?USD\b|\bSSP\s?\d/u', $text, "$rel states an amount — hold it in site.json");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(25, $checked);
    }
}
