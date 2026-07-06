<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Event;

/** Transactional outbox: events wait here to be published exactly once. */
final class Outbox
{
    /** @var array<int,array{event:DomainEvent,published:bool}> */
    private array $rows = [];

    public function add(DomainEvent $e): void { $this->rows[] = ['event' => $e, 'published' => false]; }
    /** @return DomainEvent[] */
    public function pending(): array
    {
        return array_map(fn($r) => $r['event'], array_filter($this->rows, fn($r) => !$r['published']));
    }
    public function markAllPublished(): int
    {
        $n = 0;
        foreach ($this->rows as $i => $r) if (!$r['published']) { $this->rows[$i]['published'] = true; $n++; }
        return $n;
    }
}
