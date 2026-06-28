<?php
namespace App\Services\Knowledge\Observatory;

use App\Models\KnowledgeAction;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeMetric;
use App\Services\Knowledge\EntityConfidence;

/**
 * Records the full extraction outcome for every owner message. An OBSERVER of the engine — it
 * reads the persisted event/actions/facts after ingest and never participates in extraction or
 * projection. The bucket (success|partial|fallback|unknown) is derived, not guessed.
 */
class BehaviorObservatory
{
    public function record(KnowledgeEvent $event, int $processingMs): KnowledgeMetric
    {
        $actions = KnowledgeAction::where('event_id', $event->id)->get();
        $facts   = KnowledgeFact::where('event_id', $event->id)->get();

        return KnowledgeMetric::create([
            'tenant_id'     => $event->tenant_id,
            'event_id'      => $event->id,
            'capability'    => $event->capability,
            'intent'        => $event->intent,
            'confidence'    => $this->confidence($actions, $facts),
            'result'        => $this->bucket($event, $actions->count(), $facts->count()),
            'language'      => LanguageDetector::detect((string) $event->message),
            'processing_ms' => $processingMs,
            'sample'        => mb_substr((string) $event->message, 0, 280),
        ]);
    }

    /** Update the metric once the owner accepts/rejects (and optional manual correction). */
    public function recordConfirmation(int $eventId, bool $accepted, ?string $correction = null): void
    {
        KnowledgeMetric::where('event_id', $eventId)
            ->update(['confirmed' => $accepted, 'correction' => $correction]);
    }

    private function bucket(KnowledgeEvent $event, int $actions, int $facts): string
    {
        if ($event->status === 'failed')   return 'fallback';
        if (empty($event->capability))      return 'unknown';   // no capability claimed the intent
        if ($actions > 0)                   return 'success';
        if ($facts > 0)                     return 'partial';    // facts but nothing actionable
        return 'fallback';
    }

    private function confidence($actions, $facts): ?float
    {
        $scores = [];
        foreach ($actions as $a) {
            $e = $a->entities_json ?? [];
            if ($e) $scores[] = EntityConfidence::rollup($e);
        }
        foreach ($facts as $f) { $scores[] = (float) $f->confidence; }
        return $scores ? round(array_sum($scores) / count($scores), 3) : null;
    }
}
