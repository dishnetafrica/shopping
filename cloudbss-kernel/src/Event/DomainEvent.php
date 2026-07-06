<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Event;

/**
 * An immutable authored fact. Ordering metadata (globalPosition, aggregateSequence)
 * is assigned by the EventStore on append — see RecordedEvent.
 * Tenant/entity are METADATA the kernel never branches on; projections use them to scope read models.
 */
final class DomainEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $name,
        public readonly int    $schemaVersion,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly array  $payload,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly ?string $correlationId = null,   // which command/flow
        public readonly ?string $causationId  = null,    // which event caused this one
    ) {}
}
