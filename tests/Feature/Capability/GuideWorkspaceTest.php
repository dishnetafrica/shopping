<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use App\Http\Middleware\ClassicPanelRedirects;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Help & Training — the built-in manual: role-aware chapters, valid deep links. */
class GuideWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role): void
    {
        $t = Tenant::factory()->create();
        $t->putSetting('order_system_enabled', true);
        app(\App\Support\TenantContext::class)->set($t->id);
        $this->actingAs(User::factory()->create(['tenant_id' => $t->id, 'role' => $role]));
    }

    public function test_owner_sees_all_chapters(): void
    {
        $this->login('owner');
        $j = $this->getJson('/api/v1/workspace/guide')->assertOk()->json();
        $tabs = collect($j['tabs'])->pluck('key');
        foreach (['start', 'sales', 'purchasing', 'cash', 'finance', 'chats', 'email', 'admin'] as $k) {
            $this->assertTrue($tabs->contains($k), "owner missing chapter {$k}");
        }
    }

    public function test_purchaser_gets_only_their_manual(): void
    {
        $this->login('purchaser');   // menus: dashboard, orders, ordercosts, purchases, cash
        $j = $this->getJson('/api/v1/workspace/guide')->assertOk()->json();
        $tabs = collect($j['tabs'])->pluck('key');

        $this->assertTrue($tabs->contains('start'));
        $this->assertTrue($tabs->contains('purchasing'), 'the buyer manual is the point');
        $this->assertTrue($tabs->contains('cash'));
        $this->assertFalse($tabs->contains('email'), 'no Email Center admin guide for staff');
        $this->assertFalse($tabs->contains('chats'), 'purchaser has no whatsapp menu');
        $this->assertFalse($tabs->contains('admin'));
    }

    public function test_every_deep_link_targets_a_registered_capability(): void
    {
        $this->login('owner');
        $r = app(CapabilityRegistry::class);
        foreach ((array) config('guide') as $section) {
            foreach (array_keys((array) ($section['links'] ?? [])) as $key) {
                $this->assertTrue($r->has($key), "guide links to unregistered workspace '{$key}'");
            }
        }
    }

    public function test_classic_guide_page_redirects_here(): void
    {
        $this->assertSame('guide', ClassicPanelRedirects::MAP['/panel/guide']);
    }
}
