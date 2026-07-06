<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
/** Time is injected, never read from the system directly — so replay is deterministic. */
interface Clock { public function now(): \DateTimeImmutable; }
