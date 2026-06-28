<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Dto\CartLine;
use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\MissingInfoDetector;
use PHPUnit\Framework\TestCase;

class MissingInfoDetectorTest extends TestCase
{
    public function test_asks_only_for_the_smallest_missing_piece(): void
    {
        $draft = new DraftOrder(
            lines: [new CartLine('Fafda', weightGrams: 250)],
            address: 'Ntinda', deliveryTime: '08:00', paymentMethod: 'Mobile Money',
        );  // everything but phone
        $r = (new MissingInfoDetector())->evaluate($draft);
        $this->assertFalse($r['complete']);
        $this->assertSame('phone', $r['next']);
        $this->assertStringContainsStringIgnoringCase('phone', $r['question']);
    }

    public function test_complete_draft_has_no_question(): void
    {
        $draft = new DraftOrder(
            lines: [new CartLine('Fafda', weightGrams: 250)],
            address: 'Ntinda', deliveryTime: '08:00', paymentMethod: 'Cash', phone: '0772123456',
        );
        $this->assertTrue((new MissingInfoDetector())->evaluate($draft)['complete']);
    }
}
