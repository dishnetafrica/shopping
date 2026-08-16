<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Comms\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — purchase-behaviour audience segmentation over the real Order/OrderItem data, tenant-scoped.
 * Covers order_items matching AND the items_json fallback (the "available order data" the plan documents
 * as possibly incomplete). Audience counting only; no sends.
 */
class AudienceSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        return $t;
    }

    private function product(Tenant $t, string $name, string $category): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $t->id, 'name' => $name, 'category' => $category,
            'price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(Tenant $t, string $phone, array $extra = []): void
    {
        DB::table('customer_profiles')->insert(array_merge([
            'tenant_id' => $t->id, 'phone' => $phone, 'name' => 'C '.$phone,
            'marketing_opted_in_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** Create an order for a customer; lines given as [product_id => qty]. Optionally skip order_items
     *  to simulate the sparse case where only items_json carries the product. */
    private function order(Tenant $t, string $phone, array $lines, bool $writeOrderItems = true, ?string $when = null): void
    {
        $itemsJson = [];
        foreach ($lines as $pid => $qty) {
            $itemsJson[] = ['product_id' => (int) $pid, 'name' => 'P'.$pid, 'qty' => $qty, 'price' => 100];
        }
        $oid = (int) DB::table('orders')->insertGetId([
            'tenant_id' => $t->id, 'order_no' => 'O'.uniqid(), 'customer_phone' => $phone,
            'total' => 100, 'status' => 'Delivered', 'channel' => 'whatsapp',
            'items_json' => json_encode($itemsJson),
            'created_at' => $when ? \Illuminate\Support\Carbon::parse($when) : now(),
            'updated_at' => now(),
        ]);
        if ($writeOrderItems) {
            foreach ($lines as $pid => $qty) {
                DB::table('order_items')->insert([
                    'tenant_id' => $t->id, 'order_id' => $oid, 'product_id' => (int) $pid,
                    'name' => 'P'.$pid, 'price' => 100, 'qty' => $qty,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function count(Tenant $t, array $segment): int
    {
        return app(CampaignService::class)->audienceQuery($t, $segment, 'whatsapp')->count();
    }

    public function test_bought_specific_product(): void
    {
        $t = $this->tenant();
        $foil = $this->product($t, 'Aluminium Foil', 'Kitchen');
        $rice = $this->product($t, 'Rice', 'Grocery');
        $this->customer($t, '256700000001');   // buys foil
        $this->customer($t, '256700000002');   // buys rice
        $this->customer($t, '256700000003');   // buys nothing
        $this->order($t, '256700000001', [$foil => 2]);
        $this->order($t, '256700000002', [$rice => 1]);

        $this->assertSame(1, $this->count($t, ['bought_product' => $foil]), 'only the foil buyer');
    }

    public function test_bought_and_not_bought_category(): void
    {
        $t = $this->tenant();
        $foil = $this->product($t, 'Foil', 'Kitchen');
        $film = $this->product($t, 'Cling Film', 'Kitchen');   // same category
        $rice = $this->product($t, 'Rice', 'Grocery');
        $this->customer($t, '256700000001');
        $this->customer($t, '256700000002');
        $this->customer($t, '256700000003');
        $this->order($t, '256700000001', [$foil => 1]);   // Kitchen
        $this->order($t, '256700000002', [$film => 1]);   // Kitchen
        $this->order($t, '256700000003', [$rice => 1]);   // Grocery

        $this->assertSame(2, $this->count($t, ['bought_category' => 'Kitchen']), 'foil + film buyers');
        $this->assertSame(1, $this->count($t, ['not_bought_category' => 'Kitchen']), 'only the rice buyer');
    }

    public function test_items_json_fallback_catches_buyer_missing_from_order_items(): void
    {
        $t = $this->tenant();
        $foil = $this->product($t, 'Foil', 'Kitchen');
        $this->customer($t, '256700000001');
        // Order recorded ONLY in items_json (order_items never mirrored) — the sparse case.
        $this->order($t, '256700000001', [$foil => 1], writeOrderItems: false);

        $this->assertSame(1, $this->count($t, ['bought_product' => $foil]),
            'buyer is found via items_json even when order_items is empty');
    }

    public function test_product_id_delimiter_guard(): void
    {
        $t = $this->tenant();
        $small = $this->product($t, 'Small', 'X');
        $big   = $this->product($t, 'Big', 'X');
        // Give the small product's buyer an order for the BIG product only.
        $this->customer($t, '256700000001');
        $this->order($t, '256700000001', [$big => 1], writeOrderItems: false); // items_json only

        $this->assertSame(0, $this->count($t, ['bought_product' => $small]),
            'a buyer of the big product must not match the small product id');
        $this->assertSame(1, $this->count($t, ['bought_product' => $big]));
    }

    public function test_customer_type_wholesale(): void
    {
        $t = $this->tenant();
        $this->customer($t, '256700000001', ['dealer_tier' => 'wholesale']);
        $this->customer($t, '256700000002', ['segment' => 'wholesale']);
        $this->customer($t, '256700000003', ['segment' => 'retail']);

        $this->assertSame(2, $this->count($t, ['customer_type' => 'wholesale']), 'dealer_tier OR segment tag');
        $this->assertSame(1, $this->count($t, ['customer_type' => 'retail']));
    }

    public function test_ordered_between_date_range(): void
    {
        $t = $this->tenant();
        $rice = $this->product($t, 'Rice', 'Grocery');
        $this->customer($t, '256700000001');
        $this->customer($t, '256700000002');
        $this->order($t, '256700000001', [$rice => 1], when: '2026-06-15');
        $this->order($t, '256700000002', [$rice => 1], when: '2026-01-10');

        $c = $this->count($t, ['ordered_between' => ['from' => '2026-06-01', 'to' => '2026-06-30']]);
        $this->assertSame(1, $c, 'only the June buyer');
    }

    public function test_tenant_isolation_on_segments(): void
    {
        $a = $this->tenant();
        $bId = Tenant::factory()->create()->id;
        $foilA = $this->product($a, 'Foil', 'Kitchen');
        $this->customer($a, '256700000001');
        $this->order($a, '256700000001', [$foilA => 1]);
        // Tenant B customer bought the same-named product — must not appear in A's audience.
        DB::table('customer_profiles')->insert(['tenant_id' => $bId, 'phone' => '256700000001',
            'marketing_opted_in_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(1, $this->count($a, ['bought_product' => $foilA]), 'only tenant A rows');
    }
}
