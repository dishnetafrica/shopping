<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Support;
final class SystemClock implements Clock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } }
