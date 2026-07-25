<?php declare(strict_types=1);

namespace App\Kernel\Capabilities\Money;

use App\BOS\Bos;
use App\Kernel\Contracts\KernelCapability;
use App\Kernel\Events\MoneyReceived;

/**
 * money.receive — the ONE owner of the "money received" effect (Engineering Principle 1;
 * Constitution Art. IV). Its only side effect is emitting the MoneyReceived fact; it validates the
 * payload and enforces the money-in invariant first.
 *
 * INVARIANT: it owns R3 (an applied settlement cannot exceed the invoice balance). It deliberately
 * does NOT enforce R2 (ReceiptRequiresPayment): R2's context is a payment_id and it governs the
 * separate receipt-ISSUE effect, which by Principle 1 has its own owner (a later ticket). Enforcing
 * R2 here would be a second owner reaching into another effect — exactly what the Rule of One forbids.
 *
 * TICKET 3A: this capability is fully implemented and unit-tested in isolation. It is NOT invoked by
 * any controller or service. Wiring PaymentService to it (dual-write) is 3B; migrating
 * StaffCashController and deleting the duplicate path is 3C. Shipping 3A changes zero behaviour.
 */
final class ReceiveMoney implements KernelCapability
{
    public function key(): string { return 'money.receive'; }

    public function emits(): array { return [MoneyReceived::NAME]; }

    public function invariants(): array { return ['R3']; }

    public function effect(): string { return 'reversible'; }

    public function compensator(): ?string { return 'money.reverse'; }

    /** Money-in is never auto-run by the AI — irreversible-to-a-customer, so human-only (Constitution Art. V). */
    public function autonomy(): string { return 'human_only'; }

    /**
     * @param  array  $input  customer, phone, amount, currency, usd, rate, method, reference,
     *                        invoice_no, order_no, kind, balance, holder, created_by,
     *                        tenant_id, idempotency_key, [receipt_no], [subject_id]
     * @return array{ok:bool, event_id?:string, errors?:array<string>}
     */
    public function run(array $input): array
    {
        // 1) Schema — reject a malformed money-in fact before anything else. No emit on failure.
        $errors = MoneyReceived::validate($input);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 2) Invariant R3 — a SETTLEMENT applied to an invoice cannot exceed its balance (advances exempt).
        //    Throws RuleViolation, enforced from the single App\BOS rule registry (Principle 2).
        Bos::enforce('R3', [
            'kind'    => strtolower((string) ($input['kind'] ?? 'settlement')),
            'amount'  => (float) ($input['amount'] ?? 0),
            'balance' => (float) ($input['balance'] ?? 0),
        ]);

        // 3) Emit — the ONLY side effect (Constitution Laws 3 & 4). Idempotent at the spine: the same
        //    idempotency_key, retried, returns the existing fact and never writes a second row.
        $built = MoneyReceived::fromInput($input);
        $event = Bos::emit(MoneyReceived::NAME, $built['payload'], $built['meta']);

        return ['ok' => true, 'event_id' => (string) $event->event_id];
    }
}
