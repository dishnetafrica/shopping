<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/**
 * Compensating entry for the historical multi-currency REVENUE overstatement (sibling of
 * CogsCorrectionPolicy). The old sale posting (UgandaVatSalesPolicy on 'DeliveryCompleted') booked a
 * sell-currency (e.g. UGX) net as if it were base currency, WITHOUT conversion:
 *     Dr 1100 AR (gross)  ·  Cr 4000 Revenue (net)  ·  Cr 2200 VAT Output (vat)
 * inflating all three legs. Journals are immutable (ADR-000), so this posts a NEW balanced entry that
 * reverses ONLY the overstatement, at the SAME VAT rate the sale used:
 *
 *     Dr 4000 Sales Revenue        (net_over)    ← removes overstated revenue
 *     Dr 2200 VAT Output Payable   (vat_over)    ← removes overstated output VAT
 *     Cr 1100 Accounts Receivable  (gross_over)  ← removes the overstated receivable
 *
 * gross_over = net_over + vat_over, so Σdebits == Σcredits by construction. The event payload carries
 * the net overstatement (minor, base) and the VAT basis points; the why (rate, sale, reason, approver)
 * lives on the 'SaleFxCorrected' business event, never on the lines (ADR-000).
 *
 * `net_amount` MUST be a positive overstatement — a zero/negative value is rejected (never posted).
 */
final class SaleFxCorrectionPolicy implements AccountingPolicy
{
    public function __construct(private Clock $clock, private IdGenerator $ids) {}

    public function supports(DomainEvent $e): bool { return $e->name === 'SaleFxCorrected'; }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $cur   = $e->payload['currency'] ?? 'USD';
        $vatBp = (int) ($e->payload['vat_bp'] ?? 1800);
        $net   = Money::ofMinor((int) $e->payload['net_amount'], $cur);
        if ($net->isZero() || $net->isNegative()) {
            throw new \DomainException('SaleFxCorrected net_amount must be a positive overstatement');
        }
        $vat   = $net->scaledBy($vatBp, 10000);   // reverse VAT at the SAME rate the sale booked
        $gross = $net->plus($vat);
        $sale  = (string) ($e->payload['sale'] ?? $e->aggregateId);

        return new JournalEntry($e->tenantId, $e->entityId, "Sale FX correction for sale {$sale}", [
            JournalLine::debit(new Account('4000', 'Sales Revenue', 'income'), $net),
            JournalLine::debit(new Account('2200', 'VAT Output Payable', 'liability'), $vat),
            JournalLine::credit(new Account('1100', 'Accounts Receivable', 'asset'), $gross),
        ], $e->eventId, $this->clock, $this->ids);
    }
}
