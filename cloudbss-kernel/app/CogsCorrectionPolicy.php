<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * Phase 3B — compensating entry for the historical multi-currency COGS overstatement.
 *
 * The old sale posting (UgandaVatSalesPolicy on 'DeliveryCompleted') booked a UGX cost as if it were
 * base-currency COGS, WITHOUT conversion:  Dr 5000 COGS  /  Cr 1200 Inventory  for the inflated amount.
 * Journals are immutable (ADR-000), so we never edit that entry — we post a NEW, balanced compensating
 * entry that reverses ONLY the overstatement (the difference between what was posted and the correct
 * base-currency cost):
 *
 *     Dr 1200 Inventory            (overstatement)   ← restores stock that was over-relieved
 *     Cr 5000 Cost of Goods Sold   (overstatement)   ← removes the overstated expense → gross profit restored
 *
 * Same currency on both legs (the base/posting currency), so Σdebits == Σcredits by construction.
 * The WHY of the correction (original posted cost, correct cost, FX rate, reason, approver, version)
 * lives on the 'CogsCorrected' business event's payload — never on the journal line (ADR-000).
 *
 * `amount` is MINOR units in `currency` and MUST be > 0 (a zero/negative overstatement is not a
 * correction and is rejected up-front by the remediation command, never posted).
 */
final class CogsCorrectionPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}

    public function supports(DomainEvent $e): bool { return $e->name === 'CogsCorrected'; }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur    = $e->payload['currency'] ?? 'USD';
        $amount = Money::ofMinor((int) $e->payload['amount'], $cur);
        if ($amount->isZero() || $amount->isNegative()) {
            throw new \DomainException('CogsCorrected amount must be a positive overstatement');
        }
        $sale = (string) ($e->payload['sale'] ?? $e->aggregateId);

        return new JournalEntry($e->tenantId, $e->entityId, "COGS FX correction for sale {$sale}", [
            JournalLine::debit(new Account('1200', 'Inventory', 'asset'), $amount),
            JournalLine::credit(new Account('5000', 'Cost of Goods Sold', 'expense'), $amount),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
