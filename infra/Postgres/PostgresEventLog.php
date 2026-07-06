<?php declare(strict_types=1);

namespace CloudBSS\Infra\Postgres;

use CloudBSS\Kernel\Event\DomainEvent;
use CloudBSS\Kernel\Event\RecordedEvent;

/**
 * Durable, append-only event log on Postgres.
 *
 * This is an ADAPTER, not a replacement for the kernel's EventStore. The kernel's in-memory
 * EventStore is the working write model within one process (it assigns global_position and the
 * per-aggregate sequence). This class PERSISTS every RecordedEvent the kernel publishes, and can
 * LOAD the whole log back so a fresh process can rehydrate identical state.
 *
 * It deliberately mirrors the read surface of the kernel EventStore (all/since/forAggregate/
 * versionOf/count) so callers reason about it the same way. It touches nothing in kernel/src —
 * the freeze holds.
 */
final class PostgresEventLog
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Create the schema if it isn't there yet. Safe to call on boot. */
    public function migrate(): void
    {
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
    }

    /**
     * Persist one recorded event. Postgres assigns global_position (identity) — so the DB is the
     * single ordering authority and short-lived processes don't need to replay the log to append.
     * Idempotent on event_id (a retry/re-publish is a no-op), and UNIQUE(aggregate_id,
     * aggregate_sequence) rejects a concurrency conflict at the storage layer.
     */
    public function append(RecordedEvent $rec): void
    {
        $e = $rec->event;
        $sql = 'INSERT INTO cloudbss_events
            (event_id, name, schema_version, aggregate_type, aggregate_id,
             aggregate_sequence, payload, occurred_at, tenant_id, entity_id, correlation_id, causation_id)
            VALUES
            (:eid, :name, :sv, :atype, :aid, :aseq, :payload, :occ, :tid, :enid, :corr, :caus)
            ON CONFLICT (event_id) DO NOTHING';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':eid'     => $e->eventId,
            ':name'    => $e->name,
            ':sv'      => $e->schemaVersion,
            ':atype'   => $e->aggregateType,
            ':aid'     => $e->aggregateId,
            ':aseq'    => $rec->aggregateSequence,
            ':payload' => json_encode($e->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':occ'     => $e->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ':tid'     => $e->tenantId,
            ':enid'    => $e->entityId,
            ':corr'    => $e->correlationId,
            ':caus'    => $e->causationId,
        ]);
    }

    /** @return RecordedEvent[] the whole log, in global order (for rehydration). */
    public function load(): array
    {
        return $this->query('SELECT * FROM cloudbss_events ORDER BY global_position ASC');
    }

    /** @return RecordedEvent[] everything strictly after a global position (resumable projections). */
    public function since(int $position): array
    {
        return $this->query(
            'SELECT * FROM cloudbss_events WHERE global_position > :p ORDER BY global_position ASC',
            [':p' => $position]
        );
    }

    /** @return RecordedEvent[] one aggregate's events, in sequence (for reconstitution). */
    public function forAggregate(string $aggregateId): array
    {
        return $this->query(
            'SELECT * FROM cloudbss_events WHERE aggregate_id = :a ORDER BY aggregate_sequence ASC',
            [':a' => $aggregateId]
        );
    }

    /** Last persisted sequence for an aggregate (0 if none) — the value to pass as expectedVersion. */
    public function versionOf(string $aggregateId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(aggregate_sequence), 0) FROM cloudbss_events WHERE aggregate_id = :a');
        $st->execute([':a' => $aggregateId]);
        return (int) $st->fetchColumn();
    }

    /** Highest global position persisted (0 if empty). */
    public function lastPosition(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(global_position), 0) FROM cloudbss_events')->fetchColumn();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cloudbss_events')->fetchColumn();
    }

    /** @return RecordedEvent[] */
    private function query(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->toRecorded($row);
        }
        return $out;
    }

    private function toRecorded(array $row): RecordedEvent
    {
        $event = new DomainEvent(
            eventId:       (string) $row['event_id'],
            name:          (string) $row['name'],
            schemaVersion: (int) $row['schema_version'],
            aggregateType: (string) $row['aggregate_type'],
            aggregateId:   (string) $row['aggregate_id'],
            payload:       json_decode((string) $row['payload'], true) ?: [],
            occurredAt:    new \DateTimeImmutable((string) $row['occurred_at']),
            tenantId:      (string) $row['tenant_id'],
            entityId:      (string) $row['entity_id'],
            correlationId: $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            causationId:   $row['causation_id'] !== null ? (string) $row['causation_id'] : null,
        );

        return new RecordedEvent(
            $event,
            (int) $row['global_position'],
            (int) $row['aggregate_sequence'],
        );
    }
}
