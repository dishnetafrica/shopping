<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Event;

/** A DomainEvent once the store has assigned it an order. */
final class RecordedEvent
{
    public function __construct(
        public readonly DomainEvent $event,
        public readonly int $globalPosition,     // total order across the whole store
        public readonly int $aggregateSequence,  // per-aggregate order (1,2,3,...)
    ) {}
    public function name(): string { return $this->event->name; }
    public function payload(): array { return $this->event->payload; }
}
