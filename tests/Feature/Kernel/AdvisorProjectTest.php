<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\AdvisorProject;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Advisor API (Slice 2 — Discovery). Proves the questionnaire schema is served, that answers
 * compute a deterministic sizing without saving, that a captured project persists both answers and the
 * sizing snapshot, and that the endpoints are gated (a role without the Sales menu is refused).
 */
class AdvisorProjectTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role[0] . '-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    private function answers(): array
    {
        return [
            'customer_type' => 'Residential', 'location' => 'Central (Kampala)',
            'backup_hours' => 8, 'future_expansion' => 'Some (20%)',
            'loads' => [
                ['name' => 'LED light', 'watts' => 10, 'qty' => 4, 'hours_per_day' => 5, 'critical' => true],
                ['name' => 'TV', 'watts' => 100, 'qty' => 1, 'hours_per_day' => 4, 'critical' => true],
                ['name' => 'Fridge', 'watts' => 150, 'qty' => 1, 'hours_per_day' => 8, 'critical' => true],
                ['name' => 'Phone charger', 'watts' => 10, 'qty' => 1, 'hours_per_day' => 3, 'critical' => false],
            ],
        ];
    }

    public function test_schema_endpoint(): void
    {
        $this->boot();
        $j = $this->getJson('/papi/advisor/schema?profile=solar')->assertOk()->json();
        $this->assertTrue($j['ok']);
        $this->assertSame('solar', $j['schema']['profile']);
        $this->assertCount(5, $j['schema']['sections']);
    }

    public function test_compute_is_deterministic_and_unsaved(): void
    {
        $this->boot();
        $j = $this->postJson('/papi/advisor/compute', ['profile' => 'solar', 'answers' => $this->answers()])
            ->assertOk()->json();

        $r = $j['sizing']['result'];
        $this->assertEqualsWithDelta(300.0, $r['connected_load_w'], 0.01);
        $this->assertEqualsWithDelta(1830.0, $r['daily_energy_wh'], 0.01);
        $this->assertEqualsWithDelta(375.0, $r['inverter_w'], 0.01);   // 300 × 1.25
        $this->assertSame(0, AdvisorProject::count());                 // compute never persists
    }

    public function test_save_persists_answers_and_sizing_snapshot(): void
    {
        $t = $this->boot();
        $save = $this->postJson('/papi/advisor/projects', [
            'profile' => 'solar', 'name' => 'Nakawa home backup', 'answers' => $this->answers(),
        ])->assertOk()->json();

        $id = $save['project']['id'];
        $this->assertSame('sized', $save['project']['status']);
        $this->assertEqualsWithDelta(1830.0, $save['project']['sizing']['result']['daily_energy_wh'], 0.01);

        $row = AdvisorProject::find($id);
        $this->assertSame($t->id, $row->tenant_id);
        $this->assertSame('Nakawa home backup', $row->name);
        $this->assertNotEmpty($row->answers['loads']);
        $this->assertEqualsWithDelta(375.0, $row->sizing['result']['inverter_w'], 0.01);

        // Read back through the API.
        $show = $this->getJson('/papi/advisor/projects/' . $id)->assertOk()->json();
        $this->assertSame($id, $show['project']['id']);
        $this->assertTrue(collect($this->getJson('/papi/advisor/projects')->json('projects'))
            ->contains(fn ($p) => $p['id'] === $id));
    }

    public function test_gated_to_sales_menu(): void
    {
        // product_staff sees only dashboard + products — no Sales menu, so the advisor is refused.
        $this->boot('product_staff');
        $this->getJson('/papi/advisor/schema')->assertStatus(403);
        $this->postJson('/papi/advisor/compute', ['answers' => $this->answers()])->assertStatus(403);
    }
}
