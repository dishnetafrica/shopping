<?php

namespace Tests\Feature;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Process\TasksCapability;
use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * V3 Tasks capability + tokenized no-login task link. The capability shows a person's
 * tasks and (for managers) assigns; the public /t/{nonce} link lets the assignee
 * accept/complete without logging in.
 */
class V3TasksTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $mgr = User::create(['tenant_id'=>$t->id,'name'=>'Mgr','phone'=>'1','role'=>'owner','password'=>'x']);
        $savan = User::create(['tenant_id'=>$t->id,'name'=>'Savan','phone'=>'2','role'=>'staff','password'=>'x']);
        return [$t,$mgr,$savan];
    }

    public function test_everyone_has_tasks_menu(): void
    {
        [$t,$mgr,$savan] = $this->seed();
        $this->assertContains('tasks', $mgr->effectiveMenus(), 'owner sees tasks');
        $this->assertContains('tasks', $savan->effectiveMenus(), 'staff sees own tasks');
    }

    public function test_capability_lists_the_persons_tasks(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        app(TaskEngine::class)->assignAdHoc($t, $savan, 'Enter book details', null, 2, $mgr);

        $ctx = CapabilityContext::fromUser($savan->fresh());
        $payload = app(TasksCapability::class)->project($ctx)->toArray();

        $json = json_encode($payload);
        $this->assertStringContainsString('Enter book details', $json);
    }

    public function test_manager_can_assign_via_capability_command(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $ctx = CapabilityContext::fromUser($mgr);
        $res = app(TasksCapability::class)->command($ctx, 'assign', [
            'assignee_id' => $savan->id, 'title' => 'Count fridge', 'priority' => 2,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertDatabaseHas('order_tasks', ['owner_user_id'=>$savan->id,'title'=>'Count fridge','stage'=>'adhoc']);
    }

    public function test_non_manager_cannot_assign(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $ctx = CapabilityContext::fromUser($savan);
        $res = app(TasksCapability::class)->command($ctx, 'assign', [
            'assignee_id' => $mgr->id, 'title' => 'Nope', 'priority' => 2,
        ]);
        $this->assertFalse($res['ok']);
        $this->assertSame('forbidden', $res['error']);
    }

    public function test_ad_hoc_task_gets_a_link_nonce(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $res = app(TaskEngine::class)->assignAdHoc($t, $savan, 'With link', null, 2, $mgr);
        $task = OrderTask::find($res['task_id']);
        $this->assertNotEmpty($task->link_nonce, 'ad-hoc task carries a tokenized link nonce');
    }

    public function test_public_link_shows_and_completes_task(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $res = app(TaskEngine::class)->assignAdHoc($t, $savan, 'Public task', null, 2, $mgr);
        $nonce = OrderTask::find($res['task_id'])->link_nonce;

        // Show page (no auth)
        $this->get('/task/' . $nonce)->assertStatus(200)->assertSee('Public task');

        // Complete via link (no auth)
        $this->postJson('/task/' . $nonce . '/complete', ['note' => 'done'])
            ->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertSame('completed', OrderTask::find($res['task_id'])->status);
    }

    public function test_unknown_nonce_is_404(): void
    {
        $this->get('/task/doesnotexist123')->assertStatus(404);
    }

    public function test_nonce_a_cannot_touch_task_b(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $a = OrderTask::find(app(TaskEngine::class)->assignAdHoc($t,$savan,'Task A',null,2,$mgr)['task_id']);
        $b = OrderTask::find(app(TaskEngine::class)->assignAdHoc($t,$savan,'Task B',null,2,$mgr)['task_id']);

        // Completing via A's nonce must only affect A.
        $this->postJson('/task/' . $a->link_nonce . '/complete', [])->assertJson(['ok'=>true]);
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertNotSame('completed', $b->fresh()->status, 'task B untouched');
    }
}
