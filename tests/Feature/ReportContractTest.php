<?php

namespace Tests\Feature;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Inventory\StockLedger;
use App\Services\Money\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E7.1 — the Report definition contract. Proves the built-in reports produce rows by reading
 * ONLY SSOT services (CashService, StockLedger), so a report can never diverge from the rest of the
 * app, and that the registry resolves definitions by key.
 */
class ReportContractTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    public function test_cash_position_report_matches_cash_service(): void
    {
        $t = $this->tenant();
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'funding', 'currency' => 'USD',
            'native_amount' => 1000, 'rate_to_usd' => 1, 'usd_amount' => 1000, 'paid_native' => 1000,
            'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash']);
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'cash_out', 'currency' => 'USD',
            'native_amount' => 300, 'rate_to_usd' => 1, 'usd_amount' => 300, 'paid_native' => 300,
            'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash']);

        $report = ReportRegistry::default()->get('cash_position');
        $rows = $report->rows($t, ['currency' => 'USD']);

        $this->assertCount(1, $rows);
        $this->assertSame('USD', $rows[0]['currency']);
        // exactly what CashService (the SSOT) reports — 1000 - 300 = 700
        $this->assertSame(CashService::forTenant($t)->balances(['currency' => 'USD'])['USD'], $rows[0]['on_hand']);
        $this->assertSame(700.0, $rows[0]['on_hand']);
    }

    public function test_stock_on_hand_report_matches_stock_ledger(): void
    {
        $t = $this->tenant();
        $l = StockLedger::forTenant($t);
        $l->receive(['sku' => 'RICE', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 100]);
        $l->issue(['sku' => 'RICE', 'location' => StockLedger::LOC_KAMPALA, 'qty' => 40]);

        $report = ReportRegistry::default()->get('stock_on_hand');
        $rows = $report->rows($t, ['location' => StockLedger::LOC_KAMPALA]);

        $this->assertCount(1, $rows);
        $this->assertSame('RICE', $rows[0]['sku']);
        $this->assertSame(60.0, $rows[0]['qty']); // 100 - 40, straight from the projection
    }

    public function test_registry_resolves_and_describes(): void
    {
        $reg = ReportRegistry::default();
        $this->assertTrue($reg->has('cash_position'));
        $this->assertNull($reg->get('does_not_exist'));

        $describe = $reg->get('cash_position')->describe();
        $this->assertSame('cash_position', $describe['key']);
        $this->assertArrayHasKey('columns', $describe);
        $this->assertArrayHasKey('filters', $describe);
    }
}
