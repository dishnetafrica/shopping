<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
/** Deterministic ids for tests/replay: prefix_1, prefix_2, ... */
final class SequenceIdGenerator implements IdGenerator
{
    private array $counters = [];
    public function next(string $prefix = ''): string
    { $p = $prefix ?: 'id'; $this->counters[$p] = ($this->counters[$p] ?? 0) + 1; return "{$p}_{$this->counters[$p]}"; }
}
