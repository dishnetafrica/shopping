<?php
namespace App\Apps\Orders\Dto;

/**
 * Everything one message yielded — the extractor never throws information away. Each field carries
 * a confidence so the Order Engine can decide what to trust vs. re-confirm (confidence per FIELD).
 */
final class EntityBundle
{
    /**
     * @param array<int,array{name:string,weight_grams:?int,qty:int,confidence:float}> $products
     * @param array<string,float> $confidence  field => confidence
     */
    public function __construct(
        public array $products = [],
        public ?string $deliveryArea = null,
        public ?string $address = null,
        public ?string $landmark = null,
        public ?string $deliveryTime = null,
        public ?string $deliveryDate = null,
        public ?string $paymentMethod = null,
        public ?string $phone = null,
        public ?string $notes = null,
        public array $confidence = [],
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->products && $this->deliveryArea === null && $this->address === null
            && $this->deliveryTime === null && $this->paymentMethod === null && $this->phone === null
            && $this->landmark === null && $this->notes === null;
    }
}
