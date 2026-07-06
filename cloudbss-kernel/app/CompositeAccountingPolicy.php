<?php declare(strict_types=1);
namespace CloudBSS\App;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Ledger\{AccountingPolicy, JournalEntry};

/**
 * Lets ONE tenant use MANY posting rules (trade + manufacturing + VAT) at once.
 * Implements the kernel's AccountingPolicy interface — so the kernel needs no change
 * to support a tenant whose books span several domains.
 */
final class CompositeAccountingPolicy implements AccountingPolicy
{
    /** @param AccountingPolicy[] $policies */
    public function __construct(private array $policies) {}
    public function supports(DomainEvent $e): bool
    { foreach ($this->policies as $p) if ($p->supports($e)) return true; return false; }
    public function deriveEntry(DomainEvent $e): JournalEntry
    { foreach ($this->policies as $p) if ($p->supports($e)) return $p->deriveEntry($e);
      throw new \LogicException('No sub-policy supports '.$e->name); }
}
