<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan sales:retire-plan` (DishNet, 21 Nov 2026: "our system gives quotations for Starlink Roam but
 * that plan is no more — remove it and update the prompt"). Prices and names are test values.
 */
class SalesRetirePlanTest extends TestCase
{
    use RefreshDatabase;

    private const FAQ = "Free trial? No.\nCan I move Starlink between locations? Roam plan yes; Residential is tied to the service address but it can be updated.";

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    /** @return array{0:Tenant,1:User,2:User} */
    private function business(): array
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD']]);
        app(TenantContext::class)->set($t->id);
        $mgr = User::create(['tenant_id' => $t->id, 'name' => 'Manager', 'role' => 'manager', 'email' => 'm' . $t->id . '@t.test', 'password' => 'secret']);
        $sales = User::create(['tenant_id' => $t->id, 'name' => 'Akol', 'role' => 'sales', 'phone' => '211922000001', 'email' => 's' . $t->id . '@t.test', 'password' => 'secret']);
        foreach ([['Starlink Roam Plan', 80, 'Starlink plans', 'For moving between locations.'], ['Starlink Residential Plan', 50, 'Starlink plans', 'Unlimited.'],
                  ['Starlink Car Adaptor with cable', 160, 'Starlink accessories', 'For roaming / mobile use.']] as [$name, $price, $cat, $desc]) {
            Product::create(['tenant_id' => $t->id, 'name' => $name, 'price' => $price, 'category' => $cat, 'description' => $desc, 'active' => true, 'billing' => 'monthly', 'service_line' => 'starlink']);
        }
        $brain = app(SalesBrain::class);
        $v = $brain->startVersion($t, $mgr, 'faq', 'general', 'Common questions', self::FAQ);
        $brain->approve($t, $mgr, $v['version_id']);
        $brain->publish($t, $mgr, $v['version_id']);

        return [$t, $mgr, $sales];
    }

    public function test_a_preview_changes_nothing(): void
    {
        [$t] = $this->business();
        $this->artisan('sales:retire-plan', ['tenant' => $t->id, 'word' => 'Roam', '--product' => ['Starlink Roam Plan']])
            ->expectsOutputToContain('"Starlink Roam Plan"')
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();

        $this->assertTrue((bool) Product::where('name', 'Starlink Roam Plan')->value('active'));
        $this->assertSame(self::FAQ, app(SalesBrain::class)->published($t, 'faq')[0]['body'] ?? null);
    }

    public function test_the_plan_is_archived_and_leaves_the_published_knowledge_through_the_sales_brain(): void
    {
        [$t, $mgr] = $this->business();

        $this->artisan('sales:retire-plan', ['tenant' => $t->id, 'word' => 'Roam', '--product' => ['starlink roam plan'], '--by' => $mgr->id, '--apply' => true])
            ->assertSuccessful();

        $roam = Product::where('name', 'Starlink Roam Plan')->first();
        $this->assertFalse((bool) $roam->active, 'never quoted again');
        $this->assertNotNull($roam->archived_at, 'archived, not deleted: old quotations keep their lines');
        $this->assertTrue((bool) Product::where('name', 'Starlink Residential Plan')->value('active'), 'other plans untouched');
        $this->assertSame('For mobile use.', Product::where('name', 'Starlink Car Adaptor with cable')->value('description'), 'the accessory stays, the retired word goes');

        $live = app(SalesBrain::class)->published($t, 'faq');
        $this->assertCount(1, $live);
        $this->assertSame("Free trial? No.\nCan I move Starlink between locations? Residential is tied to the service address but it can be updated.", $live[0]['body']);
        $versions = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->orderBy('version')->pluck('status')->all();
        $this->assertSame(['retired', 'published'], $versions, 'a new version; the old one retired, kept in history');

        $this->artisan('sales:retire-plan', ['tenant' => $t->id, 'word' => 'Roam', '--by' => $mgr->id, '--apply' => true])
            ->expectsOutputToContain('no published entry mentions "Roam"')
            ->assertSuccessful();
    }

    /** Codex on #174: quotation terms print from their items, the bot reads titles, and an entry may be only the plan. */
    public function test_terms_items_titles_and_entries_that_were_only_the_plan(): void
    {
        [$t, $mgr] = $this->business();
        $brain = app(SalesBrain::class);
        $publish = function (string $topic, string $key, string $title, ?string $body, $data = null) use ($brain, $t, $mgr) {
            $v = $brain->startVersion($t, $mgr, $topic, $key, $title, $body, $data);
            $this->assertTrue($v['ok'] ?? false, json_encode($v));
            $brain->approve($t, $mgr, $v['version_id']);
            $brain->publish($t, $mgr, $v['version_id']);
        };
        $publish('terms', 'starlink', 'Starlink terms', null, ['title' => 'Starlink terms', 'items' => [
            ['label' => 'Plans', 'text' => 'Residential is unlimited.'], ['label' => 'Roam', 'text' => 'Roam plan billed monthly.']]]);
        $publish('objections', 'moving', 'Residential / Roam moves', 'Tell them the service address can be updated.');
        $publish('objections', 'roam', 'Roam plan', 'Roam costs more than Residential.');

        $this->artisan('sales:retire-plan', ['tenant' => $t->id, 'word' => 'Roam', '--by' => $mgr->id, '--apply' => true])->assertSuccessful();

        $terms = $brain->published($t, 'terms');
        $this->assertSame([['label' => 'Plans', 'text' => 'Residential is unlimited.']], $terms[0]['data']['items'], 'the Roam term is gone from what quotations print');
        $obj = collect($brain->published($t, 'objections'))->keyBy('key');
        $this->assertSame('Residential moves', $obj['moving']['title'] ?? null, 'a title naming it is cleaned');
        $this->assertArrayNotHasKey('roam', $obj->all(), 'an entry that was only the plan is retired, not republished empty');
    }

    public function test_only_an_owner_or_manager_may_apply_it(): void
    {
        [$t, , $sales] = $this->business();
        $this->artisan('sales:retire-plan', ['tenant' => $t->id, 'word' => 'Roam', '--product' => ['Starlink Roam Plan'], '--by' => $sales->id, '--apply' => true])
            ->assertFailed();
        $this->assertTrue((bool) Product::where('name', 'Starlink Roam Plan')->value('active'));
    }
}
