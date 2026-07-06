<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Event;

/**
 * Modules and projections subscribe here. The kernel never changes to admit a new
 * consumer — Inventory, CRM, HR, etc. register handlers; the ledger is just one subscriber.
 */
final class EventBus
{
    /** @var array<string, callable[]> event name => handlers */
    private array $byName = [];
    /** @var callable[] */
    private array $catchAll = [];

    public function subscribe(string $eventName, callable $handler): void { $this->byName[$eventName][] = $handler; }
    public function subscribeAll(callable $handler): void { $this->catchAll[] = $handler; }

    public function publish(RecordedEvent $rec): void
    {
        foreach ($this->byName[$rec->name()] ?? [] as $h) $h($rec);
        foreach ($this->catchAll as $h) $h($rec);
    }
}
