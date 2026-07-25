<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\BOS\Event;
use App\Kernel\Capabilities\Money\ReceiveMoney;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 3A — the constitutional cure for the due-diligence "double record", proven at the seam.
 * The same money-in, retried (as an offline reconnect would), produces EXACTLY ONE fact.
 * This is Engineering Principle 1 (one effect → one event) made testable before any wiring.
 */
class MoneyReceiveIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-' . substr(md5(uniqid('', true)), 0, 8)]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_same_idempotency_key_creates_only_one_event(): void
    {
        $input = [
            'customer'        => 'Shop no 58',
            'amount'          => 250,
            'currency'        => 'USD',
            'kind'            => 'advance',
            'method'          => 'Cash',
            'holder'          => 'Asish',
            'created_by'      => 'Asish',
            'tenant_id'       => $this->tenant->id,
            'idempotency_key' => 'fixed-offline-key-001',
        ];

        $first  = (new ReceiveMoney())->run($input);
        $second = (new ReceiveMoney())->run($input); // the retry

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        // Same fact returned, not a second one.
        $this->assertSame($first['event_id'], $second['event_id']);
        $this->assertSame(1, Event::where('name', 'PaymentReceived')->count());
    }
}
