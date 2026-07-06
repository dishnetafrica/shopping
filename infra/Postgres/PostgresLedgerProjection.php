<?php declare(strict_types=1);

namespace CloudBSS\Infra\Postgres;

use CloudBSS\Kernel\Event\RecordedEvent;
use CloudBSS\Kernel\Ledger\Posting;
use CloudBSS\Kernel\Money\Money;

/**
 * Durable version of the kernel's LedgerProjection: folds JournalPosted events into a
 * `cloudbss_ledger_balances` table so a balance is a cheap indexed query — not a full-log replay on every
 * web request. Same fold rule as the in-memory projection (debit +, credit -), same guarantee:
 * it is a pure projection and can be rebuilt from the events log at any time.
 *
 * Subscribe on() to the bus for Posting::EVENT; the fold runs inside the caller's transaction so
 * the balance update commits atomically with the event that caused it.
 */
final class PostgresLedgerProjection
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Fold one posting into the balances table (incremental, idempotent per line via UPSERT add). */
    public function on(RecordedEvent $rec): void
    {
        if ($rec->name() !== Posting::EVENT) return;
        $e = $rec->event;
        $sql = 'INSERT INTO cloudbss_ledger_balances (tenant_id, entity_id, account, currency, net_minor)
                VALUES (:t, :en, :acc, :cur, :delta)
                ON CONFLICT (tenant_id, entity_id, account, currency)
                DO UPDATE SET net_minor = cloudbss_ledger_balances.net_minor + EXCLUDED.net_minor,
                              updated_at = now()';
        $st = $this->pdo->prepare($sql);
        foreach ($e->payload['lines'] as $l) {
            $delta = $l['side'] === 'debit' ? $l['minor'] : -$l['minor'];
            $st->execute([
                ':t' => $e->tenantId, ':en' => $e->entityId,
                ':acc' => $l['code'], ':cur' => $l['currency'], ':delta' => $delta,
            ]);
        }
    }

    public function balanceOf(string $tenantId, string $entityId, string $account, string $currency): Money
    {
        $st = $this->pdo->prepare(
            'SELECT net_minor FROM cloudbss_ledger_balances
             WHERE tenant_id = :t AND entity_id = :en AND account = :acc AND currency = :cur'
        );
        $st->execute([':t' => $tenantId, ':en' => $entityId, ':acc' => $account, ':cur' => $currency]);
        $v = $st->fetchColumn();
        return Money::ofMinor($v === false ? 0 : (int) $v, $currency);
    }

    /** Every (tenant, entity, currency) book nets to zero. */
    public function isBalanced(?string $tenantId = null, ?string $entityId = null): bool
    {
        $sql = 'SELECT COALESCE(SUM(net_minor), 0) AS net FROM cloudbss_ledger_balances';
        $params = [];
        if ($tenantId !== null) { $sql .= ' WHERE tenant_id = :t AND entity_id = :en'; $params = [':t' => $tenantId, ':en' => $entityId]; }
        $sql .= $tenantId !== null ? ' GROUP BY tenant_id, entity_id, currency' : ' GROUP BY tenant_id, entity_id, currency';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $net) {
            if ((int) $net !== 0) return false;
        }
        return true;
    }

    /** Recovery: recompute the whole balances table from the events log. */
    public function rebuildFrom(array $recordedEvents): void
    {
        $this->pdo->exec('TRUNCATE cloudbss_ledger_balances');
        foreach ($recordedEvents as $rec) $this->on($rec);
    }
}
