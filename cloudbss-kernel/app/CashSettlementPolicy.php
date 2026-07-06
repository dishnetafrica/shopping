<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * Closes the cash loop. Two events, one policy:
 *   PaymentReceived (a customer pays us) -> Dr Cash & Bank, Cr Accounts Receivable
 *   SupplierPaid    (we pay a supplier)  -> Dr Accounts Payable, Cr Cash & Bank
 * Settles the aggregate AR/AP raised by the sales/purchase policies. Kernel untouched.
 */
final class CashSettlementPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}

    public function supports(DomainEvent $e): bool
    {
        return $e->name === 'PaymentReceived' || $e->name === 'SupplierPaid';
    }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur    = $e->payload['currency'] ?? 'UGX';
        $amount = Money::ofMinor($e->payload['amount'], $cur);
        $cash   = new Account('1000', 'Cash & Bank', 'asset');

        if ($e->name === 'PaymentReceived') {
            return new JournalEntry($e->tenantId, $e->entityId, "Customer payment {$e->aggregateId}", [
                JournalLine::debit($cash, $amount),
                JournalLine::credit(new Account('1100', 'Accounts Receivable', 'asset'), $amount),
            ], $e->eventId, $this->clock, $this->ids);
        }

        // SupplierPaid
        return new JournalEntry($e->tenantId, $e->entityId, "Supplier payment {$e->aggregateId}", [
            JournalLine::debit(new Account('2000', 'Accounts Payable', 'liability'), $amount),
            JournalLine::credit($cash, $amount),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
