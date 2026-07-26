<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Advisor API (Slice 5 — Knowledge Library). Proves the library is served, filters by type and
 * free-text query, exposes categories/types, and is gated to the Sales menu.
 */
class AdvisorKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): void
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'role' => $role,
            'email' => $role[0] . '-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
    }

    public function test_lists_and_exposes_types_and_categories(): void
    {
        $this->boot();
        $j = $this->getJson('/papi/advisor/knowledge?profile=solar')->assertOk()->json();
        $this->assertTrue($j['ok']);
        $this->assertGreaterThanOrEqual(20, count($j['entries']));
        $this->assertContains('faq', $j['types']);
        $this->assertNotEmpty($j['categories']);
    }

    public function test_filter_by_type_and_query(): void
    {
        $this->boot();
        $faq = $this->getJson('/papi/advisor/knowledge?type=faq')->assertOk()->json('entries');
        $this->assertNotEmpty($faq);
        $this->assertEmpty(array_filter($faq, fn ($e) => $e['type'] !== 'faq'));

        $q = $this->getJson('/papi/advisor/knowledge?q=generator')->assertOk()->json('entries');
        $this->assertTrue(collect($q)->contains(fn ($e) => $e['key'] === 'obj_generator'));
    }

    public function test_gated_to_sales_menu(): void
    {
        $this->boot('product_staff');   // no Sales menu
        $this->getJson('/papi/advisor/knowledge')->assertStatus(403);
    }
}
