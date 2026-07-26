<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Advisor\ProposalBuilder;
use Tests\TestCase;

/**
 * Project Advisor — proposal assembly (no DB). Proves a captured project + equipment + segment template
 * assemble into the eleven standard sections in order, with the executive summary drawn from the sizing,
 * requirements from the answers, correct commercial maths (subtotal + install + VAT), specs grouped per
 * product, and the equipment mapped to quotation line items for the quote-engine reuse.
 */
class ProposalBuilderTest extends TestCase
{
    private function project(): array
    {
        return [
            'name' => 'Nakawa home backup', 'profile' => 'solar',
            'answers' => [
                'customer_type' => 'Residential', 'project_objective' => 'Backup during outages',
                'location' => 'Central (Kampala)', 'existing_power_source' => 'Grid', 'backup_hours' => 8,
                'future_expansion' => 'Some (20%)', 'budget' => 3000000,
            ],
            'sizing' => [
                'result' => [
                    'connected_load_w' => 300, 'critical_load_w' => 290, 'daily_energy_wh' => 1830,
                    'daily_energy_kwh' => 1.83, 'peak_demand_w' => 300, 'pv_array_w' => 585.6,
                    'panel_count' => 2, 'battery_bank_wh' => 3866.67, 'battery_bank_ah' => 161.11,
                    'inverter_w' => 375, 'inverter_va' => 468.75,
                ],
                'assumptions' => ['system_efficiency' => 0.75, 'depth_of_discharge' => 0.8, 'system_voltage' => 24, 'safety_margin' => 1.25],
            ],
        ];
    }

    private function equipment(): array
    {
        return [
            ['name' => '300W Solar Power Station', 'sku' => 'GSL-SOLAR-300W', 'qty' => 1, 'unit_price' => 740000,
                'warranty' => '12-month manufacturer warranty',
                'specs' => [['label' => 'Wattage', 'value' => '300', 'unit' => 'W'], ['label' => 'Battery Chemistry', 'value' => 'LiFePO4', 'unit' => '']]],
            ['name' => 'Solar Panel 550W', 'sku' => 'PANEL-550', 'qty' => 2, 'unit_price' => 450000],
        ];
    }

    public function test_all_eleven_sections_in_order(): void
    {
        $p = ProposalBuilder::build($this->project(), $this->equipment(), [], ['currency' => 'UGX']);
        $this->assertSame([
            'executive_summary', 'customer_requirements', 'recommended_solution', 'equipment_list',
            'technical_specifications', 'estimated_performance', 'commercial_offer', 'warranty',
            'assumptions', 'exclusions', 'terms',
        ], array_column($p['sections'], 'key'));
    }

    public function test_executive_summary_and_requirements(): void
    {
        $p = ProposalBuilder::build($this->project(), $this->equipment());
        $sec = fn ($k) => collect($p['sections'])->firstWhere('key', $k);

        $exec = $sec('executive_summary')['body'];
        $this->assertStringContainsString('0.59 kW', $exec);
        $this->assertStringContainsString('1.83 kWh', $exec);
        $this->assertStringContainsStringIgnoringCase('residential', $exec);

        $req = collect($sec('customer_requirements')['rows']);
        $this->assertTrue($req->contains(fn ($r) => $r['value'] === 'Residential'));
        $this->assertTrue($req->contains(fn ($r) => $r['label'] === 'Required backup' && $r['value'] === '8 hours'));
    }

    public function test_commercial_maths(): void
    {
        $p = ProposalBuilder::build($this->project(), $this->equipment(), [], ['install_fee' => 200000, 'vat_pct' => 18]);
        $eq = collect($p['sections'])->firstWhere('key', 'equipment_list');
        $co = collect($p['sections'])->firstWhere('key', 'commercial_offer');

        $this->assertEqualsWithDelta(1640000.0, $eq['subtotal'], 0.01);   // 740k + 2×450k
        $this->assertEqualsWithDelta(2171200.0, $co['total'], 0.01);      // (1.64m + 200k) × 1.18
        $vat = collect($co['rows'])->first(fn ($r) => str_starts_with($r['label'], 'VAT'));
        $this->assertEqualsWithDelta(331200.0, $vat['value'], 0.01);
    }

    public function test_specs_grouped_and_quotation_lines_mapped(): void
    {
        $p = ProposalBuilder::build($this->project(), $this->equipment());
        $groups = collect($p['sections'])->firstWhere('key', 'technical_specifications')['groups'];
        $this->assertCount(1, $groups);                                   // only item 1 has specs
        $this->assertSame('300W Solar Power Station', $groups[0]['product']);
        $this->assertTrue(collect($groups[0]['specs'])->contains(fn ($s) => $s['value'] === '300 W'));

        $lines = ProposalBuilder::toQuotationLines($this->equipment());
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(740000.0, $lines[0]['unit_price'], 0.01);
    }

    public function test_segment_template_applies(): void
    {
        $proj = $this->project();
        $proj['answers']['customer_type'] = 'Government';
        $p = ProposalBuilder::build($proj, $this->equipment());
        $terms = collect($p['sections'])->firstWhere('key', 'terms')['items'];
        $this->assertTrue(collect($terms)->contains(fn ($t) => str_contains($t, 'procurement')));
    }
}
