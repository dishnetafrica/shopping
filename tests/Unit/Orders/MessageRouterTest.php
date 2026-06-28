<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\MessageRouter;
use PHPUnit\Framework\TestCase;

/** Cases drawn from the real Pal's corpus. */
class MessageRouterTest extends TestCase
{
    private MessageRouter $r;
    protected function setUp(): void { $this->r = new MessageRouter(); }

    public function test_social_and_questions_do_not_reach_the_order_parser(): void
    {
        $this->assertSame(MessageRouter::GREETING, $this->r->route('Ok'));
        $this->assertSame(MessageRouter::GREETING, $this->r->route('Hello'));
        $this->assertSame(MessageRouter::GREETING, $this->r->route('JSK'));
        $this->assertSame(MessageRouter::QUESTION, $this->r->route('Platter price?'));
        $this->assertSame(MessageRouter::QUESTION, $this->r->route('Almond 250gms ketla tya'));  // price Q, not order
    }

    public function test_real_orders_route_to_order(): void
    {
        $this->assertSame(MessageRouter::ORDER, $this->r->route('250g khaman'));
        $this->assertSame(MessageRouter::ORDER, $this->r->route('2pkt sev 2pkt chakri'));
        $this->assertSame(MessageRouter::ORDER, $this->r->route('I want kaju 2 kg'));
        $this->assertSame(MessageRouter::ORDER_UPDATE, $this->r->route('Nathi lavanu'));          // removal
    }

    public function test_delivery_and_payment(): void
    {
        $this->assertSame(MessageRouter::PAYMENT, $this->r->route('Mobile money'));
        $this->assertSame(MessageRouter::DELIVERY, $this->r->route('Please arrange delivery'));
    }
}
