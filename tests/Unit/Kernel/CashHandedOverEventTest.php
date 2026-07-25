<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Kernel\Events\CashHandedOver;
use Tests\TestCase;

/**
 * The CashHandedOver event contract (schema + payload build). Pure; no DB, no emit.
 * Proves a handover fact is well-formed — and conserved (different people) — before it can reach the spine.
 */
class CashHandedOverEventTest extends TestCase
{
    public function test_valid_payload_passes(): void
    {
        $errs = CashHandedOver::validate([
            'from_holder' => 'Asish', 'to_holder' => 'Owner',
            'amount' => 500, 'currency' => 'UGX', 'idempotency_key' => 'k1',
        ]);
        $this->assertSame([], $errs);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $errs = CashHandedOver::validate(['from_holder' => 'Asish', 'amount' => 500]);
        $this->assertNotEmpty($errs);
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'to_holder')));
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'currency')));
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'idempotency_key')));
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $errs = CashHandedOver::validate([
            'from_holder' => 'A', 'to_holder' => 'B', 'amount' => 0, 'currency' => 'UGX', 'idempotency_key' => 'k',
        ]);
        $this->assertNotEmpty($errs);
    }

    public function test_same_person_is_rejected(): void
    {
        // Conservation guard: a "handover" to yourself is a no-op that would write two cancelling rows.
        $errs = CashHandedOver::validate([
            'from_holder' => 'Asish', 'to_holder' => 'asish', 'amount' => 100, 'currency' => 'UGX', 'idempotency_key' => 'k',
        ]);
        $this->assertTrue((bool) collect($errs)->first(fn ($e) => str_contains($e, 'different')));
    }

    public function test_from_input_builds_canonical_payload_and_meta(): void
    {
        $built = CashHandedOver::fromInput([
            'from_holder' => 'Asish', 'to_holder' => 'Owner', 'amount' => '500', 'currency' => 'ugx',
            'usd' => 0.14, 'idempotency_key' => 'k2', 'tenant_id' => 17, 'created_by' => 'Owner',
            'from_holder_user_id' => 9,
        ]);

        $this->assertSame(500.0, $built['payload']['amount']);
        $this->assertSame('UGX', $built['payload']['currency']);      // normalised
        $this->assertSame('Asish', $built['payload']['from_holder']);
        $this->assertSame('Owner', $built['payload']['to_holder']);
        $this->assertSame('custody', $built['meta']['subject_type']); // first custody event
        $this->assertSame('k2', $built['meta']['idempotency_key']);
        $this->assertSame(17, $built['meta']['tenant_id']);
        $this->assertSame(9, $built['meta']['subject_id']);           // subject is the giver
    }

    public function test_canonical_names(): void
    {
        $this->assertSame('CashHandedOver', CashHandedOver::NAME);
        $this->assertSame('cash.handover', CashHandedOver::CANONICAL);
    }
}
