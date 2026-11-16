<?php

namespace Tests\Feature\Sales;

use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Sales Brain screens (V3 workspace API): a manager writes, answers questions, approves and
 * publishes; a salesperson sees published knowledge only and cannot change anything; other
 * business types do not get the screen's content. Texts are test values.
 */
class SalesBrainWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical = 'isp'): Tenant
    {
        return Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
    }

    private function user(Tenant $t, string $role): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => ucfirst($role) . ' Person', 'role' => $role,
            'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    private function entryId(Tenant $t, string $topic, string $key): int
    {
        return (int) SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $t->id)->where('topic', $topic)->where('key', $key)->value('id');
    }

    public function test_a_manager_writes_approves_and_publishes_from_the_screens(): void
    {
        $t = $this->business();
        $this->signIn($this->user($t, 'manager'));

        $caps = collect($this->getJson('/api/v1/workspace/')->assertOk()->json('capabilities'));
        $this->assertTrue($caps->contains(fn ($c) => ($c['key'] ?? '') === 'salesBrain'));

        $this->postJson('/api/v1/workspace/salesBrain/_/new_entry', ['topic' => 'terms', 'title' => 'Fiber terms', 'key' => 'fiber',
            'content' => "Payment: Test monthly in advance\nContract: Test three months"])->assertOk()->assertJson(['ok' => true]);
        $id = $this->entryId($t, 'terms', 'fiber');

        $list = $this->getJson('/api/v1/workspace/salesBrain')->assertOk()->json();
        $row = collect($list['table']['rows'])->first(fn ($r) => str_ends_with($r['link'], '/salesKnowledge/' . $id));
        $this->assertSame(['Not published', 'Draft version 1 · ready to approve'], [$row['live']['value'], $row['pending']['value']]);

        $base = '/api/v1/workspace/salesKnowledge/' . $id;
        $keys = collect($this->getJson($base)->assertOk()->json('actions'))->pluck('key')->all();
        $this->assertSame(['approve', 'edit_draft', 'reject'], $keys);

        $this->postJson($base . '/edit_draft', ['content' => "Payment: Test monthly in advance\nContract: Test six months"])->assertOk()->assertJson(['ok' => true]);
        $this->postJson($base . '/publish')->assertOk()->assertJson(['ok' => false]);           // not approved yet
        $this->postJson($base . '/approve')->assertOk()->assertJson(['ok' => true]);
        $this->postJson($base . '/edit_draft', ['content' => 'Payment: changed'])->assertOk()->assertJson(['ok' => false]);   // approved text is fixed
        $this->postJson($base . '/publish')->assertOk()->assertJson(['ok' => true]);

        $j = $this->getJson($base)->assertOk()->json();
        $this->assertSame('Live', $j['headline']['value']);
        $this->assertStringContainsString('Contract: Test six months', json_encode($j['answers']));
        $this->assertSame(['new_version', 'retire'], collect($j['actions'])->pluck('key')->all());
        $this->assertSame(['Version 1 published', 'Version 1 approved', 'Version 1 drafted'], collect($j['timeline'])->pluck('title')->all());
    }

    public function test_imported_template_terms_need_every_question_answered_before_approval(): void
    {
        $t = $this->business();
        $this->artisan('sales-brain:import-template', ['tenant' => $t->id])->assertExitCode(0);
        $this->signIn($this->user($t, 'owner'));
        $base = '/api/v1/workspace/salesKnowledge/' . $this->entryId($t, 'terms', 'starlink');

        $j = $this->getJson($base)->assertOk()->json();
        $this->assertSame('5', collect($j['stats'])->firstWhere('key', 'questions')['value']);
        $this->assertSame(['answer_question', 'edit_draft', 'reject'], collect($j['actions'])->pluck('key')->all(), 'no approve while questions are open');
        $this->postJson($base . '/approve')->assertOk()->assertJson(['ok' => false, 'error' => 'open_questions']);

        for ($i = 5; $i >= 1; $i--) {
            $this->postJson($base . '/answer_question', ['question' => '1 — x', 'answer' => 'Test answer ' . $i])->assertOk()->assertJson(['ok' => true]);
        }
        $this->postJson($base . '/approve')->assertOk()->assertJson(['ok' => true]);
        $v = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->where('status', 'approved')->sole();
        $this->assertCount(5, $v->resolved_questions);
    }

    public function test_a_salesperson_sees_published_knowledge_only_and_cannot_change_it(): void
    {
        $t = $this->business();
        $this->artisan('sales-brain:import-template', ['tenant' => $t->id])->assertExitCode(0);
        $this->signIn($this->user($t, 'manager'));
        $this->postJson('/api/v1/workspace/salesBrain/_/new_entry', ['topic' => 'faq', 'title' => 'Install time', 'content' => 'Test answer'])->assertJson(['ok' => true]);
        $faq = '/api/v1/workspace/salesKnowledge/' . $this->entryId($t, 'faq', 'install-time');
        $this->postJson($faq . '/approve')->assertJson(['ok' => true]);
        $this->postJson($faq . '/publish')->assertJson(['ok' => true]);

        $this->signIn($this->user($t, 'sales'));
        $list = $this->getJson('/api/v1/workspace/salesBrain')->assertOk()->json();
        $this->assertSame(['Install time'], collect($list['table']['rows'])->pluck('entry.value')->all(), 'drafts are not shown to sales staff');
        $this->assertSame([], $list['actions'] ?? []);
        $this->assertSame([], $this->getJson($faq)->assertOk()->json('actions'));
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/salesKnowledge/' . $this->entryId($t, 'terms', 'starlink'))->json('meta.error'));
        $this->postJson($faq . '/retire', ['reason' => 'x'])->assertOk()->assertJson(['ok' => false, 'error' => 'forbidden']);
        $this->postJson('/api/v1/workspace/salesBrain/_/new_entry', ['topic' => 'faq', 'title' => 'X', 'content' => 'Y'])->assertJson(['ok' => false, 'error' => 'forbidden']);
    }

    public function test_another_business_type_gets_no_content_and_cannot_write(): void
    {
        $t = $this->business('grocery');
        $this->signIn($this->user($t, 'owner'));

        $j = $this->getJson('/api/v1/workspace/salesBrain')->assertOk()->json();
        $this->assertSame('Available for Internet providers', $j['object']['subtitle']);
        $this->postJson('/api/v1/workspace/salesBrain/_/new_entry', ['topic' => 'faq', 'title' => 'X', 'content' => 'Y'])->assertJson(['ok' => false, 'error' => 'not_available']);
        $this->assertSame(0, SalesKnowledgeEntry::withoutGlobalScopes()->count());
    }
}
