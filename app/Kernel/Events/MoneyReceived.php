<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * Canonical contract for the "money received" business fact (Constitution Art. III — the event model).
 *
 * NAMING (approved 3A decision 1): the wire name stays 'PaymentReceived' — the exact name
 * PaymentService already emits today (PaymentService.php:123) — so NO working code changes.
 * 'money.received' is its canonical taxonomy alias from the Constitution.
 *
 * This class is a PURE contract: it defines the payload shape, builds it, and validates it. It does
 * no I/O and emits nothing. The ReceiveMoney capability is what emits. Shipping it changes nothing.
 */
final class MoneyReceived
{
    /** The event name written to the spine (bos_events.name). Unchanged from today. */
    public const NAME = 'PaymentReceived';

    /** The Constitution Art. III taxonomy alias. */
    public const CANONICAL = 'money.received';

    /** The minimum a money-in fact must carry to be recordable. */
    public const REQUIRED = ['amount', 'currency', 'idempotency_key'];

    /**
     * Build a validated payload + emit-meta from a money-in input. Does NOT emit — the capability does.
     *
     * @return array{payload: array<string,mixed>, meta: array<string,mixed>}
     */
    public static function fromInput(array $in): array
    {
        $payload = [
            'receipt_no' => $in['receipt_no'] ?? null,
            'amount'     => round((float) ($in['amount'] ?? 0), 2),
            'currency'   => strtoupper((string) ($in['currency'] ?? '')),
            'usd'        => isset($in['usd']) ? round((float) $in['usd'], 2) : null,
            'rate'       => isset($in['rate']) ? (float) $in['rate'] : null,
            'method'     => $in['method'] ?? null,
            'reference'  => $in['reference'] ?? null,
            'invoice_no' => $in['invoice_no'] ?? null,
            'order_no'   => $in['order_no'] ?? null,
            'kind'       => strtolower((string) ($in['kind'] ?? 'settlement')),
            'holder'     => $in['holder'] ?? null,     // who received the cash
            'party'      => $in['customer'] ?? null,   // who paid
        ];

        $meta = [
            'tenant_id'       => isset($in['tenant_id']) ? (int) $in['tenant_id'] : null,
            // Honour an explicit subject when the caller supplies one (3B dual-write passes the
            // customer subject to preserve the Customer-360 timeline exactly); else the heuristic.
            'subject_type'    => $in['subject_type'] ?? (($payload['invoice_no'] ?? null) ? 'invoice' : 'customer'),
            'subject_id'      => isset($in['subject_id']) ? (int) $in['subject_id'] : null,
            'idempotency_key' => (string) ($in['idempotency_key'] ?? ''),
            'actor'           => ['type' => 'user', 'name' => (string) ($in['created_by'] ?? '')],
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
        return $errs;
    }
}
