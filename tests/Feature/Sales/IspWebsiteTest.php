<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An internet provider's website (DishNet plan): plans and coverage from APPROVED data only, a chat on
 * the same governed brain, and no basket — styled after DishNet's own site. Other businesses unchanged.
 * Names, prices and places are test values.
 */
class IspWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical, array $extra = []): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'web_chat' => true] + $extra]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function coverage(Tenant $t, array $areas, bool $publish): void
    {
        $mgr = User::create(['tenant_id' => $t->id, 'name' => 'Manager', 'role' => 'manager', 'email' => 'm' . $t->id . uniqid() . '@t.test', 'password' => 'secret']);
        $brain = app(SalesBrain::class);
        $r = $brain->startVersion($t, $mgr, 'coverage', $publish ? 'fiber' : 'draft-areas', 'Areas', null, ['areas' => $areas]);
        $this->assertTrue($r['ok'], json_encode($r));
        if ($publish) {
            $brain->approve($t, $mgr, $r['version_id']);
            $brain->publish($t, $mgr, $r['version_id']);
        }
    }

    public function test_an_internet_providers_website_shows_approved_plans_and_coverage_and_no_basket(): void
    {
        $t = $this->business('isp');
        $w = app(ProductWriteService::class);
        $w->create($t, ['name' => 'Test Fibre 20', 'price' => 50, 'billing' => 'monthly', 'service_line' => 'fiber', 'active' => true]);
        $w->create($t, ['name' => 'Test Kit Mini', 'price' => 400, 'billing' => 'one_off', 'service_line' => 'starlink', 'active' => true]);
        $w->create($t, ['name' => 'Test Hidden Plan', 'price' => 999, 'billing' => 'monthly', 'service_line' => 'fiber', 'active' => false]);
        $this->coverage($t, [
            ['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town']],
            ['name' => 'Survey Village', 'service_line' => 'fiber', 'status' => 'survey', 'keywords' => ['survey village']],
        ], true);
        $this->coverage($t, [['name' => 'Draft Place', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['draft place']]], false);
        app(TenantContext::class)->reset();

        foreach (['/' . $t->slug, '/' . $t->slug . '/shop', '/' . $t->slug . '/chat'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('var ISP = {', $html, $path);
            $this->assertStringContainsString('"name":"Test Fibre 20","price":50', $html);
            $this->assertStringContainsString('"billing":"monthly"', $html);
            $this->assertStringContainsString('"name":"Test Kit Mini","price":400', $html);
            $this->assertStringNotContainsString('Test Hidden Plan', $html, 'a switched-off product never shows');
            $this->assertStringContainsString('{"name":"Test Town","line":"fiber"}', $html, 'published, covered');
            $this->assertStringNotContainsString('Survey Village', $html, 'not confirmed as covered');
            $this->assertStringNotContainsString('Draft Place', $html, 'a draft never reaches the website');
            $this->assertStringContainsString('"accent":"#C8102E"', $html, 'the internet-provider look by default');
            foreach (['Add to cart', 'View Cart', 'checkout', 'chatOnlyCss'] as $retail) {
                $this->assertStringNotContainsStringIgnoringCase($retail, $html, "$path must not show: $retail");
            }
        }
        $this->assertStringContainsString('"openChat":true', $this->get('/' . $t->slug . '/chat')->getContent());
    }

    public function test_the_businesss_own_colour_wins(): void
    {
        $t = $this->business('isp', ['theme_accent' => '#123456']);
        app(TenantContext::class)->reset();
        $this->assertStringContainsString('"accent":"#123456"', $this->get('/' . $t->slug)->assertOk()->getContent());
    }

    public function test_a_grocery_website_is_exactly_the_shop_it_was(): void
    {
        $t = $this->business('grocery');
        app(TenantContext::class)->reset();
        $html = $this->get('/' . $t->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('var ISP = {', $html);
        $this->assertStringContainsString('chatOnlyCss', $html, 'a grocery still gets the shop page');
    }
}
