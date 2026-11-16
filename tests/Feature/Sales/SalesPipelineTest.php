<?php

namespace Tests\Feature\Sales;

use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesPipelineEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Standard sales pipeline (DishNet plan, Phase 6) through the real workspace API: a lead walks the gated
 * stages from enquiry to installed, the hand-over creates the installation job, every move is recorded,
 * salespeople keep to their own customers, and other business types are untouched.
 */
class SalesPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '211911000001';

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

    private function user(Tenant $t, string $role, string $name): User
    {
        return User::create(['tenant_id' => $t->id, 'name' => $name, 'role' => $role, 'phone' => '2119220' . random_int(10000, 99999), 'email' => 'u-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
    }

    private function lead(Tenant $t, ?User $owner, string $phone = self::PHONE): Lead
    {
        return Lead::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'customer_name' => 'Test Customer', 'intent' => 'lead',
            'interest' => 'Fibre for the house', 'status' => $owner ? 'assigned' : 'new', 'owner_user_id' => $owner?->id, 'source' => 'whatsapp']);
    }

    private function signIn(User $u): void
    {
        app(TenantContext::class)->set($u->tenant_id);
        $this->actingAs($u);
    }

    private function move(int $id, string $to, string $reason = ''): array
    {
        return $this->postJson('/api/v1/workspace/pipelineLead/' . $id . '/move', ['stage' => $to, 'reason' => $reason])->assertOk()->json();
    }

    public function test_a_lead_walks_the_gated_stages_from_enquiry_to_installed(): void
    {
        $t = $this->business();
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $brain = app(SalesBrain::class);
        $r = $brain->startVersion($t, $mgr, 'coverage', 'fiber', 'Fiber coverage', null, ['areas' => [['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town']]]]);
        $brain->approve($t, $mgr, $r['version_id']);
        $brain->publish($t, $mgr, $r['version_id']);

        $akol = $this->user($t, 'sales', 'Akol Deng');
        $l = $this->lead($t, $akol);
        $this->signIn($akol);

        $row = collect($this->getJson('/api/v1/workspace/salesPipeline')->assertOk()->json('table.rows'))->first();
        $this->assertSame(['New enquiry', 'Akol Deng'], [$row['stage']['value'], $row['owner']['value']]);

        $this->assertTrue($this->move($l->id, 'contacted')['ok']);
        $refused = $this->move($l->id, 'qualified');
        $this->assertSame('gate', $refused['error']);
        $this->assertStringContainsString('the exact area or landmark', $refused['message']);

        $this->postJson('/api/v1/workspace/pipelineLead/' . $l->id . '/qualify', ['service_line' => 'fiber', 'area' => 'Test Town, near the market', 'customer_type' => 'home', 'users' => 4])->assertJson(['ok' => true]);
        $this->assertTrue($this->move($l->id, 'qualified')['ok']);
        $cov = $this->postJson('/api/v1/workspace/pipelineLead/' . $l->id . '/check_coverage')->assertOk()->json();
        $this->assertSame('covered', $cov['result']['status']);
        $this->assertTrue($this->move($l->id, 'site_check')['ok']);

        $this->assertSame(['a quotation sent to this customer'], $this->move($l->id, 'quoted')['missing']);
        $q = Quotation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'quote_no' => 'QUO-T-1', 'customer_phone' => self::PHONE, 'currency' => 'USD', 'total' => 150, 'status' => 'sent', 'source' => 'test']);
        $this->assertTrue($this->move($l->id, 'quoted')['ok']);
        $this->assertSame('gate', $this->move($l->id, 'won')['error'], 'not won until the customer accepts');
        $q->update(['status' => 'accepted']);
        $this->assertTrue($this->move($l->id, 'won')['ok']);

        $hand = $this->move($l->id, 'installation');
        $this->assertTrue($hand['ok']);
        $job = WorkOrder::withoutGlobalScopes()->findOrFail($hand['work_order_id']);
        $this->assertSame(['installation', (int) $q->id, 'Test Town, near the market', self::PHONE], [$job->type, (int) $job->quotation_id, $job->site_address, $job->customer_phone]);
        $this->assertSame('won', $l->fresh()->status, 'the Leads screen shows it as won');

        $this->assertSame(['the installation job completed'], $this->move($l->id, 'active')['missing']);
        $job->update(['status' => 'completed']);
        $this->assertTrue($this->move($l->id, 'active')['ok']);
        $this->assertSame('final', $this->move($l->id, 'lost', 'x')['error']);

        $this->assertSame(['contacted', 'qualified', 'site_check', 'quoted', 'won', 'installation', 'active'],
            SalesPipelineEvent::withoutGlobalScopes()->where('lead_id', $l->id)->orderBy('id')->pluck('to_stage')->all());
        $page = $this->getJson('/api/v1/workspace/pipelineLead/' . $l->id)->assertOk()->json();
        $this->assertStringContainsString('UISP', json_encode($page['answers']));
    }

    public function test_salespeople_keep_to_their_own_customers_and_lost_needs_a_reason(): void
    {
        $t = $this->business();
        $akol = $this->user($t, 'sales', 'Akol Deng');
        $bob = $this->user($t, 'sales', 'Bob Lado');
        $mgr = $this->user($t, 'manager', 'Mary Manager');
        $mine = $this->lead($t, $akol);
        $free = $this->lead($t, null, '211911000002');

        $this->signIn($bob);
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/pipelineLead/' . $mine->id)->json('meta.error'));
        $this->postJson('/api/v1/workspace/pipelineLead/' . $mine->id . '/move', ['stage' => 'contacted'])->assertJson(['ok' => false, 'error' => 'not_found']);
        $this->assertSame([$free->id], collect($this->getJson('/api/v1/workspace/salesPipeline')->json('table.rows'))->map(fn ($r) => (int) basename($r['link']))->all(), 'only unassigned (and own) leads are listed');
        $this->assertTrue($this->move($free->id, 'contacted')['ok']);
        $this->assertSame((int) $bob->id, (int) $free->fresh()->owner_user_id, 'working an unassigned lead makes it yours');

        $this->assertSame('reason_required', $this->move($free->id, 'lost')['error']);
        $this->assertTrue($this->move($free->id, 'lost', 'Chose another provider')['ok']);
        $this->assertSame(['lost', 'Chose another provider'], [$free->fresh()->status, $free->fresh()->lost_reason]);
        $this->assertSame('manager_only', $this->move($free->id, 'contacted', 'Came back')['error']);

        $this->signIn($mgr);
        $this->assertTrue($this->move($free->id, 'contacted', 'Customer came back')['ok']);
        $this->assertSame('contacted', $free->fresh()->status);
    }

    public function test_another_business_type_has_no_pipeline(): void
    {
        $t = $this->business('grocery');
        $owner = $this->user($t, 'owner', 'Shop Owner');
        $l = $this->lead($t, null);
        $this->signIn($owner);

        $this->assertSame('Available for Internet providers', $this->getJson('/api/v1/workspace/salesPipeline')->assertOk()->json('object.subtitle'));
        $this->assertSame('not_found', $this->getJson('/api/v1/workspace/pipelineLead/' . $l->id)->json('meta.error'));
        $this->assertNull($l->fresh()->stage);
    }
}
