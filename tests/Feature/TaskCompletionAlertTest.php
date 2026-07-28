<?php

namespace Tests\Feature;

use App\Jobs\NotifyOwner;
use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Completing a manually-assigned (ad-hoc) task fires two best-effort WhatsApps: a receipt to the
 * person who did it and an alert to the owner number(s). Order-workflow stage completions must NOT
 * notify (would spam), and the `task_done_alerts` setting must be able to mute it.
 */
class TaskCompletionAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $assignee;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->tenant->forceFill(['whatsapp_instance' => 'inst-test'])->save();
        $this->tenant->putSetting('owner_alert_phone', '256700000001');
        app(TenantContext::class)->set($this->tenant->id);

        $this->assignee = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Savan', 'phone' => '256711111111']);
        $this->manager  = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bhavin', 'phone' => '256722222222']);
    }

    private function adhoc(): OrderTask
    {
        return OrderTask::create([
            'tenant_id' => $this->tenant->id, 'order_id' => 0, 'stage' => 'adhoc', 'role' => 'adhoc',
            'title' => 'Buy stock', 'status' => 'pending', 'owner_user_id' => $this->assignee->id,
        ]);
    }

    public function test_adhoc_completion_notifies_assignee_and_owner(): void
    {
        Bus::fake([NotifyOwner::class]);
        $task = $this->adhoc();

        app(TaskEngine::class)->complete($this->tenant, $task, $this->manager, ['note' => 'bought 5 boxes']);

        // One receipt to the assignee's phone + one alert to the owner-alert number.
        Bus::assertDispatchedTimes(NotifyOwner::class, 2);
        Bus::assertDispatched(NotifyOwner::class, fn (NotifyOwner $j) => $j->to === '256711111111');
        Bus::assertDispatched(NotifyOwner::class, fn (NotifyOwner $j) => $j->to === '256700000001');
    }

    public function test_completion_via_link_notifies(): void
    {
        Bus::fake([NotifyOwner::class]);
        $task = $this->adhoc();

        // No logged-in user on the public link path.
        app(TaskEngine::class)->completeByNonce($task, 'done from the road');

        Bus::assertDispatchedTimes(NotifyOwner::class, 2);
    }

    public function test_workflow_stage_completion_does_not_notify(): void
    {
        Bus::fake([NotifyOwner::class]);
        // order_id > 0 = part of an order workflow, not an assigned ad-hoc task.
        $task = OrderTask::create([
            'tenant_id' => $this->tenant->id, 'order_id' => 5, 'stage' => 'pick', 'role' => 'warehouse',
            'title' => 'Pick items', 'status' => 'in_progress', 'owner_user_id' => $this->assignee->id,
        ]);

        app(TaskEngine::class)->setStatusById($this->tenant, $task->id, 'completed', $this->manager);

        Bus::assertNotDispatched(NotifyOwner::class);
    }

    public function test_setting_off_suppresses_alerts(): void
    {
        $this->tenant->putSetting('task_done_alerts', false);
        Bus::fake([NotifyOwner::class]);
        $task = $this->adhoc();

        app(TaskEngine::class)->setStatusById($this->tenant, $task->id, 'completed', $this->manager);

        Bus::assertNotDispatched(NotifyOwner::class);
    }
}
