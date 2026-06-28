<?php
namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Classifier\DeterministicClassifier;
use App\Services\Knowledge\Intent;
use PHPUnit\Framework\TestCase;

class DeterministicClassifierTest extends TestCase
{
    private DeterministicClassifier $c;
    protected function setUp(): void { $this->c = new DeterministicClassifier(); }

    public function test_routes_common_intents(): void
    {
        $this->assertSame(Intent::PRICE, $this->c->classify('Tea 5000'));
        $this->assertSame(Intent::AVAILABILITY, $this->c->classify('Dhokla sold out'));
        $this->assertSame(Intent::MENU, $this->c->classify("Lunch:\n- Thali 25000"));
        $this->assertSame(Intent::SPECIAL, $this->c->classify('Today special Kathiyawadi Thali'));
        $this->assertSame(Intent::SCHEDULE, $this->c->classify('Closed tomorrow'));
        $this->assertSame(Intent::POLICY, $this->c->classify('Free delivery above 50000'));
        $this->assertSame(Intent::FACILITY, $this->c->classify('Parking available'));
        $this->assertSame(Intent::REPEAT_PREVIOUS, $this->c->classify('Same as yesterday'));
        $this->assertSame(Intent::NOTE, $this->c->classify('thanks bhai'));
    }
}
