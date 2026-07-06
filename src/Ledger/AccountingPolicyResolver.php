<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;
use CloudBSS\Kernel\Event\DomainEvent;
/** The application supplies this. Kernel stays tenant-agnostic: it asks which policy for this event. */
interface AccountingPolicyResolver { public function policyFor(DomainEvent $e): ?AccountingPolicy; }
