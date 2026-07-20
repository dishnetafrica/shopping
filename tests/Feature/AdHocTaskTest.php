<?php

namespace Tests\Feature;

use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ad-hoc task assignment: a manager assigns a one-off task to a staff member; it
 * appears in that person's My Tasks and flows through the existing reminders. No new
 * table — reuses order_tasks with order_id=0, stage='adhoc'.
 */
class AdHocTaskTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $mgr = User::create(['tenant_id'=>$t->id,'name'=>'Manager','phone'=>'100','role'=>'owner','password'=>'x']);
        $savan = User::create(['tenant_id'=>$t->id,'name'=>'Savan','phone'=>'200','role'=>'staff','password'=>'x']);
        return [$t,$mgr,$savan];
    }

    public function test_assign_ad_hoc_creates_task_for_the_person(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();

        $res = app(TaskEngine::class)->assignAdHoc($t, $savan, 'Enter the new products', null, 2, $mgr);
        $this->assertTrue($res['ok']);

        $task = OrderTask::find($res['task_id']);
        $this->assertNotNull($task);
        $this->assertSame($savan->id, $task->owner_user_id);
        $this->assertSame('Enter the new products', $task->title);
        $this->assertSame(0, (int) $task->order_id, 'ad-hoc task has no order');
        $this->assertSame('adhoc', $task->stage);
        $this->assertSame('pending', $task->status);
        $this->assertNotNull($task->due_at, 'default due date applied');
    }

    public function test_ad_hoc_task_appears_in_persons_my_tasks(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        app(TaskEngine::class)->assignAdHoc($t, $savan, 'Count the fridge stock', null, 2, $mgr);

        $mine = app(TaskEngine::class)->myTasks($t, $savan);
        $this->assertCount(1, $mine);
        $this->assertSame('Count the fridge stock', $mine->first()->title);
    }

    public function test_empty_title_is_rejected(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $res = app(TaskEngine::class)->assignAdHoc($t, $savan, '   ', null, 2, $mgr);
        $this->assertFalse($res['ok']);
    }

    public function test_cross_tenant_assignee_is_rejected(): void
    {
        Queue::fake();
        [$t,$mgr,] = $this->seed();
        $otherTenant = Tenant::factory()->create();
        $outsider = User::create(['tenant_id'=>$otherTenant->id,'name'=>'Outsider','phone'=>'900','role'=>'staff','password'=>'x']);

        $res = app(TaskEngine::class)->assignAdHoc($t, $outsider, 'Should fail', null, 2, $mgr);
        $this->assertFalse($res['ok']);
    }

    public function test_explicit_due_date_is_honoured(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        $due = now()->addDays(3)->setTime(12, 0);
        $res = app(TaskEngine::class)->assignAdHoc($t, $savan, 'Deadline task', $due, 3, $mgr);

        $task = OrderTask::find($res['task_id']);
        $this->assertSame($due->toDateTimeString(), $task->due_at->toDateTimeString());
        $this->assertSame(3, (int) $task->priority);
    }

    public function test_order_derived_task_queries_ignore_ad_hoc_rows(): void
    {
        Queue::fake();
        [$t,$mgr,$savan] = $this->seed();
        app(TaskEngine::class)->assignAdHoc($t, $savan, 'Ad hoc', null, 2, $mgr);

        // A query for real order tasks (order_id > 0) must not pick up the ad-hoc row.
        $orderTasks = OrderTask::where('tenant_id',$t->id)->where('order_id','>',0)->count();
        $this->assertSame(0, $orderTasks);
    }
}
