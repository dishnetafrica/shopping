<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;
use CloudBSS\Kernel\Event\DomainEvent;
/** Simple registry: (tenantId|entityId) => policy. */
final class RegistryAccountingPolicyResolver implements AccountingPolicyResolver
{
    /** @var array<string,AccountingPolicy> */ private array $map = [];
    public function register(string $tenantId, string $entityId, AccountingPolicy $p): void { $this->map["{$tenantId}|{$entityId}"] = $p; }
    public function policyFor(DomainEvent $e): ?AccountingPolicy { return $this->map["{$e->tenantId}|{$e->entityId}"] ?? null; }
}
