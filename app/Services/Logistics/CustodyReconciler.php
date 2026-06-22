<?php

namespace App\Services\Logistics;

/**
 * Shipment Platform v1 — chain-of-custody reconciliation. Pure logic (no framework).
 *
 * The custody ledger records a box_count at each physical handoff:
 *
 *   packed(5) → received_by_transport(5) → arrived(5) → collected_by_rider(5) → delivered(5)
 *
 * Boxes should never change between handoffs. This compares each counted handoff to the one
 * before it and localises WHERE a discrepancy happened (which leg lost/gained boxes) rather than
 * only checking against the original total — that's what makes it actionable: "1 box lost between
 * transport and arrival" beats "1 box missing somewhere".
 *
 * Only events that actually carry a count are compared (e.g. `bus_departed` has no recount and is
 * skipped). `damaged` exceptions are raised explicitly by an actor, not inferred here.
 */
class CustodyReconciler
{
    public const MISSING = 'missing_boxes';
    public const EXTRA   = 'extra_boxes';
    public const DAMAGED = 'damaged_boxes';

    /**
     * @param list<array{stage:string,count:int|null}> $events  custody events in chronological order
     * @return list<array{type:string,from_stage:string,to_stage:string,expected:int,got:int,delta:int}>
     */
    public static function reconcile(array $events): array
    {
        // Keep only handoffs that recorded a real count, preserving order.
        $counted = [];
        foreach ($events as $e) {
            $c = $e['count'] ?? null;
            if ($c === null || $c === '') continue;
            $counted[] = ['stage' => (string) ($e['stage'] ?? ''), 'count' => (int) $c];
        }

        $exceptions = [];
        for ($i = 1; $i < count($counted); $i++) {
            $prev = $counted[$i - 1];
            $cur  = $counted[$i];
            $delta = $cur['count'] - $prev['count'];
            if ($delta === 0) continue;

            $exceptions[] = [
                'type'       => $delta < 0 ? self::MISSING : self::EXTRA,
                'from_stage' => $prev['stage'],
                'to_stage'   => $cur['stage'],
                'expected'   => $prev['count'],
                'got'        => $cur['count'],
                'delta'      => abs($delta),
            ];
        }
        return $exceptions;
    }

    /** True if the whole chain holds a constant count end-to-end. */
    public static function isClean(array $events): bool
    {
        return self::reconcile($events) === [];
    }

    /** Net boxes lost across the chain (baseline first-count minus last-count); 0 if none. */
    public static function netShortfall(array $events): int
    {
        $counts = [];
        foreach ($events as $e) {
            $c = $e['count'] ?? null;
            if ($c === null || $c === '') continue;
            $counts[] = (int) $c;
        }
        if (count($counts) < 2) return 0;
        return max(0, $counts[0] - $counts[count($counts) - 1]);
    }
}
