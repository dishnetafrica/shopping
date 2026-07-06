<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Infra\Postgres\PostgresLedgerProjection;
use CloudBSS\Infra\Postgres\PostgresSubledgerProjection;
use CloudBSS\Kernel\Event\{DomainEvent, EventBus, EventStore, RecordedEvent};
use CloudBSS\Kernel\Kernel;
use CloudBSS\Kernel\Ledger\{AccountingPolicyResolver, Posting};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * The reusable write path for any business event that should hit the ledger. Sales, purchases,
 * payments, expenses etc. all record through this — the only difference is the event they author
 * and the tenant's AccountingPolicy that turns it into a JournalPosted. Request-scoped and
 * freeze-safe (builds a fresh throwaway kernel; Postgres owns ordering).
 *
 * Guarantees: durable (events + balances committed in one transaction), idempotent (a repeat of
 * the same aggregate is a no-op), and concurrency-safe (a transaction-scoped advisory lock on the
 * aggregate key serialises simultaneous submits — no double-post).
 */
final class LedgerPoster
{
    public function __construct(
        private \PDO $pdo,
        private PostgresEventLog $log,
        private PostgresLedgerProjection $ledger,
        private Clock $clock,
        private IdGenerator $ids,
        private AccountingPolicyResolver $policies,
        private ?PostgresSubledgerProjection $subledger = null,
    ) {}

    /**
     * Record one business fact and post it. Returns true if a new posting was made, false if this
     * aggregate was already recorded (idempotent).
     */
    public function post(
        string $tenantId,
        string $entityId,
        string $aggregateId,
        string $aggregateType,
        string $eventName,
        string $eventId,
        array $payload,
        ?\DateTimeImmutable $occurredAt = null,
    ): bool {
        $this->pdo->beginTransaction();
        try {
            // serialise simultaneous submits of the SAME aggregate; auto-releases on commit/rollback
            $this->pdo->prepare('SELECT pg_advisory_xact_lock(:k)')
                ->execute([':k' => $this->advisoryKey("{$tenantId}|{$entityId}|{$aggregateId}")]);

            if ($this->log->versionOf($aggregateId) > 0) {   // already posted -> no-op
                $this->pdo->commit();
                return false;
            }

            $store = new EventStore();
            $bus   = new EventBus();
            $bus->subscribe(Posting::EVENT, [$this->ledger, 'on']);              // fold balances (durable)
            $bus->subscribeAll(fn (RecordedEvent $r) => $this->log->append($r)); // persist events (durable)
            if ($this->subledger !== null) {
                $bus->subscribeAll([$this->subledger, 'on']);                    // per-party open items
            }
            $kernel = new Kernel($store, $bus, $this->clock, $this->ids, $this->policies);

            $kernel->recordEvent(new DomainEvent(
                eventId:       $eventId,
                name:          $eventName,
                schemaVersion: 1,
                aggregateType: $aggregateType,
                aggregateId:   $aggregateId,
                payload:       $payload,
                occurredAt:    $occurredAt ?? $this->clock->now(),
                tenantId:      $tenantId,
                entityId:      $entityId,
                correlationId: strtolower($aggregateType) . "_{$aggregateId}",
            ));

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function balance(string $tenantId, string $entityId, string $account, string $currency): Money
    {
        return $this->ledger->balanceOf($tenantId, $entityId, $account, $currency);
    }

    public function bookBalances(string $tenantId, string $entityId): bool
    {
        return $this->ledger->isBalanced($tenantId, $entityId);
    }

    private function advisoryKey(string $s): int
    {
        return (int) hexdec(substr(sha1($s), 0, 15));   // 60-bit positive int, valid pg bigint
    }
}
