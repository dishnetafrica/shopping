<?php declare(strict_types=1);
namespace CloudBSS\Kernel\Money;

/** A currency and how many minor units it has. */
final class Currency
{
    private const REG = ['USD' => 2, 'UGX' => 0, 'SSP' => 2, 'KES' => 2, 'EUR' => 2];

    private function __construct(public readonly string $code, public readonly int $minorUnits) {}

    public static function of(string $code): self
    {
        $code = strtoupper($code);
        if (!isset(self::REG[$code])) throw new \InvalidArgumentException("Unknown currency: {$code}");
        return new self($code, self::REG[$code]);
    }

    public function equals(Currency $o): bool { return $this->code === $o->code; }
    public function subunit(): int { return 10 ** $this->minorUnits; }
}
