<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Kernel\Events\MoneyReceived;
use Tests\TestCase;

/**
 * 3A — the MoneyReceived event contract (schema + payload build). Pure; no DB, no emit.
 * Proves the money-in fact is well-formed before it can ever reach the spine.
 */
class MoneyReceivedEventTest extends TestCase
{
    public function test_valid_payload_passes(): void
    {
        $errs = MoneyReceived::validate(['amount' => 100, 'currency' => 'USD', 'idempotency_key' => 'k1']);
        $this->assertSame([], $errs);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $errs = MoneyReceived::validate(['amount' => 100]); // no currency, no idempotency_key
        $this->assertNotEmpty($errs);
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'currency')));
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'idempotency_key')));
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $errs = MoneyReceived::validate(['amount' => 0, 'currency' => 'USD', 'idempotency_key' => 'k']);
        $this->assertNotEmpty($errs);
    }

    public function test_from_input_builds_canonical_payload_and_meta(): void
    {
        $built = MoneyReceived::fromInput([
            'amount' => '1164', 'currency' => 'usd', 'invoice_no' => 'INV-2026-0025',
            'method' => 'Cash', 'kind' => 'settlement', 'idempotency_key' => 'k2',
            'tenant_id' => 17, 'created_by' => 'Asish', 'customer' => 'Shop no 58',
        ]);

        $this->assertSame(1164.0, $built['payload']['amount']);
        $this->assertSame('USD', $built['payload']['currency']);      // normalised
        $this->assertSame('Shop no 58', $built['payload']['party']);
        $this->assertSame('invoice', $built['meta']['subject_type']); // invoice_no present → invoice subject
        $this->assertSame('k2', $built['meta']['idempotency_key']);
        $this->assertSame(17, $built['meta']['tenant_id']);
    }

    public function test_wire_name_is_unchanged_from_today(): void
    {
        // Guards the approved decision: the wire name stays 'PaymentReceived' (no working-code change).
        $this->assertSame('PaymentReceived', MoneyReceived::NAME);
        $this->assertSame('money.received', MoneyReceived::CANONICAL);
    }
}
