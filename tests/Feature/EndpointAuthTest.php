<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E2.4 — server-side enforcement of the action gate on the previously-open money/data
 * endpoints. Asserts the guard returns 403 for a disallowed role when the tenant has opted into
 * enforcement (perm_enforce), and does NOT block allowed roles or any role when the flag is off.
 *
 * We assert 403 vs not-403 (not the downstream success status), so the test isolates the guard.
 */
class EndpointAuthTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $enforce): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('perm_enforce', $enforce);
        return $t;
    }

    private function actingAsRole(Tenant $t, string $role): User
    {
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => $role]);
        $this->actingAs($u);
        return $u;
    }

    public function test_enforced_disallowed_role_is_forbidden_on_staff_list(): void
    {
        $t = $this->tenant(true);
        $this->actingAsRole($t, 'cashier'); // cashier has no staff.manage
        $this->getJson('/papi/staff')->assertStatus(403);
    }

    public function test_enforced_disallowed_role_is_forbidden_on_payments(): void
    {
        $t = $this->tenant(true);
        $this->actingAsRole($t, 'warehouse'); // warehouse has no payment.record
        $this->postJson('/papi/payments', [])->assertStatus(403);
    }

    public function test_enforced_allowed_role_is_not_forbidden_on_payments(): void
    {
        $t = $this->tenant(true);
        $this->actingAsRole($t, 'cashier'); // cashier HAS payment.record
        $this->assertNotSame(403, $this->postJson('/papi/payments', [])->status());
    }

    public function test_enforced_owner_is_not_forbidden_on_staff_list(): void
    {
        $t = $this->tenant(true);
        $this->actingAsRole($t, 'owner');
        $this->assertNotSame(403, $this->getJson('/papi/staff')->status());
    }

    public function test_flag_off_does_not_block_disallowed_role(): void
    {
        $t = $this->tenant(false); // enforcement OFF
        $this->actingAsRole($t, 'cashier');
        $this->assertNotSame(403, $this->getJson('/papi/staff')->status());
    }

    /** E2.5 — a disabled module forbids its endpoint even for owner, with perm_enforce off. */
    public function test_disabled_module_forbids_endpoint_even_for_owner(): void
    {
        $t = Tenant::factory()->create();
        $t->putSetting('modules', ['purchases' => false]); // disable purchasing; perm_enforce stays off
        $this->actingAsRole($t, 'owner');

        // supplier.pay belongs to the purchases module → 403 before any supplier lookup
        $this->postJson('/papi/suppliers/1/pay', [])->assertStatus(403);
    }
}
