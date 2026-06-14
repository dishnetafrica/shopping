<?php

namespace Tests\Unit;

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;
use App\Services\Bot\ShoppingParser;
use PHPUnit\Framework\TestCase;

/**
 * Default Product Strategy — size-aware resolution + owner defaults.
 * Pure unit test (no DB). Guarantees: fewer questions, no wrong orders.
 */
class ProductDefaultStrategyTest extends TestCase
{
    private array $cat = [
        ['id' => 1, 'name' => 'Local Rice 1kg',     'category' => 'Rice',  'keywords' => 'chawal', 'price' => 6300,  'stock' => 10],
        ['id' => 2, 'name' => 'Pearl Rice 2kg',     'category' => 'Rice',  'keywords' => 'chawal', 'price' => 12000, 'stock' => 10],
        ['id' => 3, 'name' => 'Pakistan Rice 5kg',  'category' => 'Rice',  'keywords' => 'chawal', 'price' => 38000, 'stock' => 10],
        ['id' => 4, 'name' => 'Kinyara Sugar 1kg',  'category' => 'Sugar', 'keywords' => 'sakar',  'price' => 4500,  'stock' => 10],
        ['id' => 5, 'name' => 'Jesa Milk 500ml',    'category' => 'Milk',  'keywords' => 'doodh',  'price' => 1800,  'stock' => 10],
        ['id' => 6, 'name' => 'Fresh Dairy Milk 1L','category' => 'Milk',  'keywords' => 'doodh',  'price' => 3500,  'stock' => 10],
    ];
    private array $def = ['rice' => 3, 'milk' => 6];

    private function eng(array $defaults = [], string $strategy = 'explicit'): ShoppingEngine
    {
        return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX', $defaults, $strategy);
    }
    private function name(array $r, string $kw): ?string { foreach ($r['cart'] as $l) if (stripos($l['name'], $kw) !== false) return $l['name']; return null; }
    private function qty(array $r, string $kw): int { foreach ($r['cart'] as $l) if (stripos($l['name'], $kw) !== false) return $l['qty']; return 0; }
    private function opts(array $r): int { return count($r['state']['options'] ?? []); }

    public function test_default_applied_to_generic_word(): void
    {
        $r = $this->eng($this->def)->handle('Rice', $this->cat, [], []);
        $this->assertSame('Pakistan Rice 5kg', $this->name($r, 'Rice'));
        $this->assertSame(1, $this->qty($r, 'Rice'));
    }

    public function test_quantity_with_default(): void
    {
        $r = $this->eng($this->def)->handle('5 rice', $this->cat, [], []);
        $this->assertSame('Pakistan Rice 5kg', $this->name($r, 'Rice'));
        $this->assertSame(5, $this->qty($r, 'Rice'));
    }

    public function test_stated_size_beats_default(): void
    {
        $r = $this->eng($this->def)->handle('rice 2kg', $this->cat, [], []);
        $this->assertSame('Pearl Rice 2kg', $this->name($r, 'Rice'));
        $this->assertSame(1, $this->qty($r, 'Rice'));
    }

    public function test_count_and_size_together(): void
    {
        $r = $this->eng($this->def)->handle('2 5kg rice', $this->cat, [], []);
        $this->assertSame('Pakistan Rice 5kg', $this->name($r, 'Rice'));
        $this->assertSame(2, $this->qty($r, 'Rice'));
    }

    public function test_size_conflict_clarifies(): void
    {
        $r = $this->eng($this->def)->handle('rice 3kg', $this->cat, [], []); // no 3kg SKU
        $this->assertCount(0, $r['cart']);
        $this->assertGreaterThanOrEqual(2, $this->opts($r));
    }

    public function test_no_default_clarifies(): void
    {
        $r = $this->eng([])->handle('Rice', $this->cat, [], []);
        $this->assertCount(0, $r['cart']);
        $this->assertGreaterThanOrEqual(2, $this->opts($r));
    }

    public function test_single_sku_quantity_preserved(): void
    {
        $r = $this->eng($this->def)->handle('2kg sugar', $this->cat, [], []);
        $this->assertSame(2, $this->qty($r, 'Sugar'));
    }

    public function test_out_of_stock_default_clarifies(): void
    {
        $cat = $this->cat; $cat[2]['stock'] = 0; // default Rice 5kg out of stock
        $r = $this->eng($this->def)->handle('rice', $cat, [], []);
        $this->assertCount(0, $r['cart']);
    }

    public function test_size_hint_shown_once(): void
    {
        $r1 = $this->eng($this->def)->handle('rice', $this->cat, [], []);
        $r2 = $this->eng($this->def)->handle('milk', $this->cat, $r1['cart'], $r1['state']);
        $this->assertStringContainsStringIgnoringCase('different size', $r1['reply']);
        $this->assertStringNotContainsStringIgnoringCase('different size', $r2['reply']);
    }

    public function test_browse_overrides_default(): void
    {
        $r = $this->eng($this->def)->handle('show me rice', $this->cat, [], []);
        $this->assertCount(0, $r['cart']);
        $this->assertSame(3, $this->opts($r));
    }

    public function test_strategy_off_ignores_default(): void
    {
        $r = $this->eng($this->def, 'off')->handle('rice', $this->cat, [], []);
        $this->assertCount(0, $r['cart']);
    }

    public function test_multi_product_defaults_both_add(): void
    {
        $cat = [
            ['id'=>1,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'','price'=>6300,'stock'=>10],
            ['id'=>3,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'','price'=>38000,'stock'=>10],
            ['id'=>4,'name'=>'Kinyara Sugar 1kg','category'=>'Sugar','keywords'=>'','price'=>4500,'stock'=>10],
            ['id'=>5,'name'=>'Kinyara Sugar 5kg','category'=>'Sugar','keywords'=>'','price'=>20000,'stock'=>10],
        ];
        $r = $this->eng(['rice'=>3,'sugar'=>4])->handle('2 Rice and 3 Sugar', $cat, [], []);
        $this->assertSame('Pakistan Rice 5kg', $this->name($r,'Rice'));
        $this->assertSame(2, $this->qty($r,'Rice'));
        $this->assertSame('Kinyara Sugar 1kg', $this->name($r,'Sugar'));
        $this->assertSame(3, $this->qty($r,'Sugar'));
    }

    public function test_tenant_isolation_per_defaults(): void
    {
        $catA = [
            ['id'=>1,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'','price'=>6300,'stock'=>10],
            ['id'=>3,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'','price'=>38000,'stock'=>10],
        ];
        $catB = [
            ['id'=>101,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'','price'=>6300,'stock'=>10],
            ['id'=>103,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'','price'=>38000,'stock'=>10],
        ];
        $a = $this->eng(['rice'=>3])->handle('Rice', $catA, [], []);
        $b = $this->eng(['rice'=>101])->handle('Rice', $catB, [], []);
        $this->assertSame('Pakistan Rice 5kg', $this->name($a,'Rice'));
        $this->assertSame('Local Rice 1kg', $this->name($b,'Rice'));
        $this->assertSame(3, $a['cart'][0]['product_id']);
        $this->assertSame(101, $b['cart'][0]['product_id']);
    }
}
