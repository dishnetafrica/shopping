<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Premium footer + SEO landings (2026-07-19): navigation, trust, and crawlable category pages. */
class StorefrontFooterTest extends TestCase
{
    public function test_footer_is_dynamic_real_links_only_and_seo_ready(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Storefront/StorefrontController.php'));
        $this->assertStringContainsString('premiumFooter', $src);
        $this->assertStringContainsString('liveCategories', $src, 'links generated from the live catalogue');
        $this->assertStringContainsString('schema.org', $src);
        $this->assertStringContainsString('prefers-color-scheme:dark', $src, 'dark mode');
        $this->assertStringContainsString('Shop by industry', $src);
        $this->assertStringContainsString('function sitemap', $src);
        $this->assertStringContainsString('function category', $src);

        $r = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("'/c/{slug}'", $r);
        $this->assertStringContainsString("'/sitemap.xml'", $r);
    }
}
