<?php declare(strict_types=1);
namespace CloudBSS\Modules\Manufacturing;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** New posting rules for a new domain — added without touching the kernel or the trade policy. */
final class ManufacturingAccountingPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}
    public function supports(DomainEvent $e): bool { return $e->name === 'GoodsProducedToStock'; }
    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur = $e->payload['currency'] ?? 'USD'; $cost = Money::ofMinor($e->payload['cost'], $cur);
        return new JournalEntry($e->tenantId, $e->entityId, "Production to stock {$e->aggregateId}", [
            JournalLine::debit(new Account('1210','Finished Goods','asset'), $cost),
            JournalLine::credit(new Account('1205','Work In Process','asset'), $cost),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
