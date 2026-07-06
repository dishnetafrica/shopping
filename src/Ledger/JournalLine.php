<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Ledger;

use CloudBSS\Kernel\Money\Money;

/** One debit or credit line. */
final class JournalLine
{
    private function __construct(
        public readonly Account $account,
        public readonly Money $amount,
        public readonly string $side   // 'debit' | 'credit'
    ) { if ($amount->isNegative()) throw new \DomainException('Line amount must be non-negative'); }

    public static function debit(Account $a, Money $m): self  { return new self($a, $m, 'debit'); }
    public static function credit(Account $a, Money $m): self { return new self($a, $m, 'credit'); }
}
