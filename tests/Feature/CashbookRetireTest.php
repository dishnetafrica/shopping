<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E5.4 — retire the second (ledger_entries) cashbook after the E4 payment cutover.
 *
 * With pay_unify ON, every receipt lives in the ONE Cashbook (trade-cashbook), so the legacy
 * /panel/cashbook screen is redundant and redirects there. With pay_unify OFF (pre-cutover, the
 * default) order payments still live only in ledger_entries, so the legacy screen is kept.
 */
class CashbookRetireTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(bool $payUnify): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('pay_unify', $payUnify);
        // the target Cashbook (trade-cashbook) requires order_system_enabled; enable it so the
        // redirect condition can be exercised (E5.4 only redirects when the target is reachable).
        $t->putSetting('order_system_enabled', true);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    public function test_pay_unify_on_redirects_to_the_one_cashbook(): void
    {
        $this->actAs(true);
        $this->get('/panel/cashbook')->assertRedirect('/panel/trade-cashbook');
    }

    public function test_pay_unify_off_keeps_the_legacy_screen(): void
    {
        $this->actAs(false);
        $res = $this->get('/panel/cashbook');
        $res->assertOk();
        // it is NOT the redirect — the legacy asset is still served
        $this->assertNull($res->headers->get('location'));
    }
}
