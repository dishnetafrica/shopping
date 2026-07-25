<?php declare(strict_types=1);

namespace App\Kernel\Capabilities\Cash;

use App\BOS\Bos;
use App\Kernel\Contracts\KernelCapability;
use App\Kernel\Events\CashHandedOver;

/**
 * cash.handover — the ONE owner of the "cash handed from one holder to another" effect
 * (Engineering Principle 1; Constitution Art. IV). It is the first CUSTODY capability.
 *
 * Per the KernelCapability contract, its ONLY side effect is emitting the CashHandedOver fact — it
 * NEVER writes a ledger row itself. The paired cash-ledger projection (a −cash_out for the giver and
 * a +cash_in for the receiver, both tagged 'Transfer' so P&L is untouched) is written by the
 * orchestrator (StaffCashController::handover) inside the SAME transaction as this emit, so the event
 * and its projection are atomic. Because the spine is idempotent on (tenant_id, idempotency_key), a
 * retried handover returns the existing fact with duplicate=true and the orchestrator writes NO
 * second pair of rows — offline-retry safety with zero schema change.
 *
 * INVARIANTS:
 *  - Conservation (out == in, same currency): guaranteed by construction — the orchestrator writes
 *    one pair with a single amount/currency in one transaction — plus the same-person guard in
 *    CashHandedOver::validate(). It is not a runtime rule because it cannot be violated by construction.
 *  - R6 CustodyNotOverdrawn (CANDIDATE, OBSERVE-ONLY): a holder should not hand over more than they
 *    hold. Logged as evidence, never blocked — the same disciplined rollout used for R3 (money-in).
 *    It earns ratification into the Constitution only once reality proves over-holds occur and matter.
 */
final class HandOverCash implements KernelCapability
{
    public function key(): string { return 'cash.handover'; }

    public function emits(): array { return [CashHandedOver::NAME]; }

    /** R6 is observed here, not hard-enforced (not yet a registered RuleRegistry rule). */
    public function invariants(): array { return []; }

    public function effect(): string { return 'reversible'; }

    /** Undo is a reverse handover (to → from) — a new event, never a delete. */
    public function compensator(): ?string { return 'cash.handover'; }

    /** Moving cash between people is high-trust and human-driven — never auto-run by the AI. */
    public function autonomy(): string { return 'human_only'; }

    /**
     * @param  array  $input  from_holder, to_holder, from_holder_user_id, to_holder_user_id, amount,
     *                        currency, usd, rate, method, note, created_by, tenant_id, idempotency_key,
     *                        [from_balance] (for the observe-only R6 check), [occurred_at]
     * @return array{ok:bool, event_id?:string, duplicate?:bool, errors?:array<string>}
     */
    public function run(array $input): array
    {
        // 1) Schema — reject a malformed handover before anything else. No emit on failure.
        $errors = CashHandedOver::validate($input);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 2) R6 (OBSERVE-ONLY) — a holder should not hand over more than they hold. Log the evidence
        //    and DO NOT block. The balance is supplied by the caller (no DB read here), exactly as R3
        //    receives its balance in ReceiveMoney.
        if (array_key_exists('from_balance', $input)) {
            $amount = (float) ($input['amount'] ?? 0);
            $held   = (float) $input['from_balance'];
            if ($amount > $held + 0.005) {
                \Log::warning(sprintf(
                    'R6 custody over-hold observed (not enforced): %s tried to hand over %s %s but holds only %s %s',
                    (string) ($input['from_holder'] ?? '?'),
                    strtoupper((string) ($input['currency'] ?? '')), number_format($amount, 2),
                    number_format($held, 2), strtoupper((string) ($input['currency'] ?? ''))
                ));
            }
        }

        // 3) Emit — the ONLY side effect (Constitution Law 3). Idempotent at the spine: the same
        //    idempotency_key returns the existing fact (wasRecentlyCreated = false), which tells the
        //    orchestrator NOT to write a second pair of ledger rows.
        $built = CashHandedOver::fromInput($input);
        $event = Bos::emit(CashHandedOver::NAME, $built['payload'], $built['meta']);

        return [
            'ok'        => true,
            'event_id'  => (string) $event->event_id,
            'duplicate' => ! $event->wasRecentlyCreated,
        ];
    }
}
