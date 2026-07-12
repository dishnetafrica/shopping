<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E8.4 — Invoices + Payments server list endpoints in the shared list contract shape
 * (rows/total/pages/aggregates), so the DataTable renders them. New endpoints; legacy screens
 * untouched. Search / filter / sort / paging / aggregates go through the one ListBuilder engine.
 */
class RegisterTableTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $role = 'owner'): Tenant
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => $role, 'name' => 'U']);
        $this->actingAs($u);
        return $t;
    }

    public function test_invoices_table_shape_search_and_sort(): void
    {
        $t = $this->tenant();
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-1', 'customer_name' => 'Ann',
            'currency' => 'USD', 'total' => 100, 'amount_paid' => 0, 'balance' => 100, 'status' => 'issued', 'issued_at' => now()->subDay()]);
        Invoice::create(['tenant_id' => $t->id, 'invoice_no' => 'INV-2', 'customer_name' => 'Bob',
            'currency' => 'USD', 'total' => 300, 'amount_paid' => 0, 'balance' => 300, 'status' => 'issued', 'issued_at' => now()]);

        $body = $this->getJson('/papi/invoices/table?q=Ann')->assertOk()->json();
        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['total']);
        $this->assertSame('INV-1', $body['rows'][0]['invoice_no']);
        $this->assertArrayHasKey('pages', $body);

        // sort by total desc → INV-2 first
        $sorted = $this->getJson('/papi/invoices/table?sort=total&dir=desc')->assertOk()->json();
        $this->assertSame('INV-2', $sorted['rows'][0]['invoice_no']);
    }

    public function test_payments_table_filter_and_aggregate(): void
    {
        $t = $this->tenant();
        CustomerPayment::create(['tenant_id' => $t->id, 'receipt_no' => 'RCP-1', 'customer_name' => 'Ann',
            'kind' => 'settlement', 'currency' => 'USD', 'amount' => 100, 'rate_to_usd' => 1, 'usd_amount' => 100, 'method' => 'Cash', 'applied' => true]);
        CustomerPayment::create(['tenant_id' => $t->id, 'receipt_no' => 'RCP-2', 'customer_name' => 'Bob',
            'kind' => 'advance', 'currency' => 'USD', 'amount' => 40, 'rate_to_usd' => 1, 'usd_amount' => 40, 'method' => 'MoMo', 'applied' => false]);

        $all = $this->getJson('/papi/payments/table')->assertOk()->json();
        $this->assertSame(2, $all['total']);
        $this->assertSame(140.0, $all['aggregates']['total_usd']); // 100 + 40

        $settle = $this->getJson('/papi/payments/table?filter[kind]=settlement')->assertOk()->json();
        $this->assertSame(1, $settle['total']);
        $this->assertSame(100.0, $settle['aggregates']['total_usd']);
    }

    public function test_paging(): void
    {
        $t = $this->tenant();
        for ($i = 1; $i <= 5; $i++) {
            CustomerPayment::create(['tenant_id' => $t->id, 'receipt_no' => 'RCP-' . $i, 'customer_name' => 'C',
                'kind' => 'settlement', 'currency' => 'USD', 'amount' => 10, 'rate_to_usd' => 1, 'usd_amount' => 10, 'method' => 'Cash', 'applied' => true]);
        }
        $p = $this->getJson('/papi/payments/table?per_page=2&page=1')->assertOk()->json();
        $this->assertSame(5, $p['total']);
        $this->assertSame(3, $p['pages']);
        $this->assertCount(2, $p['rows']);
    }

    public function test_non_finance_forbidden(): void
    {
        $this->tenant('sales');
        $this->getJson('/papi/invoices/table')->assertStatus(403);
        $this->getJson('/papi/payments/table')->assertStatus(403);
    }
}
