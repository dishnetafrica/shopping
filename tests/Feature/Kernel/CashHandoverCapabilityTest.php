<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Kernel\Capabilities\Cash\HandOverCash;
use App\Kernel\Events\CashHandedOver;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cash.handover capability in isolation: its ONLY side effect is emitting CashHandedOver, it is
 * idempotent at the spine (a retried key returns the existing fact, never a second event), the
 * observe-only over-hold rule never blocks, and an invalid handover emits nothing.
 * Factory-independent.
 */
class CashHandoverCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        app(TenantContext::class)->set($t->id);
        return $t;
    }

    private function input(Tenant $t, array $over = []): array
    {
        return array_merge([
            'from_holder' => 'Asish', 'to_holder' => 'Owner', 'amount' => 400.0, 'currency' => 'UGX',
            'usd' => 0.11, 'rate' => 3655.0, 'created_by' => 'Owner', 'tenant_id' => $t->id,
            'idempotency_key' => 'hand-key-1',
        ], $over);
    }

    private function eventCount(Tenant $t): int
    {
        return Event::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('name', CashHandedOver::NAME)->count();
    }

    public function test_emits_one_event_and_reports_new(): void
    {
        $t = $this->tenant();
        $r = (new HandOverCash())->run($this->input($t));
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['duplicate']);
        $this->assertSame(1, $this->eventCount($t));
    }

    public function test_retry_with_same_key_is_idempotent(): void
    {
        $t = $this->tenant();
        $cap = new HandOverCash();
        $a = $cap->run($this->input($t));
        $b = $cap->run($this->input($t));   // offline resend — same idempotency_key
        $this->assertFalse($a['duplicate']);
        $this->assertTrue($b['duplicate']);
        $this->assertSame($a['event_id'], $b['event_id']);
        $this->assertSame(1, $this->eventCount($t));
    }

    public function test_over_hold_is_observed_not_blocked(): void
    {
        $t = $this->tenant();
        // holds only 100 but hands over 400 — R6 is observe-only: it records, it never throws or blocks.
        $r = (new HandOverCash())->run($this->input($t, ['from_balance' => 100.0]));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->eventCount($t));
    }

    public function test_invalid_input_emits_nothing(): void
    {
        $t = $this->tenant();
        $r = (new HandOverCash())->run($this->input($t, ['to_holder' => 'Asish'])); // same person
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['errors']);
        $this->assertSame(0, $this->eventCount($t));
    }
}
