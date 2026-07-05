<?php

namespace App\Services\Marketplace\Pricing;

/**
 * The running state threaded through the pricing pipeline. Each component reads
 * the current amount/currency, adjusts it, and appends one auditable line to
 * the breakdown — so a landed-cost quote can later be shown as a receipt
 * (base -> shipping -> import -> duty -> markup -> fx) without re-deriving it.
 */
final class PriceBuild
{
    /** @var array<int,array{label:string,detail:string,amount:float,currency:string}> */
    private array $breakdown = [];

    public function __construct(
        private float $amount,
        private string $currency,
    ) {
        $this->breakdown[] = [
            'label'    => 'Base price',
            'detail'   => 'Marketplace price',
            'amount'   => $amount,
            'currency' => $currency,
        ];
    }

    public function amount(): float   { return $this->amount; }
    public function currency(): string { return $this->currency; }

    /** Final customer price, rounded up to a whole unit of the currency. */
    public function sellPrice(): int
    {
        return (int) ceil($this->amount - 1e-9);
    }

    /** @return array<int,array{label:string,detail:string,amount:float,currency:string}> */
    public function breakdown(): array { return $this->breakdown; }

    /** A component adds to the amount (keeps the same currency). */
    public function add(float $delta, string $label, string $detail): void
    {
        $this->amount += $delta;
        $this->record($label, $detail);
    }

    /** A component scales the amount (e.g. markup, duty %). */
    public function multiply(float $factor, string $label, string $detail): void
    {
        $this->amount *= $factor;
        $this->record($label, $detail);
    }

    /** A component converts currency (e.g. UGX -> USD). */
    public function convert(float $newAmount, string $newCurrency, string $label, string $detail): void
    {
        $this->amount   = $newAmount;
        $this->currency = $newCurrency;
        $this->record($label, $detail);
    }

    private function record(string $label, string $detail): void
    {
        $this->breakdown[] = [
            'label'    => $label,
            'detail'   => $detail,
            'amount'   => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
