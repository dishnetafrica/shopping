<?php
namespace App\Services\Knowledge\Dto;

use App\Services\Knowledge\Source;

/** A requested operation extracted from a message. Plain value object — no DB. */
final class ActionRequest
{
    /**
     * @param array $entities  [{field,value,confidence}, ...] per-entity confidence
     */
    public function __construct(
        public string $capability,
        public string $actionType,
        public ?string $target = null,
        public array  $params = [],
        public array  $entities = [],
        public string $source = Source::WHATSAPP,
    ) {}

    /** Collapse key: same (action_type,target) supersede each other in one confirmation window. */
    public function collapseKey(): string
    {
        return $this->actionType . '|' . mb_strtolower(trim((string) $this->target));
    }

    public function toArray(): array
    {
        return [
            'capability' => $this->capability, 'action_type' => $this->actionType, 'target' => $this->target,
            'params_json' => $this->params, 'entities_json' => $this->entities, 'source' => $this->source,
        ];
    }
}
