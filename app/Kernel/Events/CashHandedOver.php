<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * Canonical contract for the "cash handed over" business fact — the first CUSTODY event
 * (Constitution Art. III — the event model; Custody is an L1 event-native concern).
 *
 * A handover is cash of ONE currency moving from one holder (a person) to another. Unlike the
 * money-in path (whose authoritative row is a CustomerPayment and whose event mirrors it), a
 * handover is event-native: the CashHandedOver event IS the fact, and the paired cash-ledger rows
 * are its projection. Idempotency therefore lives on the spine — the (tenant_id, idempotency_key)
 * uniqueness on bos_events is the ONE guard against a flaky-connection double-record. No new column.
 *
 * This class is a PURE contract: it defines the payload shape, builds it, and validates it. It does
 * no I/O and emits nothing. The HandOverCash capability is what emits.
 */
final class CashHandedOver
{
    /** The event name written to the spine (bos_events.name). */
    public const NAME = 'CashHandedOver';

    /** The Constitution Art. III taxonomy alias. */
    public const CANONICAL = 'cash.handover';

    /** The minimum a handover fact must carry to be recordable. */
    public const REQUIRED = ['from_holder', 'to_holder', 'amount', 'currency', 'idempotency_key'];

    /**
     * Build a validated payload + emit-meta from a handover input. Does NOT emit — the capability does.
     * The payload carries everything the ledger projection needs, so the event is a complete fact.
     *
     * @return array{payload: array<string,mixed>, meta: array<string,mixed>}
     */
    public static function fromInput(array $in): array
    {
        $payload = [
            'from_holder'    => trim((string) ($in['from_holder'] ?? '')),
            'to_holder'      => trim((string) ($in['to_holder'] ?? '')),
            'from_holder_id' => isset($in['from_holder_user_id']) ? (int) $in['from_holder_user_id'] : null,
            'to_holder_id'   => isset($in['to_holder_user_id']) ? (int) $in['to_holder_user_id'] : null,
            'amount'         => round((float) ($in['amount'] ?? 0), 2),
            'currency'       => strtoupper((string) ($in['currency'] ?? '')),
            'usd'            => isset($in['usd']) ? round((float) $in['usd'], 2) : null,
            'rate'           => isset($in['rate']) ? (float) $in['rate'] : null,
            'method'         => $in['method'] ?? null,
            'note'           => $in['note'] ?? null,
        ];

        $meta = [
            'tenant_id'       => isset($in['tenant_id']) ? (int) $in['tenant_id'] : null,
            'subject_type'    => 'custody',
            // Subject is the giver — "whose custody released this cash" — so a person's custody
            // timeline reads naturally. subject_id is the giver's user id when known.
            'subject_id'      => isset($in['from_holder_user_id']) ? (int) $in['from_holder_user_id'] : null,
            'idempotency_key' => (string) ($in['idempotency_key'] ?? ''),
            'actor'           => ['type' => 'user', 'name' => (string) ($in['created_by'] ?? '')],
            'occurred_at'     => $in['occurred_at'] ?? null,
        ];

        return ['payload' => $payload, 'meta' => $meta];
    }

    /**
     * Return a list of human-readable violations for an input (empty array = valid). Pure, no I/O.
     *
     * @return array<string>
     */
    public static function validate(array $in): array
    {
        $errs = [];
        foreach (self::REQUIRED as $k) {
            if (! isset($in[$k]) || $in[$k] === '') {
                $errs[] = "missing required field: {$k}";
            }
        }
        if (isset($in['amount']) && (float) $in['amount'] <= 0) {
            $errs[] = 'amount must be greater than zero';
        }
        if (isset($in['currency']) && strlen((string) $in['currency']) < 3) {
            $errs[] = 'currency must be a 3-letter code';
        }
        // Conservation guard, part 1: a handover between the SAME person is a no-op that would only
        // create two self-cancelling ledger rows. Reject it up front.
        $from = strtolower(trim((string) ($in['from_holder'] ?? '')));
        $to   = strtolower(trim((string) ($in['to_holder'] ?? '')));
        if ($from !== '' && $from === $to) {
            $errs[] = 'from_holder and to_holder must be different people';
        }
        return $errs;
    }
}
