<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public Solar Knowledge Center (customer-facing, no login). Proves the hub lists articles and FAQs, that
 * each article is server-rendered with SEO tags (unique title, meta description, canonical), that related
 * links appear, and that unknown articles/shops 404.
 */
class StorefrontLearnTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Tenant
    {
        return Tenant::create(['name' => 'GSL Solar', 'slug' => 'gsl-' . substr(md5(uniqid('', true)), 0, 6),
            'settings' => ['vertical' => 'solar']]);
    }

    public function test_hub_lists_articles_and_faqs(): void
    {
        $t = $this->shop();
        $res = $this->get('/' . $t->slug . '/learn');   // public, no auth
        $res->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('How Solar Power Works', $html);
        $this->assertStringContainsString('Frequently asked questions', $html);
        $this->assertStringContainsString($t->slug . '/solar', $html);   // CTA to the assessment
    }

    public function test_article_is_seo_rendered(): void
    {
        $t = $this->shop();
        $res = $this->get('/' . $t->slug . '/learn/how-solar-works');
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('<title>How Solar Power Works — GSL Solar</title>', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('og:title', $html);
        $this->assertStringContainsString('The four parts', $html);          // body content
        $this->assertStringContainsString('Related reading', $html);         // related section
    }

    public function test_unknown_article_404(): void
    {
        $t = $this->shop();
        $this->get('/' . $t->slug . '/learn/no-such-article')->assertStatus(404);
    }

    public function test_unknown_shop_404(): void
    {
        $this->get('/no-such-shop-xyz/learn')->assertStatus(404);
    }
}
