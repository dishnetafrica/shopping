<?php

namespace App\Capability\Dto;

/** A backend-driven action. The frontend renders it and calls $endpoint — it hard-codes no workflow. */
final class ActionSpec
{
    public function __construct(
        public string $key,
        public string $label,
        public string $endpoint,
        public string $method = 'POST',
        public string $actor = 'human',   // 'human' | 'ai'
        public string $tone = 'default',
        public ?array $schema = null,
        public ?string $confirm = null,
        public ?float $confidence = null,
        public ?string $rationale = null,
        /** v4.52 — POST payload, for briefs that call an endpoint outside their own capability. */
        public ?array $body = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key, 'label' => $this->label, 'method' => $this->method, 'endpoint' => $this->endpoint,
            'actor' => $this->actor, 'tone' => $this->tone, 'schema' => $this->schema, 'confirm' => $this->confirm,
            'confidence' => $this->confidence, 'rationale' => $this->rationale, 'body' => $this->body,
        ], fn ($v) => $v !== null);
    }
}
