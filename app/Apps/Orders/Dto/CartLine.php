<?php
namespace App\Apps\Orders\Dto;

/**
 * Models what the customer actually ordered — weight is first-class, so the "everything becomes
 * 1kg" bug cannot exist. product_id/variant_id/unit_price are filled by a ProductResolver that
 * consults OKE/catalogue; the extractor produces name + weight + qty.
 */
final class CartLine
{
    public function __construct(
        public string $name,
        public ?int $productId = null,
        public ?int $variantId = null,
        public ?int $weightGrams = null,     // null = sold by unit
        public int $quantity = 1,
        public ?float $unitPrice = null,
        public float $confidence = 1.0,
    ) {}

    public function lineTotal(): ?float
    {
        return $this->unitPrice === null ? null : round($this->unitPrice * $this->quantity, 2);
    }

    /** Stable identity for "replace the latest line" edits: same product + same weight. */
    public function key(): string
    {
        return mb_strtolower($this->name) . '|' . ($this->weightGrams ?? 'unit');
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name, 'product_id' => $this->productId, 'variant_id' => $this->variantId,
            'weight_grams' => $this->weightGrams, 'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice, 'line_total' => $this->lineTotal(), 'confidence' => $this->confidence,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''), $a['product_id'] ?? null, $a['variant_id'] ?? null,
            $a['weight_grams'] ?? null, (int) ($a['quantity'] ?? 1), $a['unit_price'] ?? null,
            (float) ($a['confidence'] ?? 1.0),
        );
    }
}
