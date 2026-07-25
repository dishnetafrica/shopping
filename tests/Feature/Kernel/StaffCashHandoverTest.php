<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Kernel\Events\CashHandedOver;
use App\Models\OrderCost;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\CashService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end custody handover through the staff-cash front door. Proves: cash moves from A to B and
 * the company total is conserved; exactly one CashHandedOver event and two 'Transfer' ledger rows
 * land (which every existing cash screen reads); a retry with the same key records once (offline
 * safety); over-hold is recorded not blocked (observe-only); and permission holds (only an
 * owner/manager or the giver themselves may record it). Factory-independent.
 */
class StaffCashHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $t->putSetting('order_system_enabled', true);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'owner-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    /** Seed cash into a person's hand (a plain funding entry, exactly like the "Give cash" action). */
    private function fund(Tenant $t, string $person, float $amount, string $cur = 'UGX'): void
    {
        OrderCost::create([
            'tenant_id' => $t->id, 'stage' => 'funding', 'holder' => $person,
            'currency' => $cur, 'native_amount' => $amount, 'rate_to_usd' => 3655, 'usd_amount' => round($amount / 3655, 2),
            'pay_status' => 'cash', 'paid_native' => $amount, 'category' => 'Funding / Float',
            'note' => 'seed', 'status' => 'approved', 'created_by' => 'Owner',
        ]);
    }

    public function test_handover_moves_cash_and_conserves_total(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 1000);

        $this->postJson('/papi/staff-cash/handover', [
            'from' => 'Asish', 'to' => 'Owner', 'currency' => 'UGX', 'amount' => 400, 'rate_to_usd' => 3655,
        ])->assertOk()->assertJson(['ok' => true]);

        $cash = CashService::forTenant($t);
        $this->assertEqualsWithDelta(600.0, $cash->balance(['holder' => 'Asish', 'currency' => 'UGX']), 0.5);
        $this->assertEqualsWithDelta(400.0, $cash->balance(['holder' => 'Owner', 'currency' => 'UGX']), 0.5);
        // Company total is unchanged — the money only moved between pockets.
        $this->assertEqualsWithDelta(1000.0, $cash->balance(['currency' => 'UGX']), 0.5);

        $this->assertSame(2, OrderCost::where('category', 'Transfer')->count());
        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
    }

    public function test_retry_with_same_key_records_once(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 1000);

        $body = ['from' => 'Asish', 'to' => 'Owner', 'currency' => 'UGX', 'amount' => 400, 'rate_to_usd' => 3655, 'idempotency_key' => 'hk-1'];
        $this->postJson('/papi/staff-cash/handover', $body)->assertOk();
        $this->postJson('/papi/staff-cash/handover', $body)->assertOk(); // offline resend

        $this->assertSame(2, OrderCost::where('category', 'Transfer')->count()); // not 4
        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
        $this->assertEqualsWithDelta(600.0, CashService::forTenant($t)->balance(['holder' => 'Asish', 'currency' => 'UGX']), 0.5);
    }

    public function test_over_hold_is_recorded_not_blocked(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 100);   // holds only 100

        $this->postJson('/papi/staff-cash/handover', [
            'from' => 'Asish', 'to' => 'Owner', 'currency' => 'UGX', 'amount' => 500, 'rate_to_usd' => 3655,
        ])->assertOk()->assertJson(['ok' => true]);   // observe-only — it goes through

        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
    }

    public function test_same_person_is_rejected(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 100);

        $this->postJson('/papi/staff-cash/handover', [
            'from' => 'Asish', 'to' => 'Asish', 'currency' => 'UGX', 'amount' => 50, 'rate_to_usd' => 3655,
        ])->assertStatus(422);
    }

    public function test_staff_cannot_hand_over_someone_elses_cash(): void
    {
        $t = $this->boot(); // owner set up
        $staff = User::create([
            'tenant_id' => $t->id, 'name' => 'Musa', 'role' => 'staff',
            'email' => 'musa-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        $this->actingAs($staff);
        $this->fund($t, 'Asish', 500);

        // Musa (a staff member) tries to hand over Asish's cash — not his own — and is refused.
        $this->postJson('/papi/staff-cash/handover', [
            'from' => 'Asish', 'to' => 'Owner', 'currency' => 'UGX', 'amount' => 100, 'rate_to_usd' => 3655,
        ])->assertStatus(403);
    }
}
