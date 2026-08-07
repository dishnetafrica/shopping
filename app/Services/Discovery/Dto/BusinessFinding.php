<?php declare(strict_types=1);

namespace App\Services\Discovery\Dto;

/**
 * v4.59 — one researched fact about one business, from one source.
 *
 * A deliberate copy of `App\Services\Research\Dto\Finding`, which has been carrying product facts in
 * production for months. Same five parts, same discipline, different vocabulary — because the two
 * pipelines will diverge (a business has branches and opening hours; a product has a barcode) and
 * coupling them to save forty lines would cost more the first time either needs to change.
 *
 * A finding is a CLAIM, never a decision. It carries where it came from and how sure that source
 * is; whether it is ever written to a customer record is decided later, by a human at a review
 * queue. Nothing downstream may treat one as fact.
 */
final class BusinessFinding
{
    /** Fields a provider may assert. Anything else is dropped rather than stored as a surprise. */
    public const FIELDS = [
        'name', 'phone', 'alt_phone', 'email', 'website', 'address', 'city', 'country',
        'category', 'contact_person', 'latitude', 'longitude', 'size', 'external_id',
    ];

    public function __construct(
        public readonly string $field,
        public readonly string $value,
        public readonly float $confidence,    // [0,1]
        public readonly string $source,       // provider key: 'csv' | 'places' | 'website' | …
        public readonly string $note = '',    // human-readable provenance, shown in the review queue
    ) {
    }

    /** A finding for a field nobody asked for, or with nothing in it, is not a finding. */
    public function isUsable(): bool
    {
        return in_array($this->field, self::FIELDS, true)
            && trim($this->value) !== ''
            && $this->confidence > 0.0;
    }

    /** Clamp on the way in — a provider returning 1.5 is a bug that must not become a ranking. */
    public static function make(string $field, string $value, float $confidence, string $source, string $note = ''): self
    {
        return new self(
            trim($field),
            trim($value),
            max(0.0, min(1.0, $confidence)),
            trim($source),
            trim($note),
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
