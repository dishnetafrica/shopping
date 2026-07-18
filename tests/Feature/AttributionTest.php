<?php

namespace Tests\Feature;

use App\Models\FollowupProposal;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V4.3: attribution is honest math — after the send, inside the window, first order only. */
class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('followups_ai', true);
        $t->putSetting('relationship_memory', true);
        return $t;
    }

    private function sentProposal(Tenant $t, string $phone, $sentAt): FollowupProposal
    {
        $p = FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => $phone,
            'trigger' => 'reorder', 'trigger_reason' => 'cycle', 'draft' => 'd',
            'confidence' => 'medium', 'status' => 'sent']);
        FollowupProposal::where('id', $p->id)->update(['updated_at' => $sentAt]);
        return $p->fresh();
    }

    private function order(Tenant $t, string $phone, $at, float $total = 250): Order
    {
        $o = Order::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => $phone,
            'customer_name' => 'X', 'items' => [], 'total' => $total, 'status' => 'completed']);
        Order::withoutGlobalScopes()->where('id', $o->id)->update(['created_at' => $at]);
        return $o->fresh();
    }

    public function test_order_after_send_inside_window_gets_linked(): void
    {
        $t = $this->tenant();
        $p = $this->sentProposal($t, '211900000041', now()->subDays(3));
        $o = $this->order($t, '211900000041', now()->subDays(1), 480);

        $this->artisan('relationship:attribute', ['--tenant' => $t->id])->assertExitCode(0);

        $this->assertSame($o->id, (int) $p->fresh()->influenced_order_id);
    }

    public function test_orders_before_the_send_or_outside_the_window_never_count(): void
    {
        $t = $this->tenant();
        $p1 = $this->sentProposal($t, '211900000042', now()->subDays(2));
        $this->order($t, '211900000042', now()->subDays(5));            // BEFORE the send

        $p2 = $this->sentProposal($t, '211900000043', now()->subDays(12));
        $this->order($t, '211900000043', now()->subDays(1));            // 11 days later — outside 7d window

        $this->artisan('relationship:attribute', ['--tenant' => $t->id])->assertExitCode(0);

        $this->assertNull($p1->fresh()->influenced_order_id, 'an earlier order proves nothing');
        $this->assertNull($p2->fresh()->influenced_order_id, 'outside the window = not ours to claim');
    }

    public function test_review_report_runs_and_flags_graduation_honestly(): void
    {
        $t = $this->tenant();
        // 10 sent reorder follow-ups, 10 conversions → the ≥90% over ≥10 graduation line must print
        for ($i = 0; $i < 10; $i++) {
            $ph = '2119000001'.sprintf('%02d', $i);
            $p = $this->sentProposal($t, $ph, now()->subDays(5));
            $o = $this->order($t, $ph, now()->subDays(4));
            $p->influenced_order_id = $o->id;
            $p->save();
        }
        FollowupProposal::create(['tenant_id' => $t->id, 'customer_phone' => '211900000099',
            'trigger' => 'winback', 'trigger_reason' => 'dormant', 'draft' => 'd', 'confidence' => 'low',
            'status' => 'skipped', 'skip_reason' => 'He visits the shop weekly anyway']);

        $this->artisan('relationship:review', ['--tenant' => $t->id])
            ->expectsOutputToContain('EARNED auto-send consideration')
            ->expectsOutputToContain('He visits the shop weekly anyway')
            ->expectsOutputToContain('Nothing here rewrites a prompt')
            ->assertExitCode(0);
    }
}
