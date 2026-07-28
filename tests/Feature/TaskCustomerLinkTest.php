<?php

namespace Tests\Feature;

use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A task created off a customer page carries the customer_id so it can be shown back on that
 * customer and named in the Tasks list. assignAdHoc's customer link is optional and backward-compatible.
 */
class TaskCustomerLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);
        $this->assignee = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_assign_adhoc_persists_customer_id(): void
    {
        $res = app(TaskEngine::class)->assignAdHoc(
            $this->tenant, $this->assignee, 'Collect USD 68 from Mehul Patel',
            null, 1, null, 0, 77
        );
        $this->assertTrue($res['ok'] ?? false);

        $task = OrderTask::find($res['task_id']);
        $this->assertSame(77, $task->customer_id);
        $this->assertSame(0, (int) $task->order_id);   // still ad-hoc (no order)
    }

    public function test_customer_id_is_null_when_not_given(): void
    {
        $res = app(TaskEngine::class)->assignAdHoc($this->tenant, $this->assignee, 'Generic task');
        $task = OrderTask::find($res['task_id']);
        $this->assertNull($task->customer_id);
    }

    public function test_attachment_url_is_persisted(): void
    {
        $url = 'https://files.example/ops/1/task_abc.pdf';
        $res = app(TaskEngine::class)->assignAdHoc(
            $this->tenant, $this->assignee, 'With a doc', null, 1, null, 0, 77, $url
        );
        $task = OrderTask::find($res['task_id']);
        $this->assertSame($url, $task->attachment);
    }
}
