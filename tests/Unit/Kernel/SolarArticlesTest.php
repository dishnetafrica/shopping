<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\SolarArticles;
use Tests\TestCase;

/**
 * Solar Knowledge Center — article library (no DB). Proves the articles are complete, slugs are unique,
 * every "related" link points to a real article, and meta descriptions stay within a sensible SEO length.
 */
class SolarArticlesTest extends TestCase
{
    public function test_articles_are_complete_and_linked(): void
    {
        $all = SolarArticles::all();
        $slugs = array_column($all, 'slug');

        $this->assertGreaterThanOrEqual(10, count($all));
        $this->assertSame(count($slugs), count(array_unique($slugs)), 'slugs must be unique');

        foreach ($all as $a) {
            foreach (['slug', 'title', 'category', 'read', 'meta', 'excerpt', 'body'] as $k) {
                $this->assertNotEmpty($a[$k] ?? null, ($a['slug'] ?? '?') . " missing $k");
            }
            $this->assertLessThanOrEqual(180, strlen((string) $a['meta']), $a['slug'] . ' meta too long for SEO');
            foreach ((array) ($a['related'] ?? []) as $rel) {
                $this->assertContains($rel, $slugs, $a['slug'] . " has a dangling related link: $rel");
            }
        }
    }

    public function test_get_and_categories(): void
    {
        $this->assertNotNull(SolarArticles::get('how-solar-works'));
        $this->assertNull(SolarArticles::get('does-not-exist'));
        $this->assertGreaterThanOrEqual(4, count(SolarArticles::categories()));
    }
}
