<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\AdvisorProject;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Advisor\ProjectAdvisorService;
use App\Services\Catalog\ProductSpecService;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Advisor API (Slice 3 — Proposal). Proves that generating a proposal for a captured project
 * resolves catalogue equipment (name, price, warranty and Product Specifications), assembles the full
 * sectioned proposal with correct commercial totals, persists it on the project, and reads it back.
 */
class AdvisorProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_proposal_resolves_equipment_and_persists(): void
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8), 'settings' => ['vertical' => 'solar']]);
        $u = User::create(['tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        SolarPreset::seedSpecCatalogue($t);

        // A catalogue product with a price, warranty and specs.
        $station = Product::create([
            'tenant_id' => $t->id, 'name' => '300W Solar Power Station', 'sku' => 'GSL-SOLAR-300W',
            'category' => 'Solar', 'price' => 740000, 'base_price' => 740000, 'stock' => 10, 'active' => true,
            'warranty_manufacturer_months' => 12, 'warranty_company_months' => 6,
        ]);
        ProductSpecService::forTenant($t)->setProductSpecs($station->id, ['wattage' => 300, 'battery_chemistry' => 'LiFePO4']);

        // A captured project (answers → deterministic sizing) via the Slice 2 service.
        $project = ProjectAdvisorService::make()->save($t, [
            'name' => 'Nakawa home backup', 'profile' => 'solar',
            'answers' => [
                'customer_type' => 'Residential', 'project_objective' => 'Backup during outages',
                'location' => 'Central (Kampala)', 'backup_hours' => 8,
                'loads' => [
                    ['name' => 'TV', 'watts' => 100, 'qty' => 1, 'hours_per_day' => 4, 'critical' => true],
                    ['name' => 'Fridge', 'watts' => 150, 'qty' => 1, 'hours_per_day' => 8, 'critical' => true],
                ],
            ],
        ], $u->id);

        $j = $this->postJson('/papi/advisor/projects/' . $project->id . '/proposal', [
            'equipment' => [['product_id' => $station->id, 'qty' => 1]],
            'opts' => ['currency' => 'UGX', 'install_fee' => 200000, 'vat_pct' => 18],
        ])->assertOk()->json();

        $p = $j['proposal'];
        $this->assertCount(11, $p['sections']);

        $eq = collect($p['sections'])->firstWhere('key', 'equipment_list');
        $this->assertSame('300W Solar Power Station', $eq['items'][0]['name']);   // resolved from catalogue
        $this->assertEqualsWithDelta(740000.0, $eq['subtotal'], 0.01);

        $co = collect($p['sections'])->firstWhere('key', 'commercial_offer');
        $this->assertEqualsWithDelta(1109200.0, $co['total'], 0.01);             // (740k + 200k) × 1.18

        // Product Specifications flowed into the technical section.
        $specs = collect($p['sections'])->firstWhere('key', 'technical_specifications')['groups'];
        $this->assertStringContainsString('300', json_encode($specs));
        // Warranty resolved from the product's months.
        $warr = collect($p['sections'])->firstWhere('key', 'warranty')['items'];
        $this->assertStringContainsString('12-month manufacturer', json_encode($warr));

        // Persisted on the project, status advanced.
        $row = AdvisorProject::find($project->id);
        $this->assertNotNull($row->proposal);
        $this->assertSame('proposed', $row->status);

        // Readable back through the API.
        $this->getJson('/papi/advisor/projects/' . $project->id . '/proposal')
            ->assertOk()->assertJson(['ok' => true]);
    }
}
