<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Infra\Postgres\PostgresLedgerProjection;
use CloudBSS\Kernel\Event\{DomainEvent, EventBus, EventStore, RecordedEvent};
use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Ledger\{AccountingPolicyResolver, Posting};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * The first business vertical slice: a confirmed sale (e.g. a ShopBot / Patronics order) becomes a
 * balanced, durable, audit-perfect ledger entry — via the kernel, using the tenant's own accounting
 * policy (UgandaVatSalesPolicy for Patronics). Request-scoped and freeze-safe:
 *
 *   recordSale() builds a fresh, empty kernel, wires persistence + the durable balances projection
 *   as subscribers, records ONE DeliveryCompleted fact, and commits. The kernel's reactor derives
 *   the VAT journal automatically. Postgres assigns positions, so no full-log replay is needed to
 *   append — balances are read straight from the ledger_balances table.
 *
 * Idempotent: re-confirming the same order is a no-op (guarded by the sale aggregate already
 * existing in the log), so a WhatsApp double-tap or a webhook retry never double-posts revenue.
 */
final class SalesLedger
{
    public function __construct(
        private \PDO $pdo,
        private PostgresEventLog $log,
        private PostgresLedgerProjection $ledger,
        private Clock $clock,
        private IdGenerator $ids,
        private AccountingPolicyResolver $policies,
    ) {}

    /**
     * Record a confirmed sale. Amounts are in MINOR units of the currency (for UGX, whole shillings).
     *
     * @return bool true if a new posting was made; false if this sale was already recorded (idempotent).
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
        // Serialize concurrent submits of the SAME sale (two PHP-FPM workers, double-tap, webhook
        // retry firing at once). A transaction-scoped Postgres advisory lock on the sale key means
        // the second caller waits, then sees the sale already recorded and no-ops — no double-post.
        // The lock auto-releases on commit/rollback. Different sales don't contend (distinct keys).
        $this->pdo->beginTransaction();
        try {
            $lockKey = $this->advisoryKey("{$tenantId}|{$entityId}|{$saleId}");
            $this->pdo->prepare('SELECT pg_advisory_xact_lock(:k)')->execute([':k' => $lockKey]);

            // Idempotency (now inside the lock): the sale aggregate already has history -> posted.
            if ($this->log->versionOf($saleId) > 0) {
                $this->pdo->commit();
                return false;
            }

            // Fresh per-request kernel; its in-memory positions are throwaway (Postgres owns ordering).
            $store = new EventStore();
            $bus   = new EventBus();
            $bus->subscribe(Posting::EVENT, [$this->ledger, 'on']);              // fold balances (durable)
            $bus->subscribeAll(fn (RecordedEvent $r) => $this->log->append($r)); // persist events (durable)
            $kernel = new Kernel($store, $bus, $this->clock, $this->ids, $this->policies);

            $occurredAt ??= $this->clock->now();
            $kernel->recordEvent(new DomainEvent(
                eventId:       "sale_{$tenantId}_{$saleId}",   // deterministic -> retry-safe
                name:          'DeliveryCompleted',
                schemaVersion: 1,
                aggregateType: 'Sale',
                aggregateId:   $saleId,
                payload:       ['revenue' => $revenueMinor, 'cost' => $costMinor, 'currency' => $currency],
                occurredAt:    $occurredAt,
                tenantId:      $tenantId,
                entityId:      $entityId,
                correlationId: "ord_{$saleId}",
            ));
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Stable 64-bit signed key for a pg advisory lock (hash of the string). */
    private function advisoryKey(string $s): int
    {
        // 15 hex digits = 60 bits: always a positive int well under PHP_INT_MAX (no float overflow),
        // and a valid Postgres bigint for pg_advisory_xact_lock. Deterministic per sale key.
        return (int) hexdec(substr(sha1($s), 0, 15));
    }

    public function balance(string $tenantId, string $entityId, string $account, string $currency): Money
    {
        return $this->ledger->balanceOf($tenantId, $entityId, $account, $currency);
    }

    public function bookBalances(string $tenantId, string $entityId): bool
    {
        return $this->ledger->isBalanced($tenantId, $entityId);
    }
}
