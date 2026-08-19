<?php declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\CatalogueMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The bot-quote ambiguity guard: a generic term matching several SKUs at very different prices must be
 * flagged (ask which one), while a specific / exact / clearly-led match still quotes normally.
 */
final class QuoteAmbiguityTest extends TestCase
{
    private CatalogueMatcher $m;

    protected function setUp(): void
    {
        $this->m = new CatalogueMatcher();
    }

    private function hit(string $name, float $price, float $score): array
    {
        return ['product' => ['name' => $name, 'price' => $price], 'score' => $score];
    }

    public function test_generic_term_with_wide_spread_is_ambiguous(): void
    {
        $foil = [
            $this->hit('Aluminium Foil 45CM*150/300 3KG Carton Of 6pc', 137, 245),
            $this->hit('ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC)', 33, 243),
            $this->hit('Fresh Wrap Aluminium Foil 150M*30cm', 155, 242),
        ];
        $amb = $this->m->ambiguityCandidates($foil);
        $this->assertNotNull($amb);
        $this->assertSame(33, (int) $amb[0]['price']);   // cheapest first
    }

    public function test_distinguishing_word_is_not_ambiguous(): void
    {
        $golden = [
            $this->hit('ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC)', 33, 365),
            $this->hit('Aluminium Foil 45CM*150/300 3KG', 137, 245),
        ];
        $this->assertNull($this->m->ambiguityCandidates($golden));
    }

    public function test_exact_name_match_is_not_ambiguous(): void
    {
        $exact = [$this->hit('ALUMINIUM FOIL 45CM GOLDEN', 33, 1243), $this->hit('Other Foil', 137, 243)];
        $this->assertNull($this->m->ambiguityCandidates($exact));
    }

    public function test_narrow_price_spread_is_not_ambiguous(): void
    {
        $narrow = [$this->hit('Brand A Sugar 50kg', 100, 245), $this->hit('Brand B Sugar 50kg', 118, 244)];
        $this->assertNull($this->m->ambiguityCandidates($narrow));
    }

    public function test_single_hit_is_not_ambiguous(): void
    {
        $this->assertNull($this->m->ambiguityCandidates([$this->hit('Only Foil', 33, 245)]));
    }

    public function test_clear_leader_is_not_ambiguous(): void
    {
        $rice = [$this->hit('Basmati Rice 25kg', 60, 495), $this->hit('Rice Crackers 200g', 3, 245)];
        $this->assertNull($this->m->ambiguityCandidates($rice));
    }
}
