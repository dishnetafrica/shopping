<?php
namespace Tests\Unit\Orders\Resolve;

use App\Apps\Orders\Resolve\CandidateScorer;
use App\Apps\Orders\Resolve\ProductResolution;
use PHPUnit\Framework\TestCase;

class CandidateScorerTest extends TestCase
{
    private function catalogue(bool $gathiyaDefault = true): array
    {
        return [
            ['id' => 1, 'name' => 'Vanela Gathiya', 'aliases' => ['vanela'], 'misspellings' => ['vanilaa', 'vanelaa'],
             'tokens' => ['gathiya'], 'default_aliases' => $gathiyaDefault ? ['gathiya'] : [],
             'popularity' => 80, 'available' => true, 'category' => 'farsan'],
            ['id' => 2, 'name' => 'Fafda Gathiya', 'tokens' => ['gathiya'], 'popularity' => 40, 'available' => true, 'category' => 'farsan'],
            ['id' => 3, 'name' => 'Vanilla Cake', 'popularity' => 5, 'available' => true, 'category' => 'bakery'],
        ];
    }

    public function test_vanela_auto_resolves_to_vanela_gathiya(): void
    {
        $r = (new CandidateScorer())->rank('Vanela', $this->catalogue());
        $this->assertSame(ProductResolution::AUTO, $r->decision);
        $this->assertSame(1, $r->line->productId);
    }

    public function test_default_alias_auto_resolves(): void
    {
        $r = (new CandidateScorer())->rank('Gathiya', $this->catalogue(gathiyaDefault: true));
        $this->assertSame(ProductResolution::AUTO, $r->decision);
        $this->assertSame('Vanela Gathiya', $r->line->name);
    }

    public function test_ambiguous_gathiya_asks_for_clarification(): void
    {
        $r = (new CandidateScorer())->rank('Gathiya', $this->catalogue(gathiyaDefault: false));
        $this->assertSame(ProductResolution::CLARIFY, $r->decision);
        $names = array_map(fn ($c) => $c['name'], $r->candidates);
        $this->assertContains('Vanela Gathiya', $names);
        $this->assertContains('Fafda Gathiya', $names);
    }

    public function test_misspelling_resolves(): void
    {
        $r = (new CandidateScorer())->rank('vanilaa', $this->catalogue());
        $this->assertSame(ProductResolution::AUTO, $r->decision);
        $this->assertSame(1, $r->line->productId);
    }

    public function test_unavailable_product_is_not_resolved(): void
    {
        $r = (new CandidateScorer())->rank('Khaman', [
            ['id' => 9, 'name' => 'Khaman', 'aliases' => ['khaman'], 'popularity' => 30, 'available' => false],
        ]);
        $this->assertSame(ProductResolution::NONE, $r->decision);   // availability is a gate
    }
}
