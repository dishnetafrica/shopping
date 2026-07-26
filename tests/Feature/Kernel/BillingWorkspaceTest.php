<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V3 Migration — Billing (view-first). Proves: the capability catalog includes 'billing'; the screen
 * shows the Free/Starter/Pro comparison with the current plan flagged and an "Upgrade / manage payment"
 * action that opens the existing secure billing page (no payment gateway is touched from V3).
 */
class BillingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    public function test_catalog_includes_billing(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'billing'));
    }

    public function test_shows_plan_comparison_and_manage_action(): void
    {
        $this->boot();   // a fresh tenant is on the 30-day trial → effective plan "pro"

        $j = $this->getJson('/api/v1/workspace/billing')->assertOk()->json();

        // Free / Starter / Pro all listed.
        $rows = collect($j['table']['rows'] ?? []);
        $this->assertCount(3, $rows);
        $names = $rows->map(fn ($r) => $r['plan']['value'] ?? '')->all();
        $this->assertContains('Free', $names);
        $this->assertContains('Starter', $names);
        $this->assertContains('Pro', $names);

        // The current plan (Pro during the trial) is flagged.
        $pro = $rows->first(fn ($r) => ($r['plan']['value'] ?? '') === 'Pro');
        $this->assertSame('Current plan', $pro['plan']['sub'] ?? null);

        // The upgrade action opens the existing secure billing page — payments untouched.
        $manage = collect($j['actions'] ?? [])->firstWhere('key', 'manage_billing');
        $this->assertNotNull($manage);
        $this->assertStringContainsString('/panel/billing', (string) ($manage['href'] ?? ''));
    }
}
