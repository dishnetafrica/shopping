<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Isp\IspSalesTurn;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Go-live with DishNet management's decisions: the imports followed by sales-brain:apply-dishnet-decisions
 * on a real database — exactly the production sequence for tenant 31.
 */
class DishNetGoLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function imported(): array
    {
        $t = Tenant::factory()->create(['name' => 'DishNet Africa Limited', 'settings' => ['vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);
        $owner = User::create(['tenant_id' => $t->id, 'name' => 'Bhavin', 'role' => 'owner', 'email' => 'o' . $t->id . '@t.test', 'password' => 'secret']);
        $this->artisan('sales-brain:import-template', ['tenant' => $t->id])->assertExitCode(0);
        $this->artisan('sales-brain:import-dishnet-bot', ['tenant' => $t->id])->assertExitCode(0);

        return [$t, $owner];
    }

    private function live(Tenant $t): array
    {
        return SalesKnowledgeVersion::withoutGlobalScopes()->where('sales_knowledge_versions.tenant_id', $t->id)->where('status', 'published')
            ->join('sales_knowledge_entries as e', 'e.id', '=', 'sales_knowledge_versions.entry_id')
            ->get(['e.topic', 'e.key'])->map(fn ($r) => $r->topic . '/' . $r->key)->sort()->values()->all();
    }

    public function test_dry_run_changes_nothing_and_the_real_run_publishes_the_decided_wording(): void
    {
        [$t, $owner] = $this->imported();

        $this->artisan('sales-brain:apply-dishnet-decisions', ['tenant' => $t->id, '--user' => $owner->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame([], $this->live($t), 'dry run: nothing published');
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('active', true)->count());

        $this->artisan('sales-brain:apply-dishnet-decisions', ['tenant' => $t->id, '--user' => $owner->id])->assertExitCode(0);
        $live = $this->live($t);
        $this->assertCount(15, $live);
        $this->assertNotContains('faq/customer-questions', $live, 'customer questions stay a draft');
        $this->assertSame(12, Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('active', true)->count());

        $brain = app(SalesBrain::class);
        $starlink = collect($brain->quoteTerms($t, ['starlink'])[0]['items'])->pluck('text')->implode(' ');
        $this->assertStringContainsString('sold to the customer', $starlink);
        $this->assertStringContainsString('not a guarantee', $starlink);
        $this->assertStringNotContainsString('26%', $starlink);
        $this->assertSame('covered', $brain->coverage($t, 'fiber', 'Plot 3, Gudele Two')['status']);
        $prompt = IspSalesTurn::knowledgeBlock($brain->published($t));
        $this->assertStringContainsString('recommend the Starlink Mini + Residential plan first', $prompt);
        $this->assertStringNotContainsString('[TO ANSWER', $prompt);

        // Who approved, and the recorded answers.
        $v = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->where('status', 'published')
            ->whereIn('entry_id', SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $t->id)->where('topic', 'terms')->where('key', 'starlink')->pluck('id'))->sole();
        $this->assertSame([(int) $owner->id, (int) $owner->id, 5], [(int) $v->approved_by, (int) $v->published_by, count($v->resolved_questions)]);

        // Running again changes nothing.
        $this->artisan('sales-brain:apply-dishnet-decisions', ['tenant' => $t->id, '--user' => $owner->id])->assertExitCode(0);
        $this->assertCount(15, $this->live($t));
    }

    public function test_only_an_owner_or_manager_can_be_the_approver_and_an_unexpected_draft_is_left_alone(): void
    {
        [$t] = $this->imported();
        $sales = User::create(['tenant_id' => $t->id, 'name' => 'Akol', 'role' => 'sales', 'email' => 's' . $t->id . '@t.test', 'password' => 'secret']);
        $this->artisan('sales-brain:apply-dishnet-decisions', ['tenant' => $t->id, '--user' => $sales->id])->assertExitCode(1);
        $this->assertSame([], $this->live($t));

        // Someone changed the payment draft by hand: its decided edit no longer finds its text → left as a draft.
        $owner = User::where('tenant_id', $t->id)->where('role', 'owner')->sole();
        $pay = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereIn('entry_id', SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $t->id)->where('topic', 'payment')->pluck('id'))->sole();
        app(SalesBrain::class)->editDraft($t, $owner, $pay->id, 'Payment text rewritten by hand.');
        $this->artisan('sales-brain:apply-dishnet-decisions', ['tenant' => $t->id, '--user' => $owner->id])->assertExitCode(1);
        $this->assertNotContains('payment/methods', $this->live($t));
        $this->assertCount(14, $this->live($t));
    }

    public function test_a_switched_off_product_can_be_switched_on_from_its_page(): void
    {
        [$t, $owner] = $this->imported();
        $p = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('name', 'Starlink Mini (Gen 4)')->sole();
        $this->actingAs($owner);

        $page = $this->getJson('/api/v1/workspace/product/' . $p->id)->assertOk()->json();
        $this->assertContains('activate_product', collect($page['actions'])->pluck('key')->all());
        $this->postJson('/api/v1/workspace/product/' . $p->id . '/activate_product')->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue((bool) $p->fresh()->active);
    }
}
