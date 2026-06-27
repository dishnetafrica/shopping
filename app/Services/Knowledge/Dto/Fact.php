<?php
namespace App\Services\Knowledge\Dto;

use App\Services\Knowledge\Source;

/** A durable, versionable truth extracted from a message. Plain value object — no DB. */
final class Fact
{
    public function __construct(
        public string $capability,
        public string $factType,
        public string $key,
        public array  $value = [],
        public float  $confidence = 1.0,
        public string $scope = 'durable',          // durable|dated
        public ?string $effectiveFrom = null,
        public string $source = Source::WHATSAPP,
    ) {}

    public function toArray(): array
    {
        return [
            'capability' => $this->capability, 'fact_type' => $this->factType, 'key' => $this->key,
            'value_json' => $this->value, 'confidence' => $this->confidence, 'scope' => $this->scope,
            'effective_from' => $this->effectiveFrom, 'source' => $this->source,
        ];
    }
}
