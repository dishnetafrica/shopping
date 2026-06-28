<?php
namespace App\Services\Knowledge\Observatory;

use App\Models\KnowledgeEvent;
use App\Services\Knowledge\KnowledgeEngine;
use App\Services\Knowledge\Source;

/**
 * Decorator that wraps KnowledgeEngine to time each ingest and record a metric — without the
 * engine knowing the Observatory exists. This is how OKE runs in "observe mode": every owner
 * message is captured + classified + measured, while the live merchant apply-path is untouched.
 * A metrics failure never affects ingest (the observation is best-effort).
 */
class ObservingEngine
{
    public function __construct(
        private KnowledgeEngine $engine,
        private BehaviorObservatory $observatory,
    ) {}

    public function ingest(int $tenantId, string $message, string $source = Source::WHATSAPP, ?string $senderRef = null, array $profile = []): KnowledgeEvent
    {
        $t0 = hrtime(true);
        $event = $this->engine->ingest($tenantId, $message, $source, $senderRef, $profile);
        $ms = (int) ((hrtime(true) - $t0) / 1e6);

        try {
            $this->observatory->record($event, $ms);
        } catch (\Throwable $e) {
            // best-effort observation — never break the pipeline for a metrics write
        }
        return $event;
    }
}
