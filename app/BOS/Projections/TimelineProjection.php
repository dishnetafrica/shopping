<?php

namespace App\BOS\Projections;

use App\BOS\Event;

/**
 * The Timeline engine — the foundation of the Customer Timeline (PJ2) and every object timeline (PJ5).
 * One query: every event about a subject, newest first. Pure read; rebuildable at any time.
 */
class TimelineProjection extends Projection
{
    public function __construct(
        private string $subjectType,
        private int $subjectId,
        private int $tenantId,
        private int $limit = 100,
    ) {}

    public static function for(string $subjectType, int $subjectId, int $tenantId, int $limit = 100): self
    {
        return new self($subjectType, $subjectId, $tenantId, $limit);
    }

    /** @return array<int,array> one human-facing line per event, newest first. */
    public function build(): array
    {
        return Event::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->orderByDesc('occurred_at')->orderByDesc('recorded_at')
            ->limit($this->limit)
            ->get()
            ->map(fn (Event $e) => [
                'event'  => $e->name,
                'at'     => optional($e->occurred_at)->toIso8601String(),
                'actor'  => $e->actor_name ?: $e->actor_type,
                'from'   => $e->from_state,
                'to'     => $e->to_state,
                'data'   => $e->payload ?? [],
            ])
            ->all();
    }
}
