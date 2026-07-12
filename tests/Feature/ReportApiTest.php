<?php

namespace Tests\Feature;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E7.2 — the generic report endpoint. Lists reports, resolves one by key, validates filters
 * against the definition, and returns rows the definition computed from SSOT services. Unknown keys
 * 404; non-finance roles are forbidden.
 */
class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $role = 'owner'): array
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => $role, 'name' => 'U']);
        $this->actingAs($u);
        return [$t, $u];
    }

    public function test_index_lists_reports(): void
    {
        $this->tenant();
        $keys = collect($this->getJson('/papi/reports')->assertOk()->json('reports'))->pluck('key')->all();
        $this->assertContains('cash_position', $keys);
        $this->assertContains('stock_on_hand', $keys);
    }

    public function test_show_returns_columns_and_rows_from_ssot(): void
    {
        [$t] = $this->tenant();
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'funding', 'currency' => 'USD',
            'native_amount' => 1000, 'rate_to_usd' => 1, 'usd_amount' => 1000, 'paid_native' => 1000,
            'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash']);
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'cash_out', 'currency' => 'USD',
            'native_amount' => 300, 'rate_to_usd' => 1, 'usd_amount' => 300, 'paid_native' => 300,
            'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash']);

        $body = $this->getJson('/papi/reports/cash_position?currency=USD')->assertOk()->json();
        $this->assertSame('cash_position', $body['key']);
        $this->assertSame('currency', $body['columns'][0]['key']);
        $this->assertCount(1, $body['rows']);
        $this->assertSame(700.0, $body['rows'][0]['on_hand']);
        $this->assertSame(CashService::forTenant($t)->balances(['currency' => 'USD'])['USD'], $body['rows'][0]['on_hand']);
    }

    public function test_invalid_select_filter_is_ignored(): void
    {
        [$t] = $this->tenant();
        // an out-of-options currency is dropped, not passed to the SSOT service
        $body = $this->getJson('/papi/reports/cash_position?currency=ZZZ')->assertOk()->json();
        $this->assertArrayNotHasKey('currency', $body['applied']);
    }

    public function test_unknown_report_is_404(): void
    {
        $this->tenant();
        $this->getJson('/papi/reports/nope')->assertStatus(404);
    }

    public function test_non_finance_role_forbidden(): void
    {
        $this->tenant('sales');
        $this->getJson('/papi/reports')->assertStatus(403);
        $this->getJson('/papi/reports/cash_position')->assertStatus(403);
    }
}
