<?php

namespace App\Capability\Dto;

final class TimelineEvent
{
    public function __construct(
        public string $at,
        public string $type,
        public string $title,
        public ?string $detail = null,
        public ?string $actor = null,
        public ?string $link = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'at' => $this->at, 'type' => $this->type, 'title' => $this->title,
            'detail' => $this->detail, 'actor' => $this->actor, 'link' => $this->link,
        ], fn ($v) => $v !== null);
    }
}
