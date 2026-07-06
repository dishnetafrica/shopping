<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Kernel\Money\Money;

/**
 * Sales side of the books: a confirmed sale (e.g. a ShopBot / Patronics order) becomes a balanced,
 * durable ledger entry via the tenant's AccountingPolicy (UgandaVatSalesPolicy for Patronics).
 * A thin domain wrapper over LedgerPoster — all the durability/idempotency/locking lives there.
 */
final class SalesLedger
{
    public function __construct(private LedgerPoster $poster) {}

    /**
     * Record a confirmed sale. Amounts are MINOR units (for UGX, whole shillings). `revenueMinor`
     * is the NET (ex-VAT) amount — for VAT-inclusive catalogues pass inclusive_total / 1.18, so the
     * ledger AR (gross) equals the price the customer paid. Idempotent on $saleId.
     *
     * @return bool true if a new posting was made; false if already recorded.
     */
    public function recordSale(
        string $tenantId,
        string $entityId,
        string $saleId,
        int $revenueMinor,
        int $costMinor,
        string $currency = 'UGX',
        ?\DateTimeImmutable $occurredAt = null,
    ): bool {
        return $this->poster->post(
            $tenantId, $entityId, $saleId, 'Sale', 'DeliveryCompleted',
            "sale_{$tenantId}_{$saleId}",
            ['revenue' => $revenueMinor, 'cost' => $costMinor, 'currency' => $currency],
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
