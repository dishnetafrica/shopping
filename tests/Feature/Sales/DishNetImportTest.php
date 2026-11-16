<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Services\Bot\OrderCalculator;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sales-brain:import-dishnet-bot against the real database: products arrive switched off and are
 * invisible to quotations until a manager switches them on; knowledge arrives as drafts only; a second
 * run changes nothing; other business types are refused.
 */
class DishNetImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp'): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    public function test_products_arrive_switched_off_and_knowledge_as_drafts_only(): void
    {
        $t = $this->business();
        $this->artisan('sales-brain:import-dishnet-bot', ['tenant' => $t->id])->assertExitCode(0);
        $this->artisan('sales-brain:import-dishnet-bot', ['tenant' => $t->id])->assertExitCode(0);   // second run: nothing new

        $products = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->get();
        $this->assertCount(13, $products);
        $this->assertSame([false], $products->pluck('active')->unique()->values()->all(), 'every product is switched off');
        $plan = $products->firstWhere('name', 'Fiber Standard 40 Mbps');
        $this->assertSame(['monthly', 'fiber', 75.0], [$plan->billing, $plan->service_line, (float) $plan->price]);

        $versions = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->get();
        $this->assertCount(13, $versions, '12 knowledge drafts + the customer-questions draft');
        $this->assertSame(['draft'], $versions->pluck('status')->unique()->values()->all());
        $this->assertSame([], app(SalesBrain::class)->published($t), 'nothing reaches the bot');
        $this->assertSame('survey', app(SalesBrain::class)->coverage($t, 'fiber', 'Gudele Two')['status'], 'draft coverage does not count');

        // The customer-questions draft cannot be approved while any answer is still "[TO ANSWER]".
        $mgr = \App\Models\User::create(['tenant_id' => $t->id, 'name' => 'Manager', 'role' => 'manager', 'email' => 'mq@t.test', 'password' => 'secret']);
        $cq = $versions->first(fn ($v) => str_contains((string) $v->body, '[TO ANSWER'));
        $this->assertNotNull($cq);
        $this->assertSame('unanswered', app(SalesBrain::class)->approve($t, $mgr, $cq->id)['error']);
        $answered = preg_replace('/\[TO ANSWER[^\]]*\]/', 'Test answer.', (string) $cq->body);
        $this->assertTrue(app(SalesBrain::class)->editDraft($t, $mgr, $cq->id, $answered)['ok']);
        $this->assertTrue(app(SalesBrain::class)->approve($t, $mgr, $cq->id)['ok'], 'once every answer is written it can be approved');
    }

    public function test_a_switched_off_product_is_never_quoted_and_a_monthly_plan_is_not_refused_for_stock(): void
    {
        $t = $this->business();
        $this->artisan('sales-brain:import-dishnet-bot', ['tenant' => $t->id])->assertExitCode(0);
        $calc = app(OrderCalculator::class);

        $before = $calc->quote($t, [['query' => 'Fiber Standard 40 Mbps', 'qty' => 1]], null, true);
        $this->assertFalse((bool) ($before['lines'][0]['matched'] ?? false), 'switched off: not quoted');

        foreach (['Fiber Standard 40 Mbps', 'Starlink Mini (Gen 4)'] as $n) {
            Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', $n)->firstOrFail()->update(['active' => true]);
        }
        $plan = $calc->quote($t, [['query' => 'Fiber Standard 40 Mbps', 'qty' => 1]], null, true)['lines'][0];
        $this->assertTrue((bool) $plan['matched'], 'a monthly plan is a service: no stock needed');
        $this->assertEquals(75.0, (float) $plan['price']);
        $kit = $calc->quote($t, [['query' => 'Starlink Mini (Gen 4)', 'qty' => 1]], null, true)['lines'][0];
        $this->assertTrue((bool) ($kit['out_of_stock'] ?? false), 'hardware with no stock entered is not offered by the bot');
    }

    public function test_another_business_type_is_refused(): void
    {
        $shop = $this->business('grocery');
        $this->artisan('sales-brain:import-dishnet-bot', ['tenant' => $shop->id])->assertExitCode(1);
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $shop->id)->count());
    }
}
