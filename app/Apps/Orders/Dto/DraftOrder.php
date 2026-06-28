<?php
namespace App\Apps\Orders\Dto;

/**
 * The persistent draft every customer message updates. Holds current state PLUS provenance (who
 * set each field, from which message, how confident) and locking (after confirmation, changes
 * become pending modifications instead of silent edits). The Order Engine asks only for the
 * smallest missing piece and creates the order once required fields are present.
 */
final class DraftOrder
{
    /** Required before the order can be confirmed. */
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
        /** @var array<string,array{source:string,confidence:float,message_id:?int,updated_at:string}> */
        public array $provenance = [],
        /** @var string[] locked field names */
        public array $locked = [],
        /** @var array<int,array{field:string,value:mixed,source:string,message_id:?int,at:string}> */
        public array $pendingModifications = [],
        public string $status = 'open',   // open | confirmed | paid
    ) {}

    public function has(string $field): bool
    {
        return match ($field) {
            'lines'          => $this->lines !== [],
            'address'        => $this->address !== null || $this->deliveryArea !== null,
            'delivery_time'  => $this->deliveryTime !== null,
            'payment_method' => $this->paymentMethod !== null,
            'phone'          => $this->phone !== null,
            default          => false,
        };
    }

    /** @return string[] required fields still missing, in declared order */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($f) => ! $this->has($f)));
    }

    public function isComplete(): bool { return $this->missing() === []; }

    public function isLocked(string $field): bool { return in_array($field, $this->locked, true); }

    public function lock(string $field): void { if (! $this->isLocked($field)) $this->locked[] = $field; }

    /** Lock every required field — called on confirmation. */
    public function lockRequired(): void { foreach (self::REQUIRED as $f) $this->lock($f); }

    public function addPending(string $field, $value, string $source, ?int $messageId): void
    {
        $this->pendingModifications[] = [
            'field' => $field, 'value' => $value, 'source' => $source,
            'message_id' => $messageId, 'at' => gmdate('c'),
        ];
    }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CartLine $l) => $l->toArray(), $this->lines),
            'delivery_area' => $this->deliveryArea, 'address' => $this->address, 'landmark' => $this->landmark,
            'delivery_time' => $this->deliveryTime, 'delivery_date' => $this->deliveryDate,
            'payment_method' => $this->paymentMethod, 'phone' => $this->phone, 'notes' => $this->notes,
            'waiting_for' => $this->waitingFor, 'confidence' => $this->confidence, 'provenance' => $this->provenance,
            'locked' => $this->locked, 'pending_modifications' => $this->pendingModifications, 'status' => $this->status,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            array_map(fn ($l) => CartLine::fromArray($l), $a['lines'] ?? []),
            $a['delivery_area'] ?? null, $a['address'] ?? null, $a['landmark'] ?? null,
            $a['delivery_time'] ?? null, $a['delivery_date'] ?? null, $a['payment_method'] ?? null,
            $a['phone'] ?? null, $a['notes'] ?? null, $a['waiting_for'] ?? null,
            $a['confidence'] ?? [], $a['provenance'] ?? [], $a['locked'] ?? [],
            $a['pending_modifications'] ?? [], $a['status'] ?? 'open',
        );
    }
}
