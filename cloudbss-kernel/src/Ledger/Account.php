<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

/** A chart-of-accounts entry. */
final class Account
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $type   // asset | liability | income | expense | equity
    ) {}
}
