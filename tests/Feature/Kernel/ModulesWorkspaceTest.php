<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Access;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V3 Migration — Module Manager (replaces legacy /panel/system). Proves: the capability catalog includes
 * 'modules'; the list reflects a module turned off; the owner can toggle a module (persisted to
 * settings.modules, the same map the menu + API read); non-owners cannot toggle.
 */
class ModulesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => $role,
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_catalog_includes_modules(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'modules'));
    }

    public function test_list_reflects_a_disabled_module(): void
    {
        $t = $this->boot();
        $t->putSetting('modules', ['inventory' => false]);   // turn Stock off

        $rows = collect($this->getJson('/api/v1/workspace/modules')->assertOk()->json('table.rows'));
        $stock = $rows->first(fn ($r) => ($r['module']['value'] ?? '') === 'Stock');
        $this->assertNotNull($stock);
        $this->assertSame('Off', $stock['status']['value']);

        $sales = $rows->first(fn ($r) => ($r['module']['value'] ?? '') === 'Sales');
        $this->assertSame('On', $sales['status']['value']);   // unset = on
    }

    public function test_owner_can_toggle_and_it_persists(): void
    {
        $t = $this->boot();
        $this->assertTrue(Access::tenantModuleEnabled($t, 'purchases'));   // default on

        $this->postJson('/papi/modules/toggle', ['module' => 'purchases', 'enabled' => false])
            ->assertOk()->assertJson(['ok' => true, 'module' => 'purchases', 'enabled' => false]);
        $this->assertFalse(Access::tenantModuleEnabled($t->fresh(), 'purchases'));

        $this->postJson('/papi/modules/toggle', ['module' => 'purchases', 'enabled' => true])->assertOk();
        $this->assertTrue(Access::tenantModuleEnabled($t->fresh(), 'purchases'));
    }

    public function test_unknown_module_is_rejected(): void
    {
        $this->boot();
        $this->postJson('/papi/modules/toggle', ['module' => 'nope', 'enabled' => false])->assertStatus(422);
    }

    public function test_toggle_is_owner_only(): void
    {
        $this->boot('manager');   // a manager is not owner-like → cannot manage modules
        $this->postJson('/papi/modules/toggle', ['module' => 'sales', 'enabled' => false])->assertStatus(403);
    }
}
