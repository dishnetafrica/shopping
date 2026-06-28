<?php
namespace Tests\Unit\Support;

use App\Support\OrderSla;
use App\Support\Vertical;
use PHPUnit\Framework\TestCase;

class OrderSlaTest extends TestCase
{
    public function test_manufacturer_thresholds_are_days_not_minutes(): void
    {
        // EuroPearl's "New 1d19h" (~2700m) and "Preparing 3d" (~4320m) must NOT be overdue.
        $this->assertGreaterThan(2700, OrderSla::staleMinutes(Vertical::MANUFACTURER, 'New'));
        $this->assertGreaterThan(4320, OrderSla::staleMinutes(Vertical::MANUFACTURER, 'Preparing'));
    }

    public function test_restaurant_thresholds_are_minutes(): void
    {
        $this->assertSame(15, OrderSla::staleMinutes(Vertical::RESTAURANT, 'New'));
        $this->assertTrue(OrderSla::isRealtime(Vertical::RESTAURANT));
        $this->assertFalse(OrderSla::isRealtime(Vertical::MANUFACTURER));
    }

    public function test_per_tenant_override_wins(): void
    {
        $this->assertSame(5, OrderSla::staleMinutes(Vertical::MANUFACTURER, 'New', ['New' => 5]));
    }

    public function test_active_and_dispatch_membership(): void
    {
        $this->assertTrue(OrderSla::isActive('Preparing'));
        $this->assertFalse(OrderSla::isActive('Delivered'));
        $this->assertTrue(OrderSla::isDispatch('Out for delivery'));
    }
}
