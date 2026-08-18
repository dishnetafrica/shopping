<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use PHPUnit\Framework\TestCase;

/**
 * Regression: "first photo not showing after multiple photos".
 *
 * When a product's photos were uploaded into the gallery slots without a hero (image_url) being set,
 * the storefront card/listing feed — which read image_url alone — rendered a placeholder while the
 * detail page (which falls back through the gallery) showed a photo. Product::primaryImage() is the
 * single resolver both now use: first non-empty of hero → gallery_1 → gallery_2 → gallery_3.
 *
 * Pure unit test (no DB): model attribute access + a pure static helper. Sandbox mirror:
 * tests/product_primary_image_harness.php.
 */
class ProductPrimaryImageTest extends TestCase
{
    public function test_hero_is_the_primary_when_set(): void
    {
        $p = new Product(['image_url' => 'hero.jpg', 'gallery_1' => 'g1.jpg', 'gallery_2' => 'g2.jpg']);
        $this->assertSame('hero.jpg', $p->primaryImage());
    }

    /** THE reported bug: photos only in the gallery, hero empty → the first gallery photo is primary. */
    public function test_falls_back_to_first_gallery_photo_when_hero_empty(): void
    {
        $p = new Product(['image_url' => '', 'gallery_1' => 'g1.jpg', 'gallery_2' => 'g2.jpg', 'gallery_3' => 'g3.jpg']);
        $this->assertSame('g1.jpg', $p->primaryImage());
    }

    public function test_skips_empty_gallery_slots(): void
    {
        $p = new Product(['image_url' => '  ', 'gallery_1' => '', 'gallery_2' => 'g2.jpg']);
        $this->assertSame('g2.jpg', $p->primaryImage());
    }

    public function test_null_when_no_photos_at_all(): void
    {
        $this->assertNull((new Product([]))->primaryImage());
        $this->assertNull((new Product(['image_url' => '', 'gallery_1' => '   ']))->primaryImage());
    }

    /** The pure resolver, exercised directly (order + whitespace handling). */
    public function test_first_image_helper_precedence(): void
    {
        $this->assertSame('a', Product::firstImage('a', 'b', 'c', 'd'));
        $this->assertSame('b', Product::firstImage('', 'b', 'c'));
        $this->assertSame('c', Product::firstImage(null, ' ', 'c'));
        $this->assertSame('d', Product::firstImage('', '', '', 'd'));
        $this->assertNull(Product::firstImage('', null, '   '));
        $this->assertNull(Product::firstImage());
    }
}
