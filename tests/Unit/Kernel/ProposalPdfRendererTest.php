<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Models\Tenant;
use App\Services\Advisor\ProposalPdfRenderer;
use Tests\TestCase;

/**
 * Project Advisor — proposal PDF HTML (no DB, no dompdf). Proves the branded document carries the tenant
 * identity, every proposal section, correct money formatting and the spec/warranty content — so the PDF
 * dompdf produces from it is complete.
 */
class ProposalPdfRendererTest extends TestCase
{
    private function proposal(): array
    {
        return [
            'currency' => 'UGX', 'title' => 'Nakawa home backup — Solar Proposal',
            'sections' => [
                ['key' => 'executive_summary', 'title' => 'Executive Summary', 'body' => 'We recommend a 0.59 kW solar system.'],
                ['key' => 'customer_requirements', 'title' => 'Customer Requirements', 'rows' => [['label' => 'Customer type', 'value' => 'Residential']]],
                ['key' => 'equipment_list', 'title' => 'Equipment List', 'items' => [['name' => '300W Station', 'sku' => 'GSL', 'qty' => 1, 'unit_price' => 740000, 'line_total' => 740000]], 'subtotal' => 740000],
                ['key' => 'technical_specifications', 'title' => 'Technical Specifications', 'groups' => [['product' => '300W Station', 'specs' => [['label' => 'Wattage', 'value' => '300 W']]]]],
                ['key' => 'commercial_offer', 'title' => 'Commercial Offer', 'rows' => [['label' => 'Equipment subtotal', 'value' => 740000]], 'total' => 1109200],
                ['key' => 'warranty', 'title' => 'Warranty', 'body' => 'Components carry warranty.', 'items' => [['product' => '300W Station', 'warranty' => '12-month manufacturer warranty']]],
                ['key' => 'assumptions', 'title' => 'Assumptions', 'items' => ['Prices valid 30 days.']],
                ['key' => 'exclusions', 'title' => 'Exclusions', 'items' => ['Civil works.']],
                ['key' => 'terms', 'title' => 'Terms and Conditions', 'items' => ['70% deposit.']],
            ],
        ];
    }

    public function test_html_is_branded_and_complete(): void
    {
        $t = new Tenant(['name' => 'GSL Solar', 'settings' => ['city' => 'Kampala'], 'whatsapp_number' => '256700000000']);
        $html = (new ProposalPdfRenderer())->html($t, $this->proposal());

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('GSL Solar', $html);
        $this->assertStringContainsString('Nakawa home backup — Solar Proposal', $html);

        foreach (['Executive Summary', 'Equipment List', 'Technical Specifications', 'Commercial Offer', 'Warranty', 'Assumptions', 'Terms and Conditions'] as $sec) {
            $this->assertStringContainsString($sec, $html);
        }
        $this->assertStringContainsString('UGX 740,000', $html);      // money formatting
        $this->assertStringContainsString('UGX 1,109,200', $html);    // total
        $this->assertStringContainsString('300 W', $html);            // spec
        $this->assertStringContainsString('12-month manufacturer warranty', $html);
    }
}
