<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Event;

/**
 * The ONE write model. Append-only, globally ordered, per-aggregate sequenced,
 * with optimistic concurrency. Everything else (ledger balances, task state,
 * read models) is a projection of what lands here.
 */
final class EventStore
{
    /** @var RecordedEvent[] */
    private array $log = [];
    /** @var array<string,int> aggregateId => last sequence */
    private array $sequences = [];
    private int $position = 0;

    /**
     * @param int|null $expectedVersion optimistic concurrency: last sequence the caller saw for this aggregate.
     * @throws ConcurrencyException
     */
    public function append(DomainEvent $e, ?int $expectedVersion = null): RecordedEvent
    {
        $current = $this->sequences[$e->aggregateId] ?? 0;
        if ($expectedVersion !== null && $expectedVersion !== $current) {
            throw new ConcurrencyException(
                "Concurrency conflict on {$e->aggregateId}: expected v{$expectedVersion}, store at v{$current}"
            );
        }
        $seq = $current + 1;
        $this->sequences[$e->aggregateId] = $seq;
        $rec = new RecordedEvent($e, ++$this->position, $seq);
        $this->log[] = $rec;
        return $rec;
    }

    /** @return RecordedEvent[] */
    public function all(): array { return $this->log; }
    /** @return RecordedEvent[] events for one aggregate, in order (for reconstitution). */
    public function forAggregate(string $aggregateId): array
    {
        return array_values(array_filter($this->log, fn($r) => $r->event->aggregateId === $aggregateId));
    }
    /** @return RecordedEvent[] everything after a global position (for resumable projections). */
    public function since(int $position): array
    {
        return array_values(array_filter($this->log, fn($r) => $r->globalPosition > $position));
    }
    public function versionOf(string $aggregateId): int { return $this->sequences[$aggregateId] ?? 0; }
    public function count(): int { return count($this->log); }
}
