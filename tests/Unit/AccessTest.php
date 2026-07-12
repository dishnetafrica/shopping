<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\Access;
use Tests\TestCase;

/**
 * Roadmap E2.3 — the action gate. Verifies each action allows exactly the intended roles,
 * owner does everything, unknown actions/users fail closed. Pure logic, no DB.
 */
class AccessTest extends TestCase
{
    private function user(string $role): User
    {
        return new User(['role' => $role]);
    }

    public function test_owner_and_admin_may_do_everything(): void
    {
        foreach (Access::actions() as $action) {
            $this->assertTrue(Access::allows($this->user('owner'), $action), "owner should be allowed: $action");
            $this->assertTrue(Access::allows($this->user('admin'), $action), "admin should be allowed: $action");
        }
    }

    public function test_matrix_roles_are_allowed_and_others_denied(): void
    {
        foreach (Access::MATRIX as $action => $roles) {
            foreach ($roles as $role) {
                $this->assertTrue(Access::allows($this->user($role), $action), "$role should be allowed: $action");
            }
            // a role never in any list must be denied
            $this->assertFalse(Access::allows($this->user('staff'), $action), "staff must be denied: $action");
        }
    }

    public function test_representative_denials(): void
    {
        $this->assertTrue(Access::allows($this->user('cashier'), 'payment.record'));
        $this->assertFalse(Access::allows($this->user('cashier'), 'supplier.pay'));
        $this->assertFalse(Access::allows($this->user('cashier'), 'staff.manage'));
        $this->assertFalse(Access::allows($this->user('cashier'), 'invoice.void'));

        $this->assertTrue(Access::allows($this->user('warehouse'), 'stock.adjust'));
        $this->assertTrue(Access::allows($this->user('warehouse'), 'shipment.manage'));
        $this->assertFalse(Access::allows($this->user('warehouse'), 'payment.record'));

        $this->assertTrue(Access::allows($this->user('procurement'), 'supplier.pay'));
        $this->assertFalse(Access::allows($this->user('procurement'), 'payment.void'));
    }

    public function test_owner_only_actions_deny_manager(): void
    {
        $this->assertFalse(Access::allows($this->user('manager'), 'settings.manage'));
        $this->assertFalse(Access::allows($this->user('manager'), 'modules.manage'));
        $this->assertTrue(Access::allows($this->user('owner'), 'settings.manage'));
        // manager still manages staff
        $this->assertTrue(Access::allows($this->user('manager'), 'staff.manage'));
    }

    public function test_unknown_action_and_null_user_fail_closed(): void
    {
        // A non-owner is denied an unknown action (fail closed)…
        $this->assertFalse(Access::allows($this->user('manager'), 'totally.unknown.action'));
        // …while owner still short-circuits to allowed (owner may do everything).
        $this->assertTrue(Access::allows($this->user('owner'), 'totally.unknown.action'));
        // No user => denied.
        $this->assertFalse(Access::allows(null, 'payment.record'));
    }

    /** E2.5 — module-flag semantics: unset = enabled, explicit false = disabled. */
    public function test_module_enabled_semantics(): void
    {
        $this->assertTrue(Access::moduleEnabled([], 'finance'));                 // unset => enabled
        $this->assertTrue(Access::moduleEnabled(['finance' => true], 'finance'));
        $this->assertFalse(Access::moduleEnabled(['finance' => false], 'finance'));
    }

    /** E2.5 — a disabled module blocks its actions for EVERYONE, owner included, flag off. */
    public function test_disabled_module_blocks_even_owner(): void
    {
        $t = new \App\Models\Tenant();
        $t->settings = ['modules' => ['purchases' => false]]; // perm_enforce not set

        $owner = new User(['role' => 'owner']);
        $owner->setRelation('tenant', $t);

        // supplier.pay is in the (disabled) purchases module → blocked even for owner
        $this->assertTrue(Access::blocks($owner, 'supplier.pay'));
        // an action in an enabled (default) module is not blocked
        $this->assertFalse(Access::blocks($owner, 'payment.record'));
    }

    /** E2.4 — blocks() only bites when the tenant enables perm_enforce. */
    public function test_blocks_only_enforces_when_flag_on(): void
    {
        $on  = new \App\Models\Tenant();  $on->settings  = ['perm_enforce' => true];
        $off = new \App\Models\Tenant();  $off->settings = [];

        $cashierOn = new User(['role' => 'cashier']);  $cashierOn->setRelation('tenant', $on);
        $cashierOff = new User(['role' => 'cashier']); $cashierOff->setRelation('tenant', $off);
        $ownerOn = new User(['role' => 'owner']);       $ownerOn->setRelation('tenant', $on);

        // flag ON: disallowed action blocked, allowed action not blocked
        $this->assertTrue(Access::blocks($cashierOn, 'supplier.pay'));
        $this->assertFalse(Access::blocks($cashierOn, 'payment.record'));
        // owner never blocked
        $this->assertFalse(Access::blocks($ownerOn, 'supplier.pay'));
        // flag OFF: never blocks (no behaviour change)
        $this->assertFalse(Access::blocks($cashierOff, 'supplier.pay'));
        // no user => not blocked (endpoint's own auth handles it)
        $this->assertFalse(Access::blocks(null, 'supplier.pay'));
    }
}
