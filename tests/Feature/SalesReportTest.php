<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Money\Fx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E7.3 — the Sales report replaces the hard-coded-FX invoiced.html. It reads REAL invoices
 * and converts to USD through the Fx service (never a hard-coded 3750), so USD totals match the rest
 * of the app. The legacy /panel/invoiced screen is retired (redirects to Reports).
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        $t->putSetting('order_system_enabled', true);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        return $t;
    }

    public function test_sales_report_converts_via_fx_not_hardcoded(): void
    {
        $t = $this->tenant();
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-1', 'customer_name' => 'Ann',
            'currency' => 'UGX', 'total' => 3655000, 'amount_paid' => 0, 'balance' => 3655000,
            'status' => 'issued', 'issued_at' => now()]);

        $rows = ReportRegistry::default()->get('sales')->rows($t);
        $this->assertCount(1, $rows);

        // total_usd must equal the Fx conversion, NOT total/3750
        $expected = Fx::forTenant($t)->toUsd(3655000.0, 'UGX');
        $this->assertSame($expected, $rows[0]['total_usd']);
        $this->assertNotSame(round(3655000 / 3750, 2), $rows[0]['total_usd']); // not the legacy hard-coded rate
    }

    public function test_status_filter(): void
    {
        $t = $this->tenant();
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-A', 'customer_name' => 'A',
            'currency' => 'USD', 'total' => 100, 'amount_paid' => 0, 'balance' => 100, 'status' => 'issued', 'issued_at' => now()]);
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-B', 'customer_name' => 'B',
            'currency' => 'USD', 'total' => 50, 'amount_paid' => 0, 'balance' => 50, 'status' => 'void', 'issued_at' => now()]);

        $issued = ReportRegistry::default()->get('sales')->rows($t, ['status' => 'issued']);
        $this->assertCount(1, $issued);
        $this->assertSame('INV-A', $issued[0]['invoice_no']);
    }

    public function test_invoiced_screen_is_retired(): void
    {
        $this->tenant();
        $this->get('/panel/invoiced')->assertRedirect('/panel/pnl');
    }
}
