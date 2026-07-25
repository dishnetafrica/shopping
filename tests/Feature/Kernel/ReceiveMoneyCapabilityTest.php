<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\BOS\Rules\RuleViolation;
use App\Kernel\Capabilities\Money\ReceiveMoney;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 3A — the money.receive capability, exercised in isolation (no production path calls it yet).
 * Proves: it emits exactly one MoneyReceived fact on the happy path, refuses a settlement that
 * exceeds the balance (R3), and refuses a malformed payload without emitting.
 */
class ReceiveMoneyCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-' . substr(md5(uniqid('', true)), 0, 8)]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    private function input(array $over = []): array
    {
        return array_merge([
            'customer'        => 'Shop no 58',
            'amount'          => 100,
            'currency'        => 'USD',
            'kind'            => 'advance',              // advance is R3-exempt (not applied to a balance)
            'method'          => 'Cash',
            'holder'          => 'Asish',
            'created_by'      => 'Asish',
            'tenant_id'       => $this->tenant->id,
            'idempotency_key' => 'idem-' . uniqid('', true),
        ], $over);
    }

    public function test_happy_path_emits_exactly_one_money_received_event(): void
    {
        $res = (new ReceiveMoney())->run($this->input());

        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('event_id', $res);
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }

    public function test_settlement_exceeding_balance_is_rejected_by_R3(): void
    {
        $this->expectException(RuleViolation::class);

        (new ReceiveMoney())->run($this->input([
            'kind'       => 'settlement',
            'amount'     => 500,
            'balance'    => 100,
            'invoice_no' => 'INV-1',
        ]));
    }

    public function test_settlement_within_balance_is_allowed(): void
    {
        $res = (new ReceiveMoney())->run($this->input([
            'kind'       => 'settlement',
            'amount'     => 100,
            'balance'    => 100,
            'invoice_no' => 'INV-1',
        ]));

        $this->assertTrue($res['ok']);
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }

    public function test_malformed_payload_returns_errors_and_emits_nothing(): void
    {
        $res = (new ReceiveMoney())->run(['amount' => 100]); // missing currency + idempotency_key

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty($res['errors']);
        $this->assertSame(0, Event::where('name', 'PaymentReceived')->count());
    }
}
