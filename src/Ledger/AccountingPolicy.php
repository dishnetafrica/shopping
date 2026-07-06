<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

use CloudBSS\Kernel\Event\DomainEvent;

/**
 * Decides WHAT to post for a business event — per entity/country.
 * The ledger records; the policy decides. Swap this per entity
 * (Bentley Walkers US, DishNet South Sudan, WinWorld Uganda).
 */
interface AccountingPolicy
{
    public function supports(DomainEvent $e): bool;
    public function deriveEntry(DomainEvent $e): JournalEntry;
}
