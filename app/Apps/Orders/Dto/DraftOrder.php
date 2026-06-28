<?php
namespace App\Apps\Orders\Dto;

/**
 * The persistent draft that every customer message updates. The Order Engine owns this; it asks
 * only for the smallest missing piece and creates the order once the required fields are present.
 */
final class DraftOrder
{
    /** Fields required before the order can be confirmed (in ask-priority order). */
    public const REQUIRED = ['lines', 'address', 'delivery_time', 'payment_method', 'phone'];

    /** @param CartLine[] $lines */
    public function __construct(
        public array $lines = [],
        public ?string $deliveryArea = null,
        public ?string $address = null,
        public ?string $landmark = null,
        public ?string $deliveryTime = null,
        public ?string $deliveryDate = null,
        public ?string $paymentMethod = null,
        public ?string $phone = null,
        public ?string $notes = null,
        public ?string $waitingFor = null,
        /** @var array<string,float> field => confidence */
        public array $confidence = [],
    ) {}

    public function has(string $field): bool
    {
        return match ($field) {
            'lines'         => $this->lines !== [],
            'address'       => $this->address !== null || $this->deliveryArea !== null,
            'delivery_time' => $this->deliveryTime !== null,
            'payment_method'=> $this->paymentMethod !== null,
            'phone'         => $this->phone !== null,
            default         => false,
        };
    }

    /** Required fields still missing, in ask order. @return string[] */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($f) => ! $this->has($f)));
    }

    public function isComplete(): bool { return $this->missing() === []; }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CartLine $l) => $l->toArray(), $this->lines),
            'delivery_area' => $this->deliveryArea, 'address' => $this->address, 'landmark' => $this->landmark,
            'delivery_time' => $this->deliveryTime, 'delivery_date' => $this->deliveryDate,
            'payment_method' => $this->paymentMethod, 'phone' => $this->phone, 'notes' => $this->notes,
            'waiting_for' => $this->waitingFor, 'confidence' => $this->confidence,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            array_map(fn ($l) => CartLine::fromArray($l), $a['lines'] ?? []),
            $a['delivery_area'] ?? null, $a['address'] ?? null, $a['landmark'] ?? null,
            $a['delivery_time'] ?? null, $a['delivery_date'] ?? null, $a['payment_method'] ?? null,
            $a['phone'] ?? null, $a['notes'] ?? null, $a['waiting_for'] ?? null, $a['confidence'] ?? [],
        );
    }
}
