<?php

namespace App\BOS;

/**
 * Legacy → unified event migration (ADR-012). The rule: NEW features emit ONLY via Bos::emit(); the
 * existing per-domain event tables (bot_events, activity_feed, product_events, shipment_events,
 * owner_activity_log, order_draft_events…) are converged onto bos_events incrementally — never
 * duplicated into "two event worlds".
 *
 * Two supported moves, applied per capability during its slice:
 *   1. FORWARD (write side): where a legacy row is still written, add ONE line to also mirror it as a
 *      canonical event — `LegacyEventBridge::forward('BotQuoted', ...)`. Temporary, until the legacy
 *      write is replaced by Bos::emit() outright.
 *   2. BACKFILL (read side): a one-off command copies historical legacy rows into bos_events so the
 *      timeline is complete. After a domain is fully forwarded, its legacy table is frozen (read-only).
 *
 * This class is the single, auditable place that bridging happens — so it can be deleted, table by
 * table, as each domain finishes migrating. No bridging anywhere else.
 */
class LegacyEventBridge
{
    /** Mirror a legacy fact into the canonical spine (idempotent by the legacy row's stable key). */
    public static function forward(string $name, array $payload, array $meta = []): ?Event
    {
        // Idempotency key ties the canonical event to the legacy row, so re-running a backfill or a
        // double webhook never creates duplicates.
        if (empty($meta['idempotency_key']) && ! empty($meta['legacy_table']) && isset($meta['legacy_id'])) {
            $meta['idempotency_key'] = 'legacy:' . $meta['legacy_table'] . ':' . $meta['legacy_id'];
        }
        try {
            return Bos::emit($name, $payload, $meta + ['device' => $meta['device'] ?? 'legacy']);
        } catch (\Throwable $e) {
            \Log::warning('LegacyEventBridge forward failed for ' . $name . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Example bridge (BotQuoted from the existing bot_events table). Real bridges are added per domain
     * inside that domain's slice, each with its own subject mapping — this shows the shape.
     */
    public static function bridgeBotEvent(object $row): ?Event
    {
        return self::forward('BotQuoted', [
            'trace'  => $row->trace ?? null,
            'stage'  => $row->stage ?? null,
            'detail' => $row->detail ?? null,
        ], [
            'tenant_id'    => (int) ($row->tenant_id ?? 0),
            'subject_type' => 'customer',
            'subject_id'   => 0, // mapped from customer_phone during the Sell slice
            'actor'        => ['type' => 'bot'],
            'occurred_at'  => $row->created_at ?? null,
            'legacy_table' => 'bot_events',
            'legacy_id'    => $row->id ?? null,
        ]);
    }
}
