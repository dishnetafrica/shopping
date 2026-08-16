<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\UnitGuard;
use PHPUnit\Framework\TestCase;

/**
 * Phase-0 Shopping-Safety — unit/quantity/weight invariant.
 *
 * A weight/volume SIZE must never become the cart QUANTITY. Guards BOTH understanding paths:
 * the deterministic ShoppingEngine and the post-NLU add path (via UnitGuard::sizeForItem).
 * Pure unit test — no DB, no app boot; the catalogue is an in-memory fixture.
 *
 * The sandbox mirror (pure-PHP, no PHPUnit) is tests/Bot/shopping_safety_harness.php.
 */
class UnitGuardTest extends TestCase
{
    /** Distinct search terms so each probe hits a single candidate — the path that carried the bug. */
    private array $cat = [
        ['id' => 1, 'name' => 'Kabras Sugar',     'category' => 'Sugar',       'keywords' => 'sakar',   'price' => 4500,  'stock' => 100, 'sold_by_weight' => false],
        ['id' => 2, 'name' => 'Basmati Rice 1kg', 'category' => 'Rice',        'keywords' => 'basmati', 'price' => 12000, 'stock' => 100, 'sold_by_weight' => false],
        ['id' => 3, 'name' => 'Fafda',            'category' => 'Snacks',      'keywords' => 'fafda',   'price' => 2000,  'stock' => 100, 'sold_by_weight' => true, 'reference_price' => 2000, 'reference_weight_grams' => 1000],
        ['id' => 4, 'name' => 'Sunflower Oil 5L', 'category' => 'Cooking Oil', 'keywords' => 'oil tel', 'price' => 45000, 'stock' => 100, 'sold_by_weight' => false],
        ['id' => 5, 'name' => 'Superloaf Bread',  'category' => 'Bakery',      'keywords' => 'bread',   'price' => 4000,  'stock' => 100, 'sold_by_weight' => false],
    ];

    private function engine(): ShoppingEngine
    {
        return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX');
    }

    private function qty(array $cart, string $needle): int
    {
        foreach ($cart as $l) {
            if (stripos((string) ($l['name'] ?? ''), $needle) !== false) {
                return (int) ($l['qty'] ?? 0);
            }
        }
        return 0;
    }

    /** PERMANENT REGRESSION for the reproduced audit failure: "add 250g sugar" must NOT become qty 250. */
    public function test_reproduced_250g_never_becomes_quantity_250(): void
    {
        $r = $this->engine()->handle('add 250g sugar', $this->cat, [], []);
        $this->assertNotSame(250, $this->qty($r['cart'], 'Kabras'));
        $this->assertCount(0, $r['cart'], 'a sub-unit size on a sizeless SKU must clarify, not auto-add');

        $parsed = (new ShoppingParser())->parse('add 250g sugar');
        $this->assertSame(1, $parsed['items'][0]['qty']);        // qty is 1, not 250
        $this->assertSame('250g', $parsed['items'][0]['size']);  // size preserved separately
    }

    /** The full weight/volume matrix: none of these amounts may leak into the quantity. */
    public function test_weight_and_volume_never_become_quantity(): void
    {
        foreach (['250g' => 250, '500g' => 500, '1kg' => 1, '2kg' => 2, '250ml' => 250, '500ml' => 500, '1l' => 1, '2l' => 2] as $size => $bad) {
            $r = $this->engine()->handle("add {$size} sugar", $this->cat, [], []);
            $this->assertNotSame($bad, $this->qty($r['cart'], 'Kabras'), "\"{$size}\" leaked into quantity");
            $this->assertCount(0, $r['cart'], "\"{$size}\" on a sizeless SKU should clarify");
        }
    }

    /** Count units are genuine quantities. */
    public function test_count_units_are_quantities(): void
    {
        foreach (['5 pieces sugar', '5 cartons sugar', '5 boxes sugar', '5 packs sugar'] as $msg) {
            $r = $this->engine()->handle($msg, $this->cat, [], []);
            $this->assertSame(5, $this->qty($r['cart'], 'Kabras'), $msg);
        }
    }

    /** Legitimate whole-pack composition is a SAFE auto-resolve and must be preserved. */
    public function test_pack_composition_preserved(): void
    {
        $this->assertSame(2, $this->qty($this->engine()->handle('2kg rice', $this->cat, [], [])['cart'], 'Basmati'));
        $this->assertSame(1, $this->qty($this->engine()->handle('1kg rice', $this->cat, [], [])['cart'], 'Basmati'));
        $this->assertSame(3, $this->qty($this->engine()->handle('3kg rice', $this->cat, [], [])['cart'], 'Basmati'));
    }

    /** Genuinely ambiguous sizes must clarify, never guess a quantity. */
    public function test_ambiguous_sizes_clarify(): void
    {
        $this->assertCount(0, $this->engine()->handle('250g rice', $this->cat, [], [])['cart']); // can't make 250g from 1kg packs
        $this->assertCount(0, $this->engine()->handle('2kg oil', $this->cat, [], [])['cart']);   // 2kg (weight) vs 5L (volume)
    }

    public function test_unit_guard_decide_matrix(): void
    {
        $this->assertSame('clarify', UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => '250g', 'count' => null])['mode']);
        $this->assertSame(['mode' => 'weight', 'grams' => 250, 'qty' => 1], UnitGuard::decide(['name' => 'Fafda', 'sold_by_weight' => true, 'size' => '250g', 'count' => null]));
        $this->assertSame(['mode' => 'count', 'qty' => 2], UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '2kg', 'count' => null]));
        $this->assertSame(['mode' => 'count', 'qty' => 1], UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '1kg', 'count' => null]));
        $this->assertSame('clarify', UnitGuard::decide(['name' => 'Basmati Rice 1kg', 'sold_by_weight' => false, 'size' => '250g', 'count' => null])['mode']);
        $this->assertSame(['mode' => 'count', 'qty' => 5], UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => null, 'count' => 5]));
        $this->assertSame(['mode' => 'count', 'qty' => 1], UnitGuard::decide(['name' => 'Kabras Sugar', 'sold_by_weight' => false, 'size' => null, 'count' => null]));
        $this->assertNull(UnitGuard::packMultiple('2kg', '5l'));
    }

    /** The post-NLU/LLM guard: a size the model folded into qty is recovered from the original text. */
    public function test_llm_size_folded_into_qty_is_caught(): void
    {
        $this->assertSame('250g', UnitGuard::sizeForItem('sugar', 250, 'add 250g sugar'));   // query lost the size
        $this->assertSame('250g', UnitGuard::sizeForItem('250g sugar', 1, 'add 250g sugar')); // size still in query
        $this->assertNull(UnitGuard::sizeForItem('sugar', 5, '5 sugar'));                     // legit count, not a size
        $this->assertSame('2kg', UnitGuard::sizeForItem('sugar', 2, '2kg sugar'));            // catches C4 on the LLM path
        $this->assertNull(UnitGuard::sizeFoldedIntoQty('add 1kg rice', 1));                   // qty 1 is never an inflated size
    }

    /**
     * Staging regression: the LLM returned {query:"ajinomoto", qty:1} for "add 250g ajinomoto" — the
     * size never inflated qty, so it must be recovered from its position beside the item and clarified,
     * not silently substituted with a 500gm pack.
     */
    public function test_llm_dropped_size_with_qty_one_is_still_caught(): void
    {
        $this->assertSame('250g', UnitGuard::sizeForItem('ajinomoto', 1, 'add 250g ajinomoto'));
        $this->assertSame('250g', UnitGuard::sizeForItem('aluminium foil', 1, 'add 250g aluminium foil'));
        $this->assertSame('clarify', UnitGuard::decide(['name' => 'Ajinomoto 500gm', 'sold_by_weight' => false, 'size' => '250g', 'count' => null])['mode']);
        $this->assertSame(['mode' => 'count', 'qty' => 1], UnitGuard::decide(['name' => 'Ajinomoto 500gm', 'sold_by_weight' => false, 'size' => '500g', 'count' => null]));
        $this->assertNull(UnitGuard::sizeForItem('glasses', 5, 'add 5 cartons glasses'));       // count unit, not a size
        $this->assertNull(UnitGuard::sizeForItem('oil', 3, 'add 250g sugar and 3 oil'));        // no cross-item theft
    }
}
