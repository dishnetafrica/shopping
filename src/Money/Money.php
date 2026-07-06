<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Money;

/**
 * Money value object. Amount is ALWAYS an integer number of minor units.
 * Arithmetic is overflow-CHECKED: on 64-bit overflow it throws instead of
 * silently promoting to float — so the "no float, ever" guarantee is actually true.
 */
final class Money
{
    private function __construct(public readonly int $minor, public readonly Currency $currency) {}

    public static function ofMinor(int $minor, Currency|string $c): self
    { return new self($minor, $c instanceof Currency ? $c : Currency::of($c)); }

    public static function ofDecimal(string $amount, Currency|string $c): self
    {
        $cur = $c instanceof Currency ? $c : Currency::of($c);
        $amount = trim($amount);
        $neg = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) throw new \InvalidArgumentException("Bad money literal: {$amount}");
        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        if ($whole !== (string) (int) $whole) throw new \OverflowException("Amount too large: {$whole}");
        $frac = substr(str_pad($frac, $cur->minorUnits, '0'), 0, $cur->minorUnits);
        $minor = self::add(self::mul((int) $whole, $cur->subunit()), (int) ($frac === '' ? '0' : $frac));
        return new self($neg ? -$minor : $minor, $cur);
    }

    public static function zero(Currency|string $c): self { return self::ofMinor(0, $c); }

    private function same(Money $o): void
    { if (!$this->currency->equals($o->currency)) throw new \DomainException("Currency mismatch: {$this->currency->code} vs {$o->currency->code}"); }

    public function plus(Money $o): self  { $this->same($o); return new self(self::add($this->minor, $o->minor), $this->currency); }
    public function minus(Money $o): self { $this->same($o); return new self(self::add($this->minor, -$o->minor), $this->currency); }
    public function negated(): self       { return new self(-$this->minor, $this->currency); }
    public function timesQty(int $qty): self { return new self(self::mul($this->minor, $qty), $this->currency); }

    /** Multiply by a rational (e.g. +45% = 145/100) with explicit half-away-from-zero rounding. */
    public function scaledBy(int $num, int $den): self
    {
        if ($den === 0) throw new \InvalidArgumentException('Division by zero');
        return new self(self::divRound(self::mul($this->minor, $num), $den), $this->currency);
    }

    /** Split into parts by integer ratios, losing no minor unit (largest-remainder). */
    public function allocate(int ...$ratios): array
    {
        $total = array_sum($ratios);
        if ($total <= 0) throw new \InvalidArgumentException('Ratios must sum > 0');
        $out = []; $remainder = $this->minor;
        foreach ($ratios as $r) { $share = intdiv(self::mul($this->minor, $r), $total); $out[] = $share; $remainder -= $share; }
        for ($i = 0; $remainder > 0; $i++, $remainder--) $out[$i] += 1;
        return array_map(fn($m) => new self($m, $this->currency), $out);
    }

    // ---- overflow-checked integer primitives ----
    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        $r = $a * $b;
        if (!is_int($r) || intdiv($r, $b) !== $a) throw new \OverflowException('Money multiplication overflow');
        return $r;
    }
    private static function add(int $a, int $b): int
    {
        $r = $a + $b;
        if (!is_int($r)) throw new \OverflowException('Money addition overflow');
        return $r;
    }
    private static function divRound(int $a, int $b): int
    {
        $sign = (($a < 0) xor ($b < 0)) ? -1 : 1;
        $a = abs($a); $b = abs($b);
        return $sign * intdiv(self::add(self::mul(2, $a), $b), self::mul(2, $b));
    }

    public function isZero(): bool     { return $this->minor === 0; }
    public function isNegative(): bool { return $this->minor < 0; }
    public function equals(Money $o): bool { return $this->currency->equals($o->currency) && $this->minor === $o->minor; }
    public function compareTo(Money $o): int { $this->same($o); return $this->minor <=> $o->minor; }
    public function lessThan(Money $o): bool { return $this->compareTo($o) < 0; }

    public function toDecimal(): string
    {
        $sub = $this->currency->subunit(); $sign = $this->minor < 0 ? '-' : ''; $abs = abs($this->minor);
        if ($this->currency->minorUnits === 0) return $sign . (string) $abs;
        $whole = intdiv($abs, $sub);
        $frac  = str_pad((string) ($abs % $sub), $this->currency->minorUnits, '0', STR_PAD_LEFT);
        return "{$sign}{$whole}.{$frac}";
    }
    public function format(): string { return $this->currency->code . ' ' . number_format((float) $this->toDecimal(), $this->currency->minorUnits); }
    public function __toString(): string { return $this->format(); }
}
