<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use App\Http\Middleware\ClassicPanelRedirects;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Retire-audit tail: scheduled ORDERS in v3 — queue, KPIs, delegated schedule writes. */
class ScheduledWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function login(): void
    {
        $this->t = Tenant::factory()->create();
        $this->t->putSetting('order_system_enabled', true);
        app(\App\Support\TenantContext::class)->set($this->t->id);
        $this->actingAs(User::factory()->create(['tenant_id' => $this->t->id, 'role' => 'owner']));
    }

    public function test_queue_kpis_rows_and_salesorder_deep_link(): void
    {
        $this->login();
        $due = Order::create([
            'tenant_id' => $this->t->id, 'order_no' => 'FS-7001', 'customer_name' => 'Grace',
            'status' => 'Ready', 'total' => 90, 'scheduled_for' => now()->addHours(3), 'sched_stage' => 'Scheduled',
        ]);
        Order::create([
            'tenant_id' => $this->t->id, 'order_no' => 'FS-7002', 'customer_name' => 'Late',
            'status' => 'Ready', 'total' => 50, 'scheduled_for' => now()->subHours(2), 'sched_stage' => 'Scheduled',
        ]);

        $r = app(CapabilityRegistry::class);
        $this->assertTrue($r->has('scheduled'));

        $j = $this->getJson('/api/v1/workspace/scheduled')->assertOk()->json();
        $kpi = collect($j['financials'])->keyBy('key');
        $this->assertSame('2', $kpi['queued']['value']);
        $this->assertSame('1', $kpi['due24']['value']);
        $this->assertSame('1', $kpi['overdue']['value']);

        $row = collect($j['table']['rows'])->firstWhere('link', '/api/v1/workspace/salesOrder/'.$due->id);
        $this->assertNotNull($row, 'scheduled rows must deep-link to the salesOrder workspace');
    }

    public function test_actions_delegate_to_schedule_order_endpoint(): void
    {
        $this->login();
        Order::create(['tenant_id' => $this->t->id, 'order_no' => 'FS-7003', 'customer_name' => 'New',
            'status' => 'Ready', 'total' => 10]);
        Order::create(['tenant_id' => $this->t->id, 'order_no' => 'FS-7004', 'customer_name' => 'Parked',
            'status' => 'Ready', 'total' => 20, 'scheduled_for' => now()->addDay()]);

        $j = $this->getJson('/api/v1/workspace/scheduled')->assertOk()->json();
        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/schedule-order', $acts['schedule']['endpoint']);
        $this->assertSame('/papi/schedule-order', $acts['release']['endpoint']);
        $this->assertSame(['mode' => 'now'], $acts['release']['body'], 'release = the proven mode:now contract');
        $this->assertStringContainsString('FS-7003', $acts['schedule']['schema']['order_id']);
        $this->assertStringContainsString('FS-7004', $acts['release']['schema']['order_id']);
    }

    public function test_classic_page_redirects_here(): void
    {
        $this->assertSame('scheduled', ClassicPanelRedirects::MAP['/panel/scheduled']);
    }
}
