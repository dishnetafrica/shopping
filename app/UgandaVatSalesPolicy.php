<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * A DIFFERENT country + a DIFFERENT accounting standard: Uganda with 18% output VAT.
 * Same events, different postings — added purely as a new policy. Kernel untouched.
 */
final class UgandaVatSalesPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids, private int $vatBp = 1800) {}
    public function supports(DomainEvent $e): bool { return $e->name === 'DeliveryCompleted'; }
    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur = $e->payload['currency'] ?? 'UGX';
        $net = Money::ofMinor($e->payload['revenue'], $cur);
        $vat = $net->scaledBy($this->vatBp, 10000);          // 18% output VAT
        $gross = $net->plus($vat);
        $cost = Money::ofMinor($e->payload['cost'], $cur);
        return new JournalEntry($e->tenantId, $e->entityId, "UG sale + VAT {$e->aggregateId}", [
            JournalLine::debit(new Account('1100','Accounts Receivable','asset'), $gross),
            JournalLine::credit(new Account('4000','Sales Revenue','income'), $net),
            JournalLine::credit(new Account('2200','VAT Output Payable','liability'), $vat),
            JournalLine::debit(new Account('5000','Cost of Goods Sold','expense'), $cost),
            JournalLine::credit(new Account('1200','Inventory','asset'), $cost),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
