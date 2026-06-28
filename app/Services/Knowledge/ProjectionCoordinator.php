<?php
namespace App\Services\Knowledge;

/**
 * Single seam between the engine and capability Projectors. Today it just loops in order and
 * isolates failures (one projector throwing does not abort the rest) with an idempotency skip.
 * It is the future home of retries, ordering policy, event publication and projection rebuilds —
 * so no capability ever reinvents those. Pure: it calls projectors and reports outcomes; the
 * caller (engine) persists status from the report.
 */
class ProjectionCoordinator
{
    public function __construct(private CapabilityRegistry $registry) {}

    /**
     * @param iterable<object> $actions each exposes ->capability, ->status (and is accepted by the projector)
     */
    public function project(iterable $actions): ProjectionReport
    {
        $report = new ProjectionReport();
        foreach ($actions as $action) {
            $cap = $this->registry->get((string) ($action->capability ?? ''));
            if (! $cap) { $report->fail($action, 'no_capability'); continue; }
            if (($action->status ?? null) === 'applied') { $report->skip($action); continue; }  // idempotent
            try {
                $label = $cap->projector()->apply($action);
                $report->ok($action, $label);
            } catch (\Throwable $e) {
                $report->fail($action, $e->getMessage());   // partial failure isolated; others continue
            }
        }
        return $report;
    }
}
