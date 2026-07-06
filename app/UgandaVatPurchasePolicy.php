<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * The other side of the books: a purchase of goods for resale under Uganda 18% input VAT.
 * On `PurchaseReceived`:
 *     Dr Inventory (net)  +  Dr VAT Input Receivable (18%)  =  Cr Accounts Payable (gross)
 * Input VAT is a recoverable asset (nets against output VAT at settlement). Same events, a new
 * policy — kernel untouched.
 */
final class UgandaVatPurchasePolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids, private int $vatBp = 1800) {}

    public function supports(DomainEvent $e): bool { return $e->name === 'PurchaseReceived'; }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur   = $e->payload['currency'] ?? 'UGX';
        $net   = Money::ofMinor($e->payload['amount'], $cur);   // net purchase cost (ex-VAT)
        $vat   = $net->scaledBy($this->vatBp, 10000);           // 18% recoverable input VAT
        $gross = $net->plus($vat);

        return new JournalEntry($e->tenantId, $e->entityId, "UG purchase + input VAT {$e->aggregateId}", [
            JournalLine::debit(new Account('1200', 'Inventory', 'asset'), $net),
            JournalLine::debit(new Account('1300', 'VAT Input Receivable', 'asset'), $vat),
            JournalLine::credit(new Account('2000', 'Accounts Payable', 'liability'), $gross),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
