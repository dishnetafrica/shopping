<?php

namespace Tests\Feature;

use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Table\ListExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E8.2 — CSV + PDF export of the full filtered set. The exporter builds a label-headed CSV
 * and a dompdf table; the report endpoint returns them as downloads when ?format=csv|pdf.
 */
class ListExportTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        OrderCost::create(['tenant_id' => $t->id, 'stage' => 'funding', 'currency' => 'USD',
            'native_amount' => 1000, 'rate_to_usd' => 1, 'usd_amount' => 1000, 'paid_native' => 1000,
            'status' => 'approved', 'holder' => 'Ann', 'pay_status' => 'cash']);
        return $t;
    }

    public function test_exporter_csv_has_header_and_rows(): void
    {
        $csv = ListExporter::csv(
            [['key' => 'currency', 'label' => 'Currency'], ['key' => 'on_hand', 'label' => 'On hand']],
            [['currency' => 'USD', 'on_hand' => 700.0]]
        );
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Currency', $lines[0]);
        $this->assertStringContainsString('USD', $lines[1]);
        $this->assertStringContainsString('700', $lines[1]);
    }

    public function test_report_csv_export_endpoint(): void
    {
        $this->tenant();
        $res = $this->get('/papi/reports/cash_position?currency=USD&format=csv');
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Currency', $res->streamedContent() ?: $res->getContent());
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
    }

    public function test_report_pdf_export_endpoint_returns_pdf(): void
    {
        $this->tenant();
        $res = $this->get('/papi/reports/cash_position?format=pdf');
        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
        // real PDF bytes start with %PDF
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
