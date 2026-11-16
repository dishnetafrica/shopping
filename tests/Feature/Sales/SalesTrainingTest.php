<?php

namespace Tests\Feature\Sales;

use App\Models\SalesTrainingAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\Training\Assessment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales training and certification (DishNet plan, Phase 5) through the real workspace API: the
 * assessment opens only when every module has PUBLISHED questions, the answer key is never shown
 * before submission, scoring is weighted with an 80% pass, results are private to the person and
 * their managers, and training questions never leak into salespeople's reading or the bot.
 */
class SalesTrainingTest extends TestCase
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

    private function user(Tenant $t, string $role, string $name): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => $name, 'role' => $role, 'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    /** Publish two questions for each listed module, through the governed Sales Brain. */
    private function publishQuiz(Tenant $t, User $mgr, array $modules): void
    {
        $brain = app(SalesBrain::class);
        foreach ($modules as $m) {
            $text = "Q: {$m} first question?\n* {$m} right one\n- {$m} wrong one\nWhy: {$m} EXPLANATION TEXT\n\nQ: {$m} second question?\n- {$m} wrong two\n* {$m} right two";
            $r = $brain->startVersion($t, $mgr, 'quiz', $m, ucfirst($m) . ' questions', null, ['module' => $m, 'questions' => Assessment::parseQuestions($text)]);
            $this->assertTrue($r['ok'], json_encode($r));
            $brain->approve($t, $mgr, $r['version_id']);
            $brain->publish($t, $mgr, $r['version_id']);
        }
    }

    /** The answers form, filled from the stored key ($wrongModules answered wrong). */
    private function answersFor(int $attemptId, array $wrongModules = []): array
    {
        $qs = SalesTrainingAttempt::withoutGlobalScopes()->findOrFail($attemptId)->questions;
        $in = [];
        foreach ($qs as $i => $q) {
            $idx = in_array($q['module'], $wrongModules, true) ? ($q['answer'] + 1) % count($q['options']) : $q['answer'];
            $in['q' . ($i + 1)] = Assessment::LETTERS[$idx];
        }

        return $in;
    }

    public function test_the_assessment_opens_only_when_every_module_has_published_questions(): void
    {
        $t = $this->business();
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $this->publishQuiz($t, $mgr, ['product', 'qualification', 'communication', 'quotation']);    // service missing
        $this->signIn($this->user($t, 'sales', 'Akol Deng'));

        $list = $this->getJson('/api/v1/workspace/salesTraining')->assertOk()->json();
        $this->assertSame('Not ready', collect($list['financials'])->firstWhere('key', 'ready')['value']);
        $this->assertNotContains('start_assessment', collect($list['actions'])->pluck('key')->all());
        $this->postJson('/api/v1/workspace/salesTraining/_/start_assessment')->assertOk()->assertJson(['ok' => false, 'error' => 'not_ready', 'missing' => ['service']]);
    }

    public function test_sitting_scoring_and_certification(): void
    {
        $t = $this->business();
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $this->publishQuiz($t, $mgr, array_keys(Assessment::MODULES));
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $this->signIn($akol);

        $r = $this->postJson('/api/v1/workspace/salesTraining/_/start_assessment')->assertOk()->json();
        $this->assertTrue($r['ok'], json_encode($r));
        $base = '/api/v1/workspace/trainingAttempt/' . $r['id'];
        $open = $this->getJson($base)->assertOk()->json();
        $this->assertSame('In progress', $open['headline']['value']);
        $this->assertCount(10, $open['actions'][0]['schema'], '2 questions × 5 modules');
        $this->assertStringNotContainsString('EXPLANATION TEXT', json_encode($open), 'nothing about the answers before submitting');

        // Wrong on all of Service (10%) and Quotation (20%) → 70%: not passed.
        $this->postJson($base . '/submit', $this->answersFor($r['id'], ['service', 'quotation']))->assertOk()->assertJson(['ok' => true, 'score' => 70, 'passed' => false]);
        $this->postJson($base . '/submit', $this->answersFor($r['id']))->assertOk()->assertJson(['ok' => false, 'error' => 'already_submitted']);
        $done = $this->getJson($base)->assertOk()->json();
        $this->assertSame('Not passed', $done['headline']['value']);
        $this->assertStringContainsString('EXPLANATION TEXT', json_encode($done), 'after submitting, the reasons are shown');

        // A second sitting, all correct → certified.
        $r2 = $this->postJson('/api/v1/workspace/salesTraining/_/start_assessment')->assertOk()->json();
        $this->assertNotSame($r['id'], $r2['id']);
        $this->postJson('/api/v1/workspace/trainingAttempt/' . $r2['id'] . '/submit', $this->answersFor($r2['id']))->assertOk()->assertJson(['ok' => true, 'score' => 100, 'passed' => true]);
        $list = $this->getJson('/api/v1/workspace/salesTraining')->assertOk()->json();
        $this->assertSame('Certified', collect($list['financials'])->firstWhere('key', 'certified')['value']);

        // Management's view.
        $this->signIn($mgr);
        $team = $this->getJson('/api/v1/workspace/trainingResults')->assertOk()->json();
        $row = collect($team['table']['rows'])->first(fn ($x) => $x['person']['value'] === 'Akol Deng');
        $this->assertStringStartsWith('Certified', $row['status']['value']);
        $this->assertSame('100%', $row['last']['value']);
    }

    public function test_results_are_private_and_questions_never_reach_sales_reading_or_the_bot(): void
    {
        $t = $this->business();
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $this->publishQuiz($t, $mgr, array_keys(Assessment::MODULES));
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $bob = $this->user($t, 'sales', 'Bob Lado');

        $this->signIn($akol);
        $id = $this->postJson('/api/v1/workspace/salesTraining/_/start_assessment')->json('id');
        $this->signIn($bob);
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/trainingAttempt/' . $id)->json('meta.error'));
        $this->postJson('/api/v1/workspace/trainingAttempt/' . $id . '/submit', ['q1' => 'A'])->assertJson(['ok' => false, 'error' => 'not_found']);
        $this->signIn($mgr);
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/trainingAttempt/' . $id)->json('meta.error'), 'not even a manager sees a sitting in progress');

        $this->signIn($bob);
        $brain = $this->getJson('/api/v1/workspace/salesBrain')->assertOk()->json();
        $this->assertStringNotContainsString('questions', strtolower(json_encode(collect($brain['table']['rows'])->pluck('entry.value'))), 'quiz entries are hidden from sales staff');
        $this->assertStringNotContainsString('EXPLANATION TEXT', \App\Services\Isp\IspSalesTurn::knowledgeBlock(app(SalesBrain::class)->published($t)), 'and never given to the bot or the coach');
    }

    public function test_another_business_type_has_no_training(): void
    {
        $t = $this->business('grocery');
        $this->signIn($this->user($t, 'owner', 'Shop Owner'));

        $this->assertSame('Available for Internet providers', $this->getJson('/api/v1/workspace/salesTraining')->assertOk()->json('object.subtitle'));
        $this->postJson('/api/v1/workspace/salesTraining/_/start_assessment')->assertJson(['ok' => false, 'error' => 'not_available']);
    }
}
