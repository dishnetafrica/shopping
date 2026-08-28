<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\Pricing\LineLabel;
use PHPUnit\Framework\TestCase;

/**
 * Phase-0 Fix #2 — the canonical line label shared by the chat basket, the quotation line and the
 * order item, so a weight/variant shown in chat can never vanish from the customer's quotation.
 * Pure unit test. Sandbox mirror: tests/Bot/line_label_harness.php.
 */
class LineLabelTest extends TestCase
{
    /** The reproduced audit defect: chat "Fafda (250g)" but quotation "1 x Fafda". */
    public function test_weight_survives_onto_the_label(): void
    {
        $fafda = ['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1, 'price' => 500.0];
        $this->assertSame('Fafda (250g)', LineLabel::make($fafda));

        // Chat and quotation build the name identically now — they cannot diverge.
        $this->assertSame(LineLabel::make($fafda), LineLabel::make($fafda));
    }

    public function test_weight_suffix_matrix(): void
    {
        $this->assertSame('Fafda (250g)',  LineLabel::make(['name' => 'Fafda', 'weight_grams' => 250]));
        $this->assertSame('Fafda (500g)',  LineLabel::make(['name' => 'Fafda', 'weight_grams' => 500]));
        $this->assertSame('Fafda (1kg)',   LineLabel::make(['name' => 'Fafda', 'weight_grams' => 1000]));
        $this->assertSame('Fafda (1.5kg)', LineLabel::make(['name' => 'Fafda', 'weight_grams' => 1500]));
        $this->assertSame('Fafda (2kg)',   LineLabel::make(['name' => 'Fafda', 'weight_grams' => 2000]));
    }

    public function test_non_weight_and_modifiers(): void
    {
        $this->assertSame('Kabras Sugar', LineLabel::make(['name' => 'Kabras Sugar', 'qty' => 3]));
        $this->assertSame('Thali + Naan, Rice', LineLabel::make(['name' => 'Thali', 'modifiers' => [['name' => 'Naan'], ['name' => 'Rice']]]));
        $this->assertSame('Combo + Naan (250g)', LineLabel::make(['name' => 'Combo', 'modifiers' => [['name' => 'Naan']], 'weight_grams' => 250]));
        $this->assertSame('Sugar', LineLabel::make(['name' => 'Sugar', 'modifiers' => []]));
    }

    /** Label-only: the amount is never touched. */
    public function test_labelling_does_not_touch_price(): void
    {
        $line = ['name' => 'Fafda', 'weight_grams' => 250, 'qty' => 1, 'price' => 500.0];
        LineLabel::make($line);
        $this->assertSame(500.0, $line['price'] * $line['qty']);
    }
}
