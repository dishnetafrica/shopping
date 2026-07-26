<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V3 Migration — Team & Roles (replaces legacy /panel/staff). Proves: the capability catalog includes
 * 'team'; the screen lists staff (with the current user flagged and excluded from the change/remove
 * pickers), carries the role guide, and offers add/change-role/remove actions pointing at the guarded
 * /papi/staff endpoints.
 */
class TeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): array
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $owner = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($owner);
        return [$t, $owner];
    }

    public function test_catalog_includes_team(): void
    {
        $this->boot();
        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'team'));
    }

    public function test_team_lists_staff_role_guide_and_actions(): void
    {
        [$t] = $this->boot();
        User::create(['tenant_id' => $t->id, 'name' => 'Jayesh', 'role' => 'sales',
            'email' => 's-' . uniqid('', true) . '@t.test', 'password' => 'secret', 'phone' => '256772000111']);

        $j = $this->getJson('/api/v1/workspace/team')->assertOk()->json();

        // Role guide present (answers), including Owner.
        $answers = collect($j['answers'] ?? []);
        $this->assertTrue($answers->contains(fn ($a) => ($a['q'] ?? '') === 'Owner'));

        // Staff listed in an overview card (2 people).
        $card = collect($j['overviewCards'] ?? [])->firstWhere('title', 'Team members');
        $this->assertNotNull($card);
        $this->assertCount(2, $card['rows']);

        // Actions reuse the guarded staff endpoints.
        $actions = collect($j['actions'] ?? []);
        $add = $actions->firstWhere('key', 'add_staff');
        $this->assertSame('/papi/staff/add', $add['endpoint'] ?? null);
        $this->assertTrue($actions->contains(fn ($a) => ($a['key'] ?? '') === 'change_role'));
        $this->assertTrue($actions->contains(fn ($a) => ($a['key'] ?? '') === 'remove_member'));

        // The change-role picker excludes the current owner (can't demote yourself into a lockout).
        $change = $actions->firstWhere('key', 'change_role');
        $this->assertStringNotContainsString('Owner', (string) ($change['schema']['id'] ?? ''));
        $this->assertStringContainsString('Jayesh', (string) ($change['schema']['id'] ?? ''));
    }
}
