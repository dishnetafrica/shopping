<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
/** Fixed, advanceable time for tests and replay. */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $t;
    public function __construct(string $at = '2026-01-01T00:00:00+00:00') { $this->t = new \DateTimeImmutable($at); }
    public function now(): \DateTimeImmutable { return $this->t; }
    public function advance(int $seconds): void { $this->t = $this->t->modify("+{$seconds} seconds"); }
}
