<?php

namespace Tests\Feature\Capability;

use App\Http\Middleware\ClassicPanelRedirects;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v3 retire parity audit 2026-07-18 — the redirect targets must DO what classic did. */
class ParityFixesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function login(string $role = 'owner'): User
    {
        $this->t = Tenant::factory()->create();
        $this->t->putSetting('order_system_enabled', true);
        app(\App\Support\TenantContext::class)->set($this->t->id);
        $u = User::factory()->create(['tenant_id' => $this->t->id, 'role' => $role]);
        $this->actingAs($u);
        return $u;
    }

    public function test_finance_workspace_can_record_and_lists_expenses(): void
    {
        $u = $this->login();
        OrderCost::create([
            'tenant_id' => $this->t->id, 'stage' => 'expense', 'expense_category' => 'Rent',
            'currency' => 'UGX', 'native_amount' => 500000, 'rate_to_usd' => 0.00027,
            'usd_amount' => 135, 'holder' => $u->name, 'status' => 'approved', 'created_by' => $u->name,
        ]);

        $j = $this->getJson('/api/v1/workspace/finance')->assertOk()->json();

        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/expenses', $acts['record_expense']['endpoint'],
            'expense recording must delegate to the classic engine');
        $this->assertStringContainsString('Rent', json_encode($j['table']['rows']));
    }

    public function test_purchasing_workspace_offers_approve_reject_for_pending_costs(): void
    {
        $this->login();   // owner has menu:approvals
        $c = OrderCost::create([
            'tenant_id' => $this->t->id, 'stage' => 'purchase', 'expense_category' => 'Stock',
            'currency' => 'UGX', 'native_amount' => 900000, 'rate_to_usd' => 0.00027,
            'usd_amount' => 243, 'holder' => 'Jayesh', 'status' => 'pending', 'created_by' => 'Jayesh',
        ]);

        $j = $this->getJson('/api/v1/workspace/purchase.process')->assertOk()->json();

        $kpi = collect($j['financials'])->keyBy('key');
        $this->assertSame('1', $kpi['approvals']['value']);
        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/order-costs/'.$c->id.'/decide', $acts['appr_'.$c->id]['endpoint']);
        $this->assertSame(['action' => 'approve'], $acts['appr_'.$c->id]['body']);
        $this->assertArrayHasKey('rej_'.$c->id, $acts);
    }

    public function test_purchaser_does_not_see_approval_actions(): void
    {
        $this->login('purchaser');   // cannot approve own spend
        OrderCost::create([
            'tenant_id' => $this->t->id, 'stage' => 'purchase', 'currency' => 'UGX',
            'native_amount' => 100, 'rate_to_usd' => 1, 'usd_amount' => 100,
            'status' => 'pending', 'created_by' => 'Jayesh',
        ]);
        $j = $this->getJson('/api/v1/workspace/purchase.process')->assertOk()->json();
        $this->assertFalse(collect($j['actions'])->pluck('key')->contains(fn ($k) => str_starts_with($k, 'appr_')));
    }

    public function test_consignment_and_scheduled_stay_classic(): void
    {
        $this->assertArrayNotHasKey('/panel/consignment', ClassicPanelRedirects::MAP,
            'consignment is a rich tool with no v3 port — must not redirect');
        $this->assertArrayNotHasKey('/panel/scheduled', ClassicPanelRedirects::MAP,
            'scheduled = scheduled ORDERS, not campaigns — must not redirect');
        $this->login('owner');
        $this->t->putSetting('v3_redirects', true);
        $this->get('/panel/consignment')->assertOk();
        $this->get('/panel/scheduled')->assertOk();
    }
}
