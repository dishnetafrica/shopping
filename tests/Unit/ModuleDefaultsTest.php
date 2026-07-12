<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\ModuleDefaults;
use Tests\TestCase;

/**
 * Roadmap E1.4 — the module-flag backfill rule. Pure logic (no DB): builds unsaved Tenant
 * models with different settings and checks the resulting restaurant flag preserves features
 * for real restaurant/grocery tenants while clearing the default clutter for trading tenants.
 */
class ModuleDefaultsTest extends TestCase
{
    private function tenant(array $settings): Tenant
    {
        $t = new Tenant();
        $t->settings = $settings;
        return $t;
    }

    public function test_standard_modules_are_always_enabled(): void
    {
        $map = ModuleDefaults::forTenant($this->tenant([]));
        foreach (ModuleDefaults::STANDARD as $m) {
            $this->assertTrue($map[$m], "$m should default enabled");
        }
    }

    public function test_trading_tenant_gets_restaurant_off_by_default(): void
    {
        // Wholesale trading business, no explicit restaurant signal (defaults to grocery).
        $map = ModuleDefaults::forTenant($this->tenant(['order_system_enabled' => true]));
        $this->assertFalse($map['restaurant'], 'trading tenant should not get restaurant clutter');
    }

    public function test_grocery_shop_keeps_restaurant_features(): void
    {
        // Non-trading grocery shop (default vertical) currently sees POS/Riders → must keep them.
        $map = ModuleDefaults::forTenant($this->tenant([]));
        $this->assertTrue($map['restaurant'], 'a grocery shop must not lose POS/Riders');
    }

    public function test_explicit_restaurant_vertical_enables_module(): void
    {
        $map = ModuleDefaults::forTenant($this->tenant(['vertical' => 'restaurant']));
        $this->assertTrue($map['restaurant']);
    }

    public function test_trading_tenant_with_thali_keeps_restaurant(): void
    {
        // Even a trading tenant that explicitly configured a thali keeps the module.
        $map = ModuleDefaults::forTenant($this->tenant([
            'order_system_enabled' => true,
            'feature_thali'        => true,
        ]));
        $this->assertTrue($map['restaurant']);
    }

    public function test_trading_tenant_with_explicit_pos_override_keeps_restaurant(): void
    {
        $map = ModuleDefaults::forTenant($this->tenant([
            'order_system_enabled' => true,
            'feature_pos'          => true,
        ]));
        $this->assertTrue($map['restaurant'], 'an explicit feature_pos=true must be preserved');
    }
}
