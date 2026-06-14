<?php

namespace Tests\Unit;

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;
use App\Services\Bot\ShoppingParser;
use PHPUnit\Framework\TestCase;

/**
 * Phase-1 brain migration — deterministic shopping engine.
 * Pure unit test (no DB / no app boot): catalogue is an in-memory fixture,
 * exactly how BotBrain feeds the engine in production.
 */
class ShoppingEngineTest extends TestCase
{
    private array $cat = [
        ['id' => 1, 'name' => 'Pakistan Rice 5kg',      'category' => 'Rice',        'keywords' => '',                'price' => 38000, 'stock' => 10],
        ['id' => 2, 'name' => 'Local Rice 1kg',         'category' => 'Rice',        'keywords' => '',                'price' => 6300,  'stock' => 10],
        ['id' => 3, 'name' => 'Kinyara Sugar 1kg',      'category' => 'Sugar',       'keywords' => 'sakar khand',     'price' => 4500,  'stock' => 10],
        ['id' => 4, 'name' => 'Sunseed Cooking Oil 1L', 'category' => 'Cooking Oil', 'keywords' => 'tel oil',         'price' => 9000,  'stock' => 10],
        ['id' => 5, 'name' => 'Jesa Milk 500ml',        'category' => 'Milk',        'keywords' => 'doodh dudh',      'price' => 3000,  'stock' => 10],
        ['id' => 6, 'name' => 'Superloaf Bread',        'category' => 'Bakery',      'keywords' => 'bread',           'price' => 4000,  'stock' => 10],
        ['id' => 7, 'name' => 'Pearl Atta Flour 2kg',   'category' => 'Flour',       'keywords' => 'atta aata maida', 'price' => 12000, 'stock' => 10],
    ];

    private function engine(): ShoppingEngine
    {
        return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), 'UGX');
    }

    private function cartMap(array $cart): array
    {
        $m = [];
        foreach ($cart as $l) {
            $m[$l['name']] = $l['qty'];
        }
        return $m;
    }

    public function test_example1_question_multi_item_with_ambiguity(): void
    {
        $r = $this->engine()->handle('Do you have rice and sugar?', $this->cat, [], []);
        $this->assertCount(3, $r['options']);                       // 2 rice + 1 sugar
        $this->assertCount(0, $r['cart']);                          // browse: nothing auto-added
    }

    public function test_example2_unit_quantity_and_plain_item(): void
    {
        $r = $this->engine()->handle('2kg sugar and bread', $this->cat, [], []);
        $m = $this->cartMap($r['cart']);
        $this->assertSame(2, $m['Kinyara Sugar 1kg'] ?? 0);
        $this->assertSame(1, $m['Superloaf Bread'] ?? 0);
    }

    public function test_example3_plural_and_quantities(): void
    {
        $r = $this->engine()->handle('give me 2 oils and 3 milk', $this->cat, [], []);
        $m = $this->cartMap($r['cart']);
        $this->assertSame(2, $m['Sunseed Cooking Oil 1L'] ?? 0);
        $this->assertSame(3, $m['Jesa Milk 500ml'] ?? 0);
    }

    public function test_example4_trailing_quantity(): void
    {
        $r = $this->engine()->handle('sugar 2', $this->cat, [], []);
        $this->assertSame(2, $this->cartMap($r['cart'])['Kinyara Sugar 1kg'] ?? 0);
    }

    public function test_example5_quantity_plus_ambiguous_clarifies(): void
    {
        $r = $this->engine()->handle('5 rice', $this->cat, [], []);
        $this->assertCount(0, $r['cart']);
        $this->assertCount(2, $r['options']);
        $this->assertSame(5, $r['options'][0]['qty']);             // qty carried into clarify
    }

    public function test_example6_bare_list_shows_options(): void
    {
        // bare list (no verb / no quantity) => SHOW each, recognised separately, nothing auto-added
        $r = $this->engine()->handle('rice, sugar, bread and oil', $this->cat, [], []);
        $this->assertCount(0, $r['cart']);
        $this->assertCount(5, $r['options']);                      // rice(2) + sugar + bread + oil
    }

    public function test_example7_gujarati_synonyms(): void
    {
        // bare synonym list => recognised + shown (atta->Flour, doodh->Milk, tel->Oil)
        $r = $this->engine()->handle('atta, doodh and tel', $this->cat, [], []);
        $names = array_map(fn ($o) => $o['name'], $r['options']);
        $this->assertCount(0, $r['cart']);
        $this->assertNotEmpty(array_filter($names, fn ($n) => stripos($n, 'Flour') !== false));
        $this->assertNotEmpty(array_filter($names, fn ($n) => stripos($n, 'Milk') !== false));
        $this->assertNotEmpty(array_filter($names, fn ($n) => stripos($n, 'Oil') !== false));
    }

    public function test_example8_synonym_with_fused_unit_quantity(): void
    {
        $r = $this->engine()->handle('sakar 2kg', $this->cat, [], []);
        $this->assertSame(2, $this->cartMap($r['cart'])['Kinyara Sugar 1kg'] ?? 0);
    }

    public function test_clarification_numeric_selection(): void
    {
        $r1 = $this->engine()->handle('5 rice', $this->cat, [], []);
        $r2 = $this->engine()->handle('1', $this->cat, $r1['cart'], $r1['state']);
        $this->assertSame(5, $this->cartMap($r2['cart'])['Local Rice 1kg'] ?? 0);
        $this->assertArrayNotHasKey('options', $r2['state']);
    }

    public function test_clarification_name_selection(): void
    {
        $r1 = $this->engine()->handle('5 rice', $this->cat, [], []);
        $r2 = $this->engine()->handle('pakistan', $this->cat, $r1['cart'], $r1['state']);
        $this->assertSame(5, $this->cartMap($r2['cart'])['Pakistan Rice 5kg'] ?? 0);
    }

    public function test_multi_pick_selection(): void
    {
        $r1 = $this->engine()->handle('Do you have rice and sugar?', $this->cat, [], []);
        $r2 = $this->engine()->handle('2, 3', $this->cat, $r1['cart'], $r1['state']);
        $this->assertCount(2, $r2['cart']);
    }
}
