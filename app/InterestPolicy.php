<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * Overdue interest (Patronics: "3% per month on overdue accounts"). On `InterestCharged`:
 *     Dr Accounts Receivable (the customer now owes more)
 *     Cr Interest Income     (income -> shows in P&L)
 * The customer id rides in the payload so the subledger adds it to their statement/aging.
 */
final class InterestPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}

    public function supports(DomainEvent $e): bool { return $e->name === 'InterestCharged'; }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur = $e->payload['currency'] ?? 'UGX';
        $amt = Money::ofMinor($e->payload['amount'], $cur);

        return new JournalEntry($e->tenantId, $e->entityId, "Overdue interest {$e->aggregateId}", [
            JournalLine::debit(new Account('1100', 'Accounts Receivable', 'asset'), $amt),
            JournalLine::credit(new Account('4100', 'Interest Income', 'income'), $amt),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
