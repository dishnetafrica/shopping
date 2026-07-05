<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\Support\Confidence;
use PHPUnit\Framework\TestCase;

/**
 * The "did you mean?" gate depends on this scorer behaving predictably.
 * Pure unit test — no DB / no app boot.
 */
class ConfidenceTest extends TestCase
{
    public function test_strong_name_match_scores_high(): void
    {
        $s = Confidence::score('tp-link router', 'TP-Link Archer C6 AC1200 Router');
        $this->assertGreaterThanOrEqual(0.60, $s);
    }

    public function test_unrelated_product_scores_low(): void
    {
        $s = Confidence::score('washing machine', 'TP-Link Archer C6 Router');
        $this->assertLessThan(0.30, $s);
    }

    public function test_stopwords_and_units_do_not_inflate_score(): void
    {
        // "i want to buy a" are stopwords; only "kettle" should count.
        $s = Confidence::score('i want to buy a kettle please', 'Ramtons 1.7L Electric Kettle');
        $this->assertGreaterThanOrEqual(0.60, $s);
    }

    public function test_plurals_and_stems_match(): void
    {
        $this->assertGreaterThanOrEqual(0.60, Confidence::score('charger', 'Fast Chargers 20W'));
        $this->assertGreaterThanOrEqual(0.60, Confidence::score('cable', 'USB-C Cables 1m'));
    }

    public function test_classify_buckets(): void
    {
        $this->assertSame('high', Confidence::classify(0.70, 0.60, 0.30));
        $this->assertSame('medium', Confidence::classify(0.45, 0.60, 0.30));
        $this->assertSame('low', Confidence::classify(0.10, 0.60, 0.30));
    }

    public function test_empty_inputs_score_zero(): void
    {
        $this->assertSame(0.0, Confidence::score('', 'Anything'));
        $this->assertSame(0.0, Confidence::score('anything', ''));
    }
}
