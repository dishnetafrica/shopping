<?php

namespace App\Capability\Dto;

final class Financial
{
    public function __construct(
        public string $key,
        public string $label,
        public float|int|string $value,
        public ?string $currency = null,
        public string $tone = 'default',
        public ?string $format = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key, 'label' => $this->label, 'value' => $this->value,
            'currency' => $this->currency, 'tone' => $this->tone, 'format' => $this->format,
        ], fn ($v) => $v !== null);
    }
}
