<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger\Policies;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{Account, AccountingPolicy, JournalEntry, JournalLine};
use CloudBSS\Kernel\Money\Money;
use CloudBSS\Kernel\Support\{Clock, IdGenerator};

/** DishNet / South Sudan trade postings. Tenant/entity come from the EVENT — the policy is not bound to one company. */
final class SouthSudanTradePolicy implements AccountingPolicy
{
    private array $acc;

    public function __construct(private Clock $clock, private IdGenerator $ids)
    {
        $this->acc = [
            'cash'      => new Account('1000', 'Cash — Juba', 'asset'),
            'ar'        => new Account('1100', 'Accounts Receivable', 'asset'),
            'short'     => new Account('1150', 'COD Shortfall Receivable', 'asset'),
            'inventory' => new Account('1200', 'Inventory / Goods-in-Transit', 'asset'),
            'ap'        => new Account('2000', 'Accounts Payable — Suppliers', 'liability'),
            'revenue'   => new Account('4000', 'Sales Revenue', 'income'),
            'cogs'      => new Account('5000', 'Cost of Goods Sold', 'expense'),
        ];
    }

    private const HANDLED = ['DeliveryCompleted', 'CODCollected', 'SupplierPaid'];
    public function supports(DomainEvent $e): bool { return in_array($e->name, self::HANDLED, true); }

    public function deriveEntry(DomainEvent $e): JournalEntry
    {
        $p = $e->payload; $cur = $p['currency'] ?? 'USD';
        $m = fn(int $minor) => Money::ofMinor($minor, $cur);
        // tenant/entity taken from the event metadata — kernel stays tenant-agnostic
        [$t, $en, $src] = [$e->tenantId, $e->entityId, $e->eventId];
        $mk = fn(string $memo, array $lines) => new JournalEntry($t, $en, $memo, $lines, $src, $this->clock, $this->ids);

        return match ($e->name) {
            'DeliveryCompleted' => $mk("Revenue + COGS for {$e->aggregateId}", [
                JournalLine::debit($this->acc['ar'],        $m($p['revenue'])),
                JournalLine::credit($this->acc['revenue'],  $m($p['revenue'])),
                JournalLine::debit($this->acc['cogs'],      $m($p['cost'])),
                JournalLine::credit($this->acc['inventory'],$m($p['cost'])),
            ]),
            'CODCollected' => $mk("COD for {$e->aggregateId}", array_values(array_filter([
                JournalLine::debit($this->acc['cash'],  $m($p['collected'])),
                ($p['short'] ?? 0) > 0 ? JournalLine::debit($this->acc['short'], $m($p['short'])) : null,
                JournalLine::credit($this->acc['ar'],   $m($p['collected'] + ($p['short'] ?? 0))),
            ]))),
            'SupplierPaid' => $mk("Paid supplier for {$e->aggregateId}", [
                JournalLine::debit($this->acc['ap'],   $m($p['amount'])),
                JournalLine::credit($this->acc['cash'],$m($p['amount'])),
            ]),
        };
    }
}
