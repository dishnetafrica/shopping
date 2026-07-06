<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Kernel\Money\Money;

/**
 * Purchase side of the books: goods/services received on a supplier bill become a balanced ledger
 * entry via the tenant's purchase AccountingPolicy (UgandaVatPurchasePolicy = input VAT). Thin
 * wrapper over LedgerPoster. Idempotent on $purchaseId (a re-processed GRN / bill won't double-post).
 */
final class PurchaseLedger
{
    public function __construct(private LedgerPoster $poster) {}

    /**
     * Record a received purchase. `amountMinor` is the NET (ex-VAT) purchase cost in MINOR units.
     *
     * @return bool true if a new posting was made; false if already recorded.
     */
    public function recordPurchase(
        string $tenantId,
        string $entityId,
        string $purchaseId,
        int $amountMinor,
        string $currency = 'UGX',
        ?\DateTimeImmutable $occurredAt = null,
    ): bool {
        return $this->poster->post(
            $tenantId, $entityId, $purchaseId, 'Purchase', 'PurchaseReceived',
            "purchase_{$tenantId}_{$purchaseId}",
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
