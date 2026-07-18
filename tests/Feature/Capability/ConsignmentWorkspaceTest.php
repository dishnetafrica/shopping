<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use App\Http\Middleware\ClassicPanelRedirects;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Retire-audit tail: consignments in v3 — manifest, role split, delegated writes. */
class ConsignmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private function login(string $role = 'owner'): User
    {
        $this->t = Tenant::factory()->create();
        $this->t->putSetting('order_system_enabled', true);
        app(\App\Support\TenantContext::class)->set($this->t->id);
        $u = User::factory()->create(['tenant_id' => $this->t->id, 'role' => $role]);
        $this->actingAs($u);
        return $u;
    }

    private function shipmentWithOrder(): array
    {
        $s = Shipment::create([
            'tenant_id' => $this->t->id, 'status' => 'in_transit',
            'origin_city' => 'Kampala', 'destination_city' => 'Juba',
            'transport_company' => 'Trinity', 'destination_agent_name' => 'Asish',
        ]);
        $o = Order::create([
            'tenant_id' => $this->t->id, 'order_no' => 'FS-9001', 'customer_name' => 'Mary',
            'customer_phone' => '211920000001', 'status' => 'Ready', 'total' => 120,
        ]);
        $ln = ShipmentOrder::create([
            'tenant_id' => $this->t->id, 'shipment_id' => $s->id, 'order_id' => $o->id,
            'status' => ShipmentOrder::ON_BOARD,
        ]);
        return [$s, $o, $ln];
    }

    public function test_registry_and_list_rows_link_to_object(): void
    {
        $this->login();
        [$s] = $this->shipmentWithOrder();
        $r = app(CapabilityRegistry::class);
        $this->assertTrue($r->has('consignments'));
        $this->assertTrue($r->has('consignment'));

        $j = $this->getJson('/api/v1/workspace/consignments')->assertOk()->json();
        $kpi = collect($j['financials'])->keyBy('key');
        $this->assertSame('1', $kpi['onboard']['value']);
        $row = collect($j['table']['rows'])->firstWhere('link', '/api/v1/workspace/consignment/'.$s->id);
        $this->assertNotNull($row);
    }

    public function test_object_manifest_and_delegated_delivery_actions(): void
    {
        $this->login();
        [$s, , $ln] = $this->shipmentWithOrder();

        $j = $this->getJson('/api/v1/workspace/consignment/'.$s->id)->assertOk()->json();

        $this->assertStringContainsString('FS-9001', json_encode($j['tabSections']['manifest']));
        $acts = collect($j['actions'])->keyBy('key');
        $this->assertSame('/papi/consignment-deliver-all', $acts['deliver_all']['endpoint']);
        $this->assertSame(['id' => $s->id], $acts['deliver_all']['body']);
        $this->assertStringContainsString((string) $ln->id, $acts['line_status']['schema']['line_id']);
        $this->assertArrayHasKey('add_order', $acts, 'owner gets management actions');
    }

    public function test_field_role_gets_delivery_but_not_management(): void
    {
        $this->login('field');   // Asish's end: open workspace, delivery-only actions
        [$s] = $this->shipmentWithOrder();

        $j = $this->getJson('/api/v1/workspace/consignment/'.$s->id)->assertOk()->json();
        $keys = collect($j['actions'])->pluck('key');
        $this->assertTrue($keys->contains('deliver_all'));
        $this->assertTrue($keys->contains('line_status'));
        $this->assertFalse($keys->contains('add_order'), 'loading a consignment is owner/manager work');
        $this->assertFalse($keys->contains('sell_stock'));
    }

    public function test_classic_page_now_redirects_here(): void
    {
        $this->assertSame('consignments', ClassicPanelRedirects::MAP['/panel/consignment']);
    }
}
