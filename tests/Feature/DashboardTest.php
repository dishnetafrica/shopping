<?php

namespace Tests\Feature;

use App\Dashboard\DashboardRegistry;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E10.1 — the role dashboard engine. GET /papi/dashboard returns the widgets for the user's
 * role, each computed from SSOT services (so a widget can't diverge from the app). Different roles
 * get different widget sets.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(string $role): array
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => $role, 'name' => 'U']);
        $this->actingAs($u);
        return [$t, $u];
    }

    public function test_owner_dashboard_widgets_and_cash_matches_ssot(): void
    {
        [$t] = $this->tenantUser('owner');
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'funding', 'currency' => 'USD',
            'native_amount' => 1000, 'rate_to_usd' => 1, 'usd_amount' => 1000, 'paid_native' => 1000,
            'status' => 'approved', 'holder' => 'U', 'pay_status' => 'cash']);

        $body = $this->getJson('/papi/dashboard')->assertOk()->json();
        $this->assertSame('owner', $body['role']);
        $keys = collect($body['widgets'])->pluck('key')->all();
        $this->assertContains('cash_on_hand', $keys);
        $this->assertContains('open_invoices', $keys);
        $this->assertContains('net_profit', $keys);

        $cash = collect($body['widgets'])->firstWhere('key', 'cash_on_hand');
        $this->assertSame(
            CashService::forTenant($t)->balances()['USD'],
            $cash['value']['balances']['USD']
        );
        $this->assertSame(1000.0, $cash['value']['balances']['USD']);
    }

    public function test_open_invoices_widget_counts_and_converts(): void
    {
        [$t] = $this->tenantUser('owner');
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-1', 'customer_name' => 'A',
            'currency' => 'USD', 'total' => 100, 'amount_paid' => 0, 'balance' => 100, 'status' => 'issued', 'issued_at' => now()]);
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-2', 'customer_name' => 'B',
            'currency' => 'USD', 'total' => 50, 'amount_paid' => 50, 'balance' => 0, 'status' => 'paid', 'issued_at' => now()]);

        $body = $this->getJson('/papi/dashboard')->assertOk()->json();
        $open = collect($body['widgets'])->firstWhere('key', 'open_invoices');
        $this->assertSame(1, $open['value']['count']); // only the unpaid one
        $this->assertSame(100.0, $open['value']['balance_usd']);
    }

    public function test_cashier_sees_cashier_widgets_only(): void
    {
        [$t, $u] = $this->tenantUser('cashier');
        CustomerPayment::create(['tenant_id' => $t->id, 'receipt_no' => 'RCP-1', 'customer_name' => 'A',
            'kind' => 'settlement', 'currency' => 'USD', 'amount' => 60, 'rate_to_usd' => 1, 'usd_amount' => 60,
            'method' => 'Cash', 'applied' => true, 'holder' => 'U', 'holder_user_id' => $u->id]);

        $body = $this->getJson('/papi/dashboard')->assertOk()->json();
        $keys = collect($body['widgets'])->pluck('key')->all();
        $this->assertSame(['cash_on_hand', 'payments_today'], $keys);

        $pay = collect($body['widgets'])->firstWhere('key', 'payments_today');
        $this->assertSame(1, $pay['value']['count']);
        $this->assertSame(60.0, $pay['value']['usd']);
    }

    public function test_warehouse_role_sees_low_stock(): void
    {
        $this->tenantUser('warehouse');
        $keys = collect($this->getJson('/papi/dashboard')->assertOk()->json('widgets'))->pluck('key')->all();
        $this->assertSame(['low_stock'], $keys);
    }

    public function test_registry_role_map(): void
    {
        $reg = DashboardRegistry::default();
        $this->assertNotEmpty($reg->forRole('owner'));
        // unknown role falls back to default (open_invoices)
        $keys = array_map(fn ($w) => $w->key(), $reg->forRole('nope'));
        $this->assertSame(['open_invoices'], $keys);
    }
}
