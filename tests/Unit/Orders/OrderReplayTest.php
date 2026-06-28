<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\DraftMerge;
use App\Apps\Orders\Extract\EntityExtractor;
use App\Apps\Orders\MissingInfoDetector;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests built from REAL WhatsApp conversations observed in production. The milestone:
 * the Order Engine must reconstruct each of these correctly. New production failures should be
 * added here as new cases.
 */
class OrderReplayTest extends TestCase
{
    private EntityExtractor $ex;
    private DraftMerge $merge;
    protected function setUp(): void { $this->ex = new EntityExtractor(); $this->merge = new DraftMerge(); }

    private function draftFrom(array $messages, ?string $seedPhone = null): DraftOrder
    {
        $draft = new DraftOrder(phone: $seedPhone);   // phone seeded from the WhatsApp sender
        foreach ($messages as $i => $msg) {
            [$draft] = $this->merge->apply($draft, $this->ex->extract($msg), 'whatsapp', $i + 1);
        }
        return $draft;
    }

    /** Conversation A — "Vanela" should be captured as a product line (alias→Vanela Gathiya is ProductResolver/Phase D). */
    public function test_conversation_a_vanela_is_captured_as_product(): void
    {
        $draft = $this->draftFrom(['Vanela']);
        $this->assertCount(1, $draft->lines);
        $this->assertStringContainsStringIgnoringCase('vanela', $draft->lines[0]->name);
    }

    /** Conversation B — the 1kg bug: weights must be exactly as ordered, four lines. */
    public function test_conversation_b_weights_are_correct_not_1kg(): void
    {
        $draft = $this->draftFrom(["300g khaman\n200g gathiya\n200g fafda\n100g jalebi"]);
        $this->assertCount(4, $draft->lines);
        $weights = array_map(fn ($l) => $l->weightGrams, $draft->lines);
        $this->assertSame([300, 200, 200, 100], $weights);   // not [1000,1000,1000,1000]
        $names = implode(' ', array_map(fn ($l) => mb_strtolower($l->name), $draft->lines));
        foreach (['khaman', 'gathiya', 'fafda', 'jalebi'] as $n) $this->assertStringContainsString($n, $names);
        // variant_id / unit_price / line_total are filled by ProductResolver (Phase D) — asserted there.
    }

    /** Conversation C — an address must never become a product lookup. */
    public function test_conversation_c_address_not_product(): void
    {
        $draft = $this->draftFrom(['503 Silver Condos']);
        $this->assertNotNull($draft->address);
        $this->assertCount(0, $draft->lines);
    }

    /** Conversation D — with phone known from WhatsApp, only the address should be missing. */
    public function test_conversation_d_missing_is_address_only(): void
    {
        $draft = $this->draftFrom(
            ["250g fafda\n250g vanela\nTomorrow 8AM\nMobile Money"],
            seedPhone: '256772123456',
        );
        $missing = (new MissingInfoDetector())->evaluate($draft);
        $this->assertSame(['address'], $missing['missing']);
        $this->assertSame('address', $missing['next']);
        $this->assertSame('CRITICAL', $missing['priorities']['address']);
    }
}
