<?php

namespace Tests\Feature;

use App\Http\Controllers\Panel\NavController;
use App\Models\User;
use Tests\TestCase;

/**
 * Roadmap E1.1 — the single navigation config + builder.
 *
 * Verifies the grouped menu (Product Architecture v2 §1) is filtered correctly
 * by role (effectiveMenus) and tenant module flags, and that every screen
 * appears exactly once. Pure logic — no database.
 */
class NavConfigTest extends TestCase
{
    /** @param array<int,array> $groups */
    private function groupKeys(array $groups): array
    {
        return array_map(static fn ($g) => $g['key'], $groups);
    }

    public function test_owner_sees_every_group(): void
    {
        $groups = NavController::menuFor(User::allMenuKeys());
        $keys   = $this->groupKeys($groups);

        foreach (['home', 'sales', 'purchases', 'stock', 'money', 'reports', 'messaging', 'settings'] as $g) {
            $this->assertContains($g, $keys, "owner should see the '$g' group");
        }
    }

    public function test_every_route_appears_exactly_once(): void
    {
        $groups = NavController::menuFor(User::allMenuKeys());

        $routes = [];
        foreach ($groups as $grp) {
            foreach ($grp['items'] as $it) {
                if ($it['route'] !== '' && $it['route'] !== '/panel/setup') { // setup intentionally hosts two settings sub-items
                    $routes[] = $it['route'];
                }
            }
        }

        $this->assertSame(
            array_values(array_unique($routes)),
            $routes,
            'no screen route should appear twice in the menu'
        );
    }

    public function test_sales_role_sees_sales_group_only(): void
    {
        $menus  = (new User(['role' => 'sales']))->effectiveMenus();
        $groups = NavController::menuFor($menus);
        $keys   = $this->groupKeys($groups);

        // visible: Home + Sales
        $this->assertContains('home', $keys);
        $this->assertContains('sales', $keys);

        // hidden for a salesperson under the current role model
        $this->assertNotContains('purchases', $keys);
        $this->assertNotContains('stock', $keys);
        $this->assertNotContains('money', $keys);
        $this->assertNotContains('reports', $keys);
        $this->assertNotContains('settings', $keys);

        // Sales group shows Orders + Customers; Invoices needs 'finance' (not a sales menu yet)
        $sales    = collect($groups)->firstWhere('key', 'sales');
        $itemKeys = array_map(static fn ($i) => $i['key'], $sales['items']);
        $this->assertContains('orders', $itemKeys);
        $this->assertContains('customers', $itemKeys);
        $this->assertNotContains('invoices', $itemKeys);
    }

    public function test_disabled_module_hides_its_group(): void
    {
        $groups = NavController::menuFor(User::allMenuKeys(), ['inventory' => false]);
        $this->assertNotContains('stock', $this->groupKeys($groups));

        // an unset flag still shows the group (non-breaking default)
        $groups2 = NavController::menuFor(User::allMenuKeys(), []);
        $this->assertContains('stock', $this->groupKeys($groups2));
    }

    /** E1.5 — the menu must carry no duplicate item labels and no retired/merged screens. */
    public function test_menu_has_no_duplicate_or_retired_links(): void
    {
        $this->assertSame([], NavController::menuIssues(), 'nav config has duplicate labels or retired routes');
    }

    /** E5.3 — Cash Position / Staff Cash / My Cash are tabs of the ONE Cashbook screen. */
    public function test_cashbook_exposes_cash_subviews_as_tabs(): void
    {
        $groups = NavController::menuFor(User::allMenuKeys());
        $cashbook = null;
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                if ($it['key'] === 'cashbook') $cashbook = $it;
            }
        }

        $this->assertNotNull($cashbook, 'Cashbook item should be present');
        $this->assertArrayHasKey('tabs', $cashbook, 'Cashbook should expose sub-view tabs');

        $tabRoutes = array_map(static fn ($t) => $t['route'], $cashbook['tabs']);
        foreach (['/panel/trade-cashbook', '/panel/cash-position', '/panel/staff-cash', '/panel/my-cash'] as $r) {
            $this->assertContains($r, $tabRoutes, "Cashbook should have a tab for {$r}");
        }
    }

    /** E5.3 — the cash sub-views must NOT reappear as separate top-level menu links. */
    public function test_cash_subviews_are_retired_as_menu_links(): void
    {
        foreach (['/panel/cash-position', '/panel/staff-cash', '/panel/my-cash'] as $r) {
            $this->assertContains($r, NavController::RETIRED_ROUTES, "{$r} should be retired as a menu link");
        }

        // and none appear as a top-level item route in the built menu
        $groups = NavController::menuFor(User::allMenuKeys());
        $itemRoutes = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) $itemRoutes[] = $it['route'];
        }
        foreach (['/panel/cash-position', '/panel/staff-cash', '/panel/my-cash'] as $r) {
            $this->assertNotContains($r, $itemRoutes, "{$r} must not be a separate menu link");
        }

        // guard still clean with the new retired entries
        $this->assertSame([], NavController::menuIssues());
    }
}
