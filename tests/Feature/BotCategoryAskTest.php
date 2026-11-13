<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Bot\AiBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two bot behaviours reported from a live WhatsApp test of a Kampala Starlink reseller:
 *
 *  1. "i need mini kit 5 pcs how much" was answered with the *Mounts* catalogue, because the only
 *     word that survives the filter ("mini") appears in four mount names. A word spread across the
 *     whole range must elect no category (CategoryVote).
 *  2. The generated delivery line promised "Delivery inside Kampala and its markets is free" to a
 *     shop that charges for every delivery. A stated delivery policy must be said as written
 *     (DeliveryWording).
 *
 * DB-backed, through the real private methods. The pure decision logic is also covered by
 * qa/bot_category_vote.php, which runs without the framework.
 */
class BotCategoryAskTest extends TestCase
{
    use RefreshDatabase;

    private function call(string $method, ...$args)
    {
        $m = new \ReflectionMethod(AiBrain::class, $method);
        $m->setAccessible(true);

        return $m->invoke(app(AiBrain::class), ...$args);
    }

    private function starlinkShop(array $settings = []): Tenant
    {
        $t = Tenant::factory()->create(['settings' => $settings]);
        app(TenantContext::class)->set($t->id);
        $rows = [
            ['Mobility Mount | Mini', 'Mounts'], ['Pivot Mount | Mini', 'Mounts'],
            ['Roof Rack Mount | Mini', 'Mounts'], ['Wall Mount | Mini', 'Mounts'],
            ['Wall Mount | Standard 4 or 4 X', 'Mounts'], ['Ridgeline Mount | Standard 4 or 4 X', 'Mounts'],
            ['Router Mini', 'Routers and WiFi'], ['Router 3 | Starlink V4 or V5, Mini', 'Routers and WiFi'],
            ['Starlink Mini Ethernet Cable 15m', 'Cables'], ['Starlink Mini USB-C Cable 5m', 'Cables'],
            ['Car Adapter | Mini', 'Power'], ['Travel Kit | Mini', 'Cases'],
            ['Starlink Mini Kit', 'Starlink Kits'], ['Starlink Standard Kit', 'Starlink Kits'],
        ];
        foreach ($rows as [$name, $cat]) {
            Product::create(['tenant_id' => $t->id, 'name' => $name, 'category' => $cat, 'price' => 100000, 'active' => true]);
        }

        return $t;
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_a_variant_word_shared_across_the_range_elects_no_category(): void
    {
        $t = $this->starlinkShop();

        $this->assertNull(
            $this->call('detectCategoryAsk', $t, 'i need mini kit 5 pcs how much'),
            'a Mini Kit question must not be answered with the Mounts catalogue'
        );
    }

    public function test_a_named_category_and_a_dominant_word_still_pick_their_section(): void
    {
        $t = $this->starlinkShop();

        // pass 1 — the category name is in the message
        $this->assertSame('Mounts', $this->call('detectCategoryAsk', $t, 'send me the mounts price list'));
        // pass 2 — "mount" is in six product names, all in one category
        $this->assertSame('Mounts', $this->call('detectCategoryAsk', $t, 'how much is a roof mount'));
    }

    public function test_a_stated_delivery_policy_replaces_the_free_delivery_wording(): void
    {
        $policy = 'Delivery is not free. Our team confirms the delivery cost when you order.';
        $t = $this->starlinkShop(['delivery_from_city' => 'Kampala', 'delivery_note' => $policy]);

        $line = (string) $this->call('deliveryNote', $t, 'Ntinda');

        $this->assertStringContainsString($policy, $line);
        $this->assertStringNotContainsString('is free', $line, 'must not promise free delivery');
        $this->assertStringNotContainsString('Juba', $line);
    }

    public function test_a_shop_without_a_policy_keeps_the_existing_wording(): void
    {
        $t = $this->starlinkShop(['delivery_from_city' => 'Kampala']);

        $line = (string) $this->call('deliveryNote', $t, 'Ntinda');

        $this->assertStringContainsString('Which town or area is *Ntinda* in?', $line);
    }
}
