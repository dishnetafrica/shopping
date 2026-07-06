<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

use CloudBSS\Kernel\Event\RecordedEvent;
use CloudBSS\Kernel\Money\Money;

/**
 * READ side. Folds JournalPosted events into running balances, scoped by
 * (tenant, entity, account, currency). Rebuildable from the log; incremental per event.
 * The ledger holds no write model of its own — that lives in the EventStore.
 */
final class LedgerProjection
{
    /** @var array<string,int> "tenant|entity|account|currency" => net minor (debit +, credit -) */
    private array $bal = [];

    /** Subscribe this to the bus for Posting::EVENT. */
    public function on(RecordedEvent $rec): void
    {
        if ($rec->name() !== Posting::EVENT) return;
        $e = $rec->event;
        foreach ($e->payload['lines'] as $l) {
            $k = "{$e->tenantId}|{$e->entityId}|{$l['code']}|{$l['currency']}";
            $delta = $l['side'] === 'debit' ? $l['minor'] : -$l['minor'];
            $this->bal[$k] = ($this->bal[$k] ?? 0) + $delta;
        }
    }

    /** Rebuild from scratch by replaying the log — proves balances are a pure projection. */
    public function rebuildFrom(array $recordedEvents): void
    {
        $this->bal = [];
        foreach ($recordedEvents as $rec) $this->on($rec);
    }

    public function balanceOf(string $tenantId, string $entityId, string $account, string $currency): Money
    {
        return Money::ofMinor($this->bal["{$tenantId}|{$entityId}|{$account}|{$currency}"] ?? 0, $currency);
    }

    /** Each tenant/entity/currency book nets to zero. */
    public function isBalanced(): bool
    {
        $book = [];
        foreach ($this->bal as $k => $net) {
            [$t, $en, , $cur] = explode('|', $k);
            $book["{$t}|{$en}|{$cur}"] = ($book["{$t}|{$en}|{$cur}"] ?? 0) + $net;
        }
        foreach ($book as $net) if ($net !== 0) return false;
        return true;
    }
}
