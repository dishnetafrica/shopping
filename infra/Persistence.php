<?php declare(strict_types=1);

namespace CloudBSS\Infra;

use CloudBSS\Infra\Postgres\PostgresEventLog;
use CloudBSS\Kernel\Event\EventBus;
use CloudBSS\Kernel\Event\EventStore;
use CloudBSS\Kernel\Event\RecordedEvent;

/**
 * Wires durable persistence around the FROZEN kernel without editing it.
 *
 * The kernel's EventStore is final and the Kernel type-hints it, so we cannot substitute a
 * Postgres store into the kernel (that would need an ADR to extract an EventStore interface —
 * see PERSISTENCE-NOTES.md). Instead we use the two public seams the kernel already exposes:
 *
 *   WRITE  → every event the kernel publishes on the EventBus is appended to the durable log.
 *   BOOT   → on startup we replay the durable log into a fresh in-memory EventStore via its
 *            public append(), which rebuilds global_position + per-aggregate sequences WITHOUT
 *            publishing — so the kernel's auto-posting reactor does NOT re-fire and we never
 *            double-post. Projections are then rebuilt from the now-complete store.
 *
 * Ordering to observe on boot:
 *   1) build store + bus + kernel + projections
 *   2) Persistence::rehydrate(...)        // replay history into the store (no publish)
 *   3) rebuild projections from store->all()
 *   4) Persistence::persistOnPublish(...) // ONLY now attach durable-write, so history isn't re-written
 *   5) go live — new commands persist + project + continue the position sequence correctly
 */
final class Persistence
{
    /**
     * Durably record every NEW event the kernel publishes. Attach AFTER rehydrate().
     * Wrap each command in a PDO transaction so the in-memory append and the durable write
     * commit together (request-scoped PHP: on failure the request ends and the discarded
     * in-memory store never diverges from the committed log).
     */
    public static function persistOnPublish(EventBus $bus, PostgresEventLog $log): void
    {
        $bus->subscribeAll(static function (RecordedEvent $rec) use ($log): void {
            $log->append($rec);
        });
    }

    /**
     * Rebuild the in-memory EventStore from the durable log. Replays each stored event through
     * the store's public append() (which re-derives identical global_position + sequence) but
     * deliberately does NOT publish — reactors must not fire during replay.
     *
     * @return int number of events replayed
     */
    public static function rehydrate(EventStore $store, PostgresEventLog $log): int
    {
        $n = 0;
        foreach ($log->load() as $rec) {
            $store->append($rec->event);   // no expectedVersion, no publish → no reactor, no double-post
            $n++;
        }
        return $n;
    }
}
