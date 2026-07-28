<?php

namespace Tests\Feature;

use App\Models\OrderTask;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task reference numbers must be sequential PER TENANT (each shop starts at #1), independent of the
 * global auto-increment id, and must never be reused or reshuffled when the other shop adds tasks.
 */
class TaskRefNumberTest extends TestCase
{
    use RefreshDatabase;

    private function task(int $tid, string $title): OrderTask
    {
        return OrderTask::create([
            'tenant_id' => $tid, 'order_id' => 0, 'stage' => 'adhoc', 'role' => 'adhoc',
            'title' => $title, 'status' => 'pending',
        ]);
    }

    public function test_ref_no_is_sequential_within_each_tenant(): void
    {
        $a = Tenant::factory()->create()->id;
        $b = Tenant::factory()->create()->id;
        app(TenantContext::class)->set($a);

        // Interleave the two shops' tasks; each shop must still count 1,2,3… on its own.
        $a1 = $this->task($a, 'A first');
        $b1 = $this->task($b, 'B first');
        $a2 = $this->task($a, 'A second');
        $b2 = $this->task($b, 'B second');
        $a3 = $this->task($a, 'A third');

        $this->assertSame(1, $a1->ref_no);
        $this->assertSame(2, $a2->ref_no);
        $this->assertSame(3, $a3->ref_no);
        $this->assertSame(1, $b1->ref_no);
        $this->assertSame(2, $b2->ref_no);

        $this->assertSame('#2', $a2->refLabel());
    }

    public function test_explicit_ref_no_is_not_overwritten(): void
    {
        $a = Tenant::factory()->create()->id;
        $t = OrderTask::create([
            'tenant_id' => $a, 'order_id' => 0, 'stage' => 'adhoc', 'role' => 'adhoc',
            'title' => 'explicit', 'status' => 'pending', 'ref_no' => 99,
        ]);
        $this->assertSame(99, $t->ref_no);
    }

    public function test_ref_label_falls_back_to_id_when_unnumbered(): void
    {
        $t = new OrderTask(['title' => 'x']);
        $t->id = 1234;
        $this->assertSame('#1234', $t->refLabel());
    }
}
