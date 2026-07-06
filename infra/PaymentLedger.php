<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Kernel\Money\Money;

/**
 * Cash settlement: records customer receipts and supplier payments, settling the AR/AP raised by
 * the sales/purchase policies. Thin wrapper over LedgerPoster (durable, idempotent, advisory-locked).
 * Idempotent on the payment id — a re-processed receipt / cheque won't double-count cash.
 */
final class PaymentLedger
{
    public function __construct(private LedgerPoster $poster) {}

    /** A customer pays us: Dr Cash, Cr AR. `amountMinor` in MINOR units. Idempotent on $paymentId. */
    public function recordCustomerPayment(
        string $tenantId, string $entityId, string $paymentId,
        int $amountMinor, string $currency = 'UGX', ?\DateTimeImmutable $occurredAt = null,
    ): bool {
        return $this->poster->post(
            $tenantId, $entityId, $paymentId, 'Payment', 'PaymentReceived',
            "pmt_in_{$tenantId}_{$paymentId}",
            ['amount' => $amountMinor, 'currency' => $currency],
            $occurredAt,
        );
    }

    /** We pay a supplier: Dr AP, Cr Cash. Idempotent on $paymentId. */
    public function recordSupplierPayment(
        string $tenantId, string $entityId, string $paymentId,
        int $amountMinor, string $currency = 'UGX', ?\DateTimeImmutable $occurredAt = null,
    ): bool {
        return $this->poster->post(
            $tenantId, $entityId, $paymentId, 'Payment', 'SupplierPaid',
            "pmt_out_{$tenantId}_{$paymentId}",
            ['amount' => $amountMinor, 'currency' => $currency],
            $occurredAt,
        );
    }

    public function balance(string $tenantId, string $entityId, string $account, string $currency): Money
    {
        return $this->poster->balance($tenantId, $entityId, $account, $currency);
    }

    public function bookBalances(string $tenantId, string $entityId): bool
    {
        return $this->poster->bookBalances($tenantId, $entityId);
    }
}
