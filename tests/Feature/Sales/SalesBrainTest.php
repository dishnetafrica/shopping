<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\QuotationService;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales Brain (DishNet South Sudan plan, Phase 3) against the real database: only PUBLISHED knowledge
 * is ever read; drafts need management approval; every step is recorded. Texts are test values.
 */
class SalesBrainTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function isp(string $vertical = 'isp'): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);

        return $t;
    }

    private function user(Tenant $t, string $role, string $tag): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => ucfirst($tag), 'role' => $role, 'phone' => '2119220000' . strlen($tag),
            'email' => "{$tag}{$t->id}@t.test", 'password' => 'secret']);
    }

    private function brain(): SalesBrain
    {
        return app(SalesBrain::class);
    }

    private function faq(Tenant $t, User $by, string $text): array
    {
        $r = $this->brain()->startVersion($t, $by, 'faq', 'install-time', 'How long installation takes', $text);
        $this->assertTrue($r['ok'], json_encode($r));

        return $r;
    }

    public function test_a_draft_reaches_nobody_until_a_manager_approves_and_publishes_it(): void
    {
        $t = $this->isp();
        $mgr = $this->user($t, 'manager', 'mgr');
        $v1 = $this->faq($t, $mgr, 'Test answer one');

        $this->assertSame([], $this->brain()->published($t), 'a draft is not published knowledge');
        $this->assertFalse($this->brain()->publish($t, $mgr, $v1['version_id'])['ok'], 'cannot publish without approval');

        $this->assertTrue($this->brain()->approve($t, $mgr, $v1['version_id'])['ok']);
        $this->assertSame([], $this->brain()->published($t), 'approved is still not published');
        $this->assertTrue($this->brain()->publish($t, $mgr, $v1['version_id'])['ok']);
        $this->assertSame(['Test answer one'], array_column($this->brain()->published($t, 'faq'), 'body'));

        // A new version: the old one stays live until the new one is published, then it is retired.
        $v2 = $this->faq($t, $mgr, 'Test answer two');
        $this->assertSame(['Test answer one'], array_column($this->brain()->published($t, 'faq'), 'body'));
        $this->brain()->approve($t, $mgr, $v2['version_id']);
        $this->brain()->publish($t, $mgr, $v2['version_id']);
        $this->assertSame(['Test answer two'], array_column($this->brain()->published($t, 'faq'), 'body'));
        $this->assertSame('retired', SalesKnowledgeVersion::withoutGlobalScopes()->find($v1['version_id'])->status);

        $v = SalesKnowledgeVersion::withoutGlobalScopes()->find($v2['version_id']);
        $this->assertSame([2, $mgr->id, $mgr->id, $mgr->id], [(int) $v->version, (int) $v->created_by, (int) $v->approved_by, (int) $v->published_by]);
        $this->assertSame(['draft', 'approve', 'publish'], AuditLog::withoutGlobalScopes()->where('entity_type', 'sales_knowledge')
            ->where('entity_id', $v2['version_id'])->orderBy('id')->pluck('action')->all());
    }

    public function test_only_the_owner_or_a_manager_of_this_business_can_change_knowledge(): void
    {
        $t = $this->isp();
        $other = $this->isp();
        $mgr = $this->user($t, 'manager', 'mgr');
        $sales = $this->user($t, 'sales', 'akol');
        $outsider = $this->user($other, 'owner', 'boss');

        $this->assertSame('forbidden', $this->brain()->startVersion($t, $sales, 'faq', 'x', 'X', 'text')['error']);
        $v = $this->faq($t, $mgr, 'Test answer');
        $this->assertSame('forbidden', $this->brain()->approve($t, $sales, $v['version_id'])['error']);
        $this->assertSame('forbidden', $this->brain()->approve($t, $outsider, $v['version_id'])['error'], 'another business\'s owner');
        $this->assertSame('not_found', $this->brain()->approve($other, $outsider, $v['version_id'])['error'], 'not visible from another business');
        $this->assertSame('draft', SalesKnowledgeVersion::withoutGlobalScopes()->find($v['version_id'])->status);
    }

    public function test_open_questions_block_approval_and_approved_text_never_changes(): void
    {
        $t = $this->isp();
        $owner = $this->user($t, 'owner', 'own');
        $r = $this->brain()->startVersion($t, $owner, 'terms', 'starlink', 'Test terms', null,
            ['title' => 'Test terms', 'items' => [['label' => 'Pay', 'text' => 'Test term']]], 'test', ['Is this right?']);

        $this->assertSame('open_questions', $this->brain()->approve($t, $owner, $r['version_id'])['error']);
        $this->assertTrue($this->brain()->resolveQuestion($t, $owner, $r['version_id'], 0, 'Yes, confirmed')['ok']);
        $this->assertTrue($this->brain()->approve($t, $owner, $r['version_id'])['ok']);

        $v = SalesKnowledgeVersion::withoutGlobalScopes()->find($r['version_id']);
        $this->assertSame('Yes, confirmed', $v->resolved_questions[0]['answer']);
        $this->assertSame('not_a_draft', $this->brain()->editDraft($t, $owner, $r['version_id'], null, ['items' => [['label' => 'x', 'text' => 'changed']]])['error']);
        $this->assertSame('version_in_progress', $this->brain()->startVersion($t, $owner, 'terms', 'starlink', 'Test terms', null,
            ['items' => [['label' => 'x', 'text' => 'y']]])['error'], 'one version on its way at a time');
    }

    public function test_the_template_import_makes_drafts_only_and_nothing_prints_until_published(): void
    {
        $t = $this->isp();
        $shop = $this->isp('grocery');

        $this->artisan('sales-brain:import-template', ['tenant' => $t->id])->assertExitCode(0);
        $this->artisan('sales-brain:import-template', ['tenant' => $t->id])->assertExitCode(0);   // second run changes nothing
        $this->artisan('sales-brain:import-template', ['tenant' => $shop->id])->assertExitCode(1);

        $rows = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['draft'], $rows->pluck('status')->unique()->values()->all());
        $this->assertTrue($rows->every(fn ($v) => count((array) $v->open_questions) > 0), 'each carries questions for management');
        $this->assertSame(0, SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $shop->id)->count());
        $this->assertSame([], $this->brain()->quoteTerms($t, ['starlink']), 'drafts never print on a quotation');
    }

    public function test_published_terms_print_on_the_quotation_for_that_service_only(): void
    {
        $t = $this->isp();
        $mgr = $this->user($t, 'manager', 'mgr');
        $r = $this->brain()->startVersion($t, $mgr, 'terms', 'starlink', 'Starlink terms', null,
            ['title' => 'Starlink test terms', 'items' => [['label' => 'Payment', 'text' => 'Approved test term']]]);
        $this->brain()->approve($t, $mgr, $r['version_id']);
        $this->brain()->publish($t, $mgr, $r['version_id']);

        $w = app(ProductWriteService::class);
        $w->create($t, ['name' => 'Test Kit', 'price' => 100, 'stock' => 5, 'billing' => 'one_off', 'service_line' => 'starlink']);
        $w->create($t, ['name' => 'Test Fibre', 'price' => 40, 'stock' => 5, 'billing' => 'monthly', 'service_line' => 'fiber']);

        $html = function (string $item) use ($t) {
            $quote = app(\App\Services\Bot\OrderCalculator::class)->quote($t, [['query' => $item, 'qty' => 1]]);
            $priced = new \ReflectionMethod(QuotationService::class, 'priced');
            $priced->setAccessible(true);
            $doc = new \ReflectionMethod(QuotationService::class, 'ispDoc');
            $doc->setAccessible(true);

            return $doc->invoke(app(QuotationService::class), $t, 'QUO-T-1', '211911000001', 'Test', $quote, $priced->invoke(app(QuotationService::class), $quote, 0.0, 0.0, false), 0.0, 0.0)['html'];
        };

        $starlink = $html('Test Kit');
        $this->assertStringContainsString('Approved test term', $starlink);
        $this->assertStringContainsString('agree to the above terms', $starlink);
        $this->assertStringNotContainsString('Approved test term', $html('Test Fibre'), 'Fiber terms are not published: none, not Starlink\'s');
    }

    public function test_coverage_answers_come_only_from_published_areas(): void
    {
        $t = $this->isp();
        $mgr = $this->user($t, 'manager', 'mgr');
        $r = $this->brain()->startVersion($t, $mgr, 'coverage', 'fiber', 'Fiber coverage', null,
            ['areas' => [['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town']]]]);
        $this->assertTrue($r['ok'], json_encode($r));

        $this->assertSame('survey', $this->brain()->coverage($t, 'fiber', 'Test Town')['status'], 'a draft area does not count');
        $this->brain()->approve($t, $mgr, $r['version_id']);
        $this->brain()->publish($t, $mgr, $r['version_id']);
        $this->assertSame('covered', $this->brain()->coverage($t, 'fiber', 'near Test Town market')['status']);
        $this->assertSame('survey', $this->brain()->coverage($t, 'fiber', 'Unlisted Place')['status']);
    }
}
