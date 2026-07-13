<?php

namespace App\Capability\Dto;

/** A knowledge-graph edge from this object to a related one. */
final class Related
{
    public function __construct(
        public string $rel,
        public string $type,
        public ?string $id = null,
        public ?string $label = null,
        public ?string $sublabel = null,
        public ?int $count = null,
        public ?string $link = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'rel' => $this->rel, 'type' => $this->type, 'id' => $this->id, 'label' => $this->label,
            'sublabel' => $this->sublabel, 'count' => $this->count, 'link' => $this->link,
        ], fn ($v) => $v !== null);
    }
}
