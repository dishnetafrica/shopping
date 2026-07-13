<?php

namespace App\Capability\Dto;

/** The assistant briefing: what/why/risk/next. Pure data — no HTML. */
final class Brief
{
    public function __construct(
        public string $level,
        public string $situation,
        public string $why,
        public ?array $risk = null,
        public array $metrics = [],
        public ?string $recommendation = null,
        public array $actions = [],
        public array $sources = [],
    ) {}

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'risk' => $this->risk,
            'situation' => $this->situation,
            'why' => $this->why,
            'metrics' => $this->metrics,
            'recommendation' => $this->recommendation,
            'actions' => array_map(fn ($a) => $a instanceof ActionSpec ? $a->toArray() : $a, $this->actions),
            'sources' => $this->sources,
        ];
    }
}
