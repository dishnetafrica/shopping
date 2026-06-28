<?php
namespace App\Services\Knowledge;

use App\Models\KnowledgeAction;
use App\Models\KnowledgeEvent;
use App\Services\Knowledge\Contracts\Classifier;

/**
 * Domain-free orchestrator. Knows NOTHING about any specific application domain — it only captures
 * events, classifies intent, routes to the owning Capability via the registry, persists extracted
 * Facts (append-only) and Actions (queued), and applies confirmed actions through the
 * ProjectionCoordinator. Hard invariants:
 *
 *   • The raw KnowledgeEvent is persisted FIRST and is never lost — extraction or projector
 *     failures only change its status, never delete it.
 *   • An unknown/unhandled intent fails gracefully: the event is stored, no exception escapes.
 *   • One message may yield many Facts AND many Actions (ExtractionResult carries both).
 *   • Adding a capability requires zero changes here — routing is pure registry lookup.
 *   • Projection goes through ProjectionCoordinator (the seam for retries/idempotency/ordering).
 */
class KnowledgeEngine
{
    public function __construct(
        private CapabilityRegistry $registry,
        private Classifier $classifier,
        private BusinessMemory $memory,
        private ProjectionCoordinator $coordinator,
    ) {}

    /** Capture → classify → route → extract → persist. Returns the (always-persisted) event. */
    public function ingest(int $tenantId, string $message, string $source = Source::WHATSAPP, ?string $senderRef = null, array $profile = []): KnowledgeEvent
    {
        // (1) CAPTURE — persist before anything can fail, so knowledge is never lost.
        $event = KnowledgeEvent::create([
            'tenant_id' => $tenantId, 'source' => $source, 'sender_ref' => $senderRef,
            'message' => $message, 'status' => 'received',
        ]);

        try {
            $intent = $this->classifier->classify($message, $profile) ?: Intent::UNKNOWN;
            $event->intent = $intent;

            $cap = $this->registry->forIntent($intent);
            if (! $cap) {                                   // (2) unknown capability → graceful, event kept
                $event->save();
                return $event;
            }
            $event->capability = $cap->name();

            $result = $cap->extractor()->extract($message, $profile);   // one message → facts + actions

            foreach ($result->facts as $fact) {
                $this->memory->record($tenantId, $fact, $event->id);     // append-only
            }
            foreach ($result->actions as $action) {
                KnowledgeAction::create($action->toArray() + [
                    'tenant_id' => $tenantId, 'event_id' => $event->id, 'status' => 'pending',
                ]);
            }

            $event->status = $result->isEmpty() ? 'received' : 'extracted';
            $event->save();
        } catch (\Throwable $e) {
            $event->status = 'failed';                       // event still intact for replay/Phase-3
            $event->save();
        }

        return $event;
    }

    /**
     * Apply confirmed actions through the ProjectionCoordinator. A projector failure marks only
     * that action rejected; the knowledge event and other actions are never lost.
     *
     * @param iterable<KnowledgeAction> $actions
     */
    public function applyActions(iterable $actions): ProjectionReport
    {
        $report = $this->coordinator->project($actions);

        foreach ($report->applied as $row) {
            $row['action']->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
        }
        foreach ($report->failed as $row) {
            $row['action']->forceFill(['status' => 'rejected'])->save();
        }
        return $report;
    }

    /** Convenience for a single action. */
    public function applyAction(KnowledgeAction $action): bool
    {
        return $this->applyActions([$action])->allOk();
    }
}
