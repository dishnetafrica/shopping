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
 * The V3 Cashbook "Moved cash between people" front door (POST /papi/trade-cashbook/transfer) now
 * flows through the cash.handover capability. Proves the CUTOVER is parity-safe: cash moves from A to
 * B and the company total is conserved; the same two 'Transfer' ledger rows land (so every existing
 * cash screen is unchanged) PLUS exactly one CashHandedOver event; a retry with the same key records
 * once; over-hold is recorded not blocked (observe-only); owner/manager permission is preserved.
 * Factory-independent.
 */
class TradeCashbookHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $t->putSetting('order_system_enabled', true);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => $role,
            'email' => 'owner-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    /** Seed cash into a person's hand (a plain funding entry, like the "Give cash" action). */
    private function fund(Tenant $t, string $person, float $amount, string $cur = 'UGX'): void
    {
        OrderCost::create([
            'tenant_id' => $t->id, 'stage' => 'funding', 'holder' => $person,
            'currency' => $cur, 'native_amount' => $amount, 'rate_to_usd' => 3655, 'usd_amount' => round($amount / 3655, 2),
            'pay_status' => 'cash', 'paid_native' => $amount, 'category' => 'Funding / Float',
            'note' => 'seed', 'status' => 'approved', 'created_by' => 'Owner',
        ]);
    }

    public function test_transfer_moves_cash_and_conserves_total(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 1000);

        $this->postJson('/papi/trade-cashbook/transfer', [
            'from_person' => 'Asish', 'to_person' => 'Owner', 'currency' => 'UGX', 'amount' => 400,
        ])->assertOk()->assertJson(['ok' => true]);

        $cash = CashService::forTenant($t);
        $this->assertEqualsWithDelta(600.0, $cash->balance(['holder' => 'Asish', 'currency' => 'UGX']), 0.5);
        $this->assertEqualsWithDelta(400.0, $cash->balance(['holder' => 'Owner', 'currency' => 'UGX']), 0.5);
        // Company total unchanged — the money only moved between pockets.
        $this->assertEqualsWithDelta(1000.0, $cash->balance(['currency' => 'UGX']), 0.5);

        // Parity: the same two 'Transfer' rows as before the cutover, PLUS one custody event.
        $this->assertSame(2, OrderCost::where('category', 'Transfer')->count());
        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
    }

    public function test_retry_with_same_key_records_once(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 1000);

        $body = ['from_person' => 'Asish', 'to_person' => 'Owner', 'currency' => 'UGX', 'amount' => 400, 'idempotency_key' => 'tk-1'];
        $this->postJson('/papi/trade-cashbook/transfer', $body)->assertOk();
        $this->postJson('/papi/trade-cashbook/transfer', $body)->assertOk(); // offline resend

        $this->assertSame(2, OrderCost::where('category', 'Transfer')->count()); // not 4
        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
        $this->assertEqualsWithDelta(600.0, CashService::forTenant($t)->balance(['holder' => 'Asish', 'currency' => 'UGX']), 0.5);
    }

    public function test_over_hold_is_recorded_not_blocked(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 100);   // holds only 100

        $this->postJson('/papi/trade-cashbook/transfer', [
            'from_person' => 'Asish', 'to_person' => 'Owner', 'currency' => 'UGX', 'amount' => 500,
        ])->assertOk()->assertJson(['ok' => true]);   // observe-only — it goes through

        $this->assertSame(1, Event::where('name', CashHandedOver::NAME)->count());
    }

    public function test_same_person_is_rejected(): void
    {
        $t = $this->boot();
        $this->fund($t, 'Asish', 100);

        $this->postJson('/papi/trade-cashbook/transfer', [
            'from_person' => 'Asish', 'to_person' => 'Asish', 'currency' => 'UGX', 'amount' => 50,
        ])->assertStatus(422);
    }

    public function test_non_manager_is_refused(): void
    {
        // A plain staff member cannot move cash between people — owner/manager only (unchanged).
        $t = $this->boot('staff');
        $this->fund($t, 'Asish', 500);

        $this->postJson('/papi/trade-cashbook/transfer', [
            'from_person' => 'Asish', 'to_person' => 'Owner', 'currency' => 'UGX', 'amount' => 100,
        ])->assertStatus(403);
    }
}
