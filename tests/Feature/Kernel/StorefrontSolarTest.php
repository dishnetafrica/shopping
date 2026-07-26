<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\AdvisorProject;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public Solar Experience Center (customer-facing, no login). Proves the shop-scoped assessment works
 * without authentication: config lists appliances/goals, estimate turns an appliance selection into a
 * deterministic recommendation + package, and a lead drops a sized project into the staff Advisor with a
 * WhatsApp hand-off. Unknown shops 404; a lead without a phone is rejected.
 */
class StorefrontSolarTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Tenant
    {
        return Tenant::create([
            'name' => 'GSL Solar', 'slug' => 'gsl-' . substr(md5(uniqid('', true)), 0, 6),
            'whatsapp_number' => '256700000111', 'settings' => ['vertical' => 'solar', 'currency' => 'UGX'],
        ]);
    }

    public function test_config_is_public(): void
    {
        $t = $this->shop();
        $j = $this->getJson('/' . $t->slug . '/solar/config')->assertOk()->json();  // no auth
        $this->assertTrue($j['ok']);
        $this->assertNotEmpty($j['appliances']);
        $this->assertNotEmpty($j['goals']);
        $this->assertSame('UGX', $j['currency']);
    }

    public function test_estimate_returns_recommendation_and_package(): void
    {
        $t = $this->shop();
        $j = $this->postJson('/' . $t->slug . '/solar/estimate', [
            'customer_type' => 'home', 'backup_hours' => 6,
            'appliances' => [['key' => 'lights', 'qty' => 6], ['key' => 'tv', 'qty' => 1], ['key' => 'fridge', 'qty' => 1]],
        ])->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertGreaterThan(0, $j['recommendation']['pv_w']);
        $this->assertGreaterThan(0, $j['recommendation']['panels']);
        $this->assertNotEmpty($j['why']);
        $this->assertSame('Home Starter', $j['package']['name']);
        $this->assertGreaterThan(0, $j['package']['price_from']);
    }

    public function test_lead_creates_sized_project_and_returns_whatsapp(): void
    {
        $t = $this->shop();
        $j = $this->postJson('/' . $t->slug . '/solar/lead', [
            'customer_type' => 'home', 'backup_hours' => 8, 'name' => 'Amina', 'phone' => '0772 000 111',
            'appliances' => [['key' => 'fridge', 'qty' => 1], ['key' => 'tv', 'qty' => 1]],
        ])->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertStringContainsString('wa.me/256700000111', (string) $j['whatsapp']);

        app(TenantContext::class)->set($t->id);
        $p = AdvisorProject::where('tenant_id', $t->id)->findOrFail($j['project_id']);
        $this->assertSame('storefront-solar', $p->answers['source']);
        $this->assertSame('Amina', $p->answers['contact_name']);
        $this->assertGreaterThan(0, $p->sizing['result']['pv_array_w']);   // sized by the engine
    }

    public function test_lead_requires_phone(): void
    {
        $t = $this->shop();
        $this->postJson('/' . $t->slug . '/solar/lead', ['customer_type' => 'home', 'name' => 'X', 'appliances' => [['key' => 'tv', 'qty' => 1]]])
            ->assertStatus(422);
    }

    public function test_unknown_shop_404(): void
    {
        $this->getJson('/no-such-shop-xyz/solar/config')->assertStatus(404);
    }

    public function test_page_renders(): void
    {
        $t = $this->shop();
        $res = $this->get('/' . $t->slug . '/solar');
        $res->assertOk();
        $this->assertStringContainsString('solar assessment', strtolower($res->getContent()));
    }
}
