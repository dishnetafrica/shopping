<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V3 Migration Constitution · Rule 4 — every NEW tenant begins life in CloudBSS V3. Proves the
 * universal default (`order_system_enabled=true`) is applied at creation regardless of the path, an
 * explicit choice is respected, and the existing trial/plan defaults still work.
 */
class V3DefaultTenantTest extends TestCase
{
    use RefreshDatabase;

    private function slug(): string
    {
        return 't-' . substr(md5(uniqid('', true)), 0, 10);
    }

    public function test_new_tenant_defaults_to_v3(): void
    {
        $t = Tenant::create(['name' => 'New Co', 'slug' => $this->slug()]);
        $this->assertTrue((bool) $t->setting('order_system_enabled', false), 'a new tenant should land in V3');
    }

    public function test_new_tenant_with_other_settings_still_gets_v3(): void
    {
        // A creation path that passes its own settings (e.g. the Operator admin) must still get V3.
        $t = Tenant::create(['name' => 'Op Co', 'slug' => $this->slug(), 'settings' => ['vertical' => 'solar']]);
        $this->assertTrue((bool) $t->setting('order_system_enabled', false));
        $this->assertSame('solar', $t->setting('vertical'), 'other settings are preserved');
    }

    public function test_explicit_choice_is_respected(): void
    {
        $t = Tenant::create(['name' => 'Legacy Co', 'slug' => $this->slug(), 'settings' => ['order_system_enabled' => false]]);
        $this->assertFalse((bool) $t->setting('order_system_enabled', true), 'an explicit opt-out is honored');
    }

    public function test_trial_and_plan_defaults_still_apply(): void
    {
        $t = Tenant::create(['name' => 'X Co', 'slug' => $this->slug()]);
        $this->assertSame('free', $t->plan);
        $this->assertNotNull($t->trial_ends_at);
    }
}
