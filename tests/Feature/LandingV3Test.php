<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase C — everyone lands on /v3; v3_all_roles=false restores the staff tap-tile home. */
class LandingV3Test extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $settings = []): User
    {
        $t = Tenant::factory()->create();
        $t->putSetting('order_system_enabled', true);
        foreach ($settings as $k => $v) $t->putSetting($k, $v);
        app(\App\Support\TenantContext::class)->set($t->id);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => $role]);
        $this->actingAs($u);
        return $u;
    }

    public function test_purchaser_lands_on_v3_by_default(): void
    {
        $this->user('purchaser');
        $this->get('/panel')->assertRedirect('/v3');
        $this->get('/panel/m')->assertRedirect('/v3');
    }

    public function test_flag_off_restores_the_classic_staff_home(): void
    {
        $this->user('field', ['v3_all_roles' => false]);
        $this->get('/panel')->assertRedirect('/panel/app');
        $this->get('/panel/m')->assertRedirect('/panel/app');
    }

    public function test_owner_still_lands_on_v3_and_classic_escape_works(): void
    {
        $this->user('owner');
        $this->get('/panel')->assertRedirect('/v3');
        $this->get('/panel?classic=1')->assertOk();   // classic stays reachable until Phase D
    }

    public function test_purchaser_can_open_cashbook_workspace(): void
    {
        // The Phase C blocker: cashbook was gated menu:finance; purchaser holds only cash.
        $this->user('purchaser');
        $this->getJson('/api/v1/workspace/cashbook')->assertOk();
        $this->getJson('/api/v1/workspace/salesOrders')->assertOk();
    }

    public function test_sales_role_still_has_no_cashbook(): void
    {
        $this->user('sales');
        $this->getJson('/api/v1/workspace/cashbook')->assertStatus(403);
    }
}
