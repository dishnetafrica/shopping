<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Roadmap E2.1 — role → menu mapping.
 *
 * Locks the exact menu-key set each role resolves to (Product Architecture v2 §5), for the five
 * NEW roles and — as a regression guard — the existing roles that must not change. Pure logic,
 * no DB (effectiveMenus reads only $this->role / $this->allowed_menus).
 */
class RoleMenuTest extends TestCase
{
    private function menusFor(string $role): array
    {
        return (new User(['role' => $role]))->effectiveMenus();
    }

    /** @return array<string,array{0:string,1:array<int,string>}> */
    public static function roleSets(): array
    {
        return [
            // NEW roles (E2.1)
            'warehouse'    => ['warehouse',    ['dashboard', 'orders', 'ordercosts', 'products']],
            'procurement'  => ['procurement',  ['dashboard', 'orders', 'ordercosts', 'purchases', 'products', 'cash', 'finance']],
            'accountant'   => ['accountant',   ['dashboard', 'orders', 'customers', 'finance', 'cash', 'approvals']],
            'cashier'      => ['cashier',      ['dashboard', 'orders', 'customers', 'finance', 'cash']],
            'marketing'    => ['marketing',    ['dashboard', 'customers', 'whatsapp', 'marketing']],
            // EXISTING roles — regression lock (must stay exactly as before E2.1)
            'sales'        => ['sales',        ['dashboard', 'orders', 'quotations', 'customers', 'ordercosts']],
            'purchaser'    => ['purchaser',    ['dashboard', 'orders', 'ordercosts', 'purchases', 'cash']],
            'field'        => ['field',        ['dashboard', 'orders', 'ordercosts', 'purchases', 'cash']],
            'product_staff'=> ['product_staff',['dashboard', 'products']],
        ];
    }

    /** @dataProvider roleSets */
    public function test_role_resolves_exact_menu_set(string $role, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, $this->menusFor($role), "role '$role' menu set drifted");
    }

    public function test_new_roles_are_not_unrestricted(): void
    {
        $all = User::allMenuKeys();
        foreach (['warehouse', 'procurement', 'accountant', 'cashier', 'marketing'] as $role) {
            $this->assertNotEqualsCanonicalizing($all, $this->menusFor($role), "role '$role' must not see every menu");
        }
    }

    public function test_manager_sees_all_except_staff(): void
    {
        $expected = array_values(array_diff(User::allMenuKeys(), ['staff']));
        $this->assertEqualsCanonicalizing($expected, $this->menusFor('manager'));
    }

    public function test_owner_sees_everything(): void
    {
        $this->assertEqualsCanonicalizing(User::allMenuKeys(), (new User(['role' => 'owner']))->effectiveMenus());
    }

    public function test_unknown_role_unrestricted_when_perm_strict_off(): void
    {
        // Default (flag off) preserves today's behaviour — no regression.
        $this->assertEqualsCanonicalizing(User::allMenuKeys(), $this->menusFor('some_legacy_role'));
    }

    /** E2.2 — with perm_strict ON, an unknown/legacy role is denied by default. */
    public function test_unknown_role_minimal_when_perm_strict_on(): void
    {
        $t = new \App\Models\Tenant();
        $t->settings = ['perm_strict' => true];

        $u = new User(['role' => 'staff']);
        $u->setRelation('tenant', $t);

        $this->assertSame(['dashboard'], $u->effectiveMenus(), 'strict mode should deny by default');
        $this->assertNotContains('finance', $u->effectiveMenus(), 'staff must not see P&L/partners under strict mode');
        $this->assertFalse($u->canMenu('finance'));
    }

    /** A known role is unaffected by perm_strict (it never reaches the default case). */
    public function test_known_role_unaffected_by_perm_strict(): void
    {
        $t = new \App\Models\Tenant();
        $t->settings = ['perm_strict' => true];

        $u = new User(['role' => 'cashier']);
        $u->setRelation('tenant', $t);

        $this->assertEqualsCanonicalizing(
            ['dashboard', 'orders', 'customers', 'finance', 'cash'],
            $u->effectiveMenus()
        );
    }
}
