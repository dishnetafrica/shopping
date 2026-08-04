<?php

namespace App\Services\Research\Dto;

/**
 * One researched fact about one product field.
 *
 * A Finding is a CLAIM, never a decision. It carries where it came from and how
 * sure the source is; whether it is ever written is decided later, by
 * ProductResearch::plan(), against what the product already holds.
 */
final class Finding
{
    public function __construct(
        public readonly string $field,        // brand | unit_size | origin_country | category | description
        public readonly string $value,
        public readonly float $confidence,    // [0,1]
        public readonly string $source,       // provider key: 'barcode' | 'ai' | 'written'
        public readonly string $note = '',    // human-readable provenance, shown in the review queue
    ) {
    }

    public function with(string $value, ?float $confidence = null, string $note = ''): self
    {
        return new self(
            $this->field,
            $value,
            $confidence ?? $this->confidence,
            $this->source,
            $note !== '' ? $note : $this->note,
        );
    }

    public function toArray(): array
    {
        return [
            'field'      => $this->field,
            'value'      => $this->value,
            'confidence' => round($this->confidence, 3),
            'source'     => $this->source,
            'note'       => $this->note,
        ];
    }
}
