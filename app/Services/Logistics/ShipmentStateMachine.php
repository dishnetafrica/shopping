<?php

namespace App\Services\Logistics;

/**
 * Shipment Platform v1 — transport-leg state machine. Pure logic (no framework).
 *
 * Deliberately separate from Order.status (shop lifecycle) and Delivery.status (last mile).
 * A shipment moves through the long-distance transport leg only:
 *
 *   packed → sent_to_transporter → transport_confirmed → in_transit → arrived
 *
 * After `arrived`, the last mile is owned by the existing Delivery model. `cancelled` is a
 * terminal reachable from any non-terminal state. Each forward action also names the custody
 * EVENT it emits and which ACTOR performs it (used by ShipmentService to write the ledger).
 */
class ShipmentStateMachine
{
    public const PACKED              = 'packed';
    public const SENT_TO_TRANSPORTER = 'sent_to_transporter';
    public const TRANSPORT_CONFIRMED = 'transport_confirmed';
    public const IN_TRANSIT          = 'in_transit';
    public const ARRIVED             = 'arrived';
    public const CANCELLED           = 'cancelled';

    /** Forward happy path. */
    public const FLOW = [
        self::PACKED, self::SENT_TO_TRANSPORTER, self::TRANSPORT_CONFIRMED,
        self::IN_TRANSIT, self::ARRIVED,
    ];

    /** Actors who perform transitions (also custody-event actors). */
    public const ACTOR_SHOP      = 'shop';
    public const ACTOR_TRANSPORT = 'transport';
    public const ACTOR_AGENT     = 'destination_agent';
    public const ACTOR_RIDER     = 'rider';
    public const ACTOR_SYSTEM    = 'system';

    /**
     * action => [from, to, event, actor, counts]
     *  - event : the custody-ledger event this action records
     *  - actor : who performs it
     *  - counts: whether this action carries a box_count to reconcile
     */
    public const ACTIONS = [
        'dispatch' => [
            'from' => self::PACKED, 'to' => self::SENT_TO_TRANSPORTER,
            'event' => 'sent_to_transport', 'actor' => self::ACTOR_SHOP, 'counts' => true,
        ],
        'transport_confirm' => [
            'from' => self::SENT_TO_TRANSPORTER, 'to' => self::TRANSPORT_CONFIRMED,
            'event' => 'received_by_transport', 'actor' => self::ACTOR_TRANSPORT, 'counts' => true,
        ],
        'depart' => [
            'from' => self::TRANSPORT_CONFIRMED, 'to' => self::IN_TRANSIT,
            'event' => 'bus_departed', 'actor' => self::ACTOR_TRANSPORT, 'counts' => false,
        ],
        'arrive' => [
            'from' => self::IN_TRANSIT, 'to' => self::ARRIVED,
            'event' => 'arrived', 'actor' => self::ACTOR_AGENT, 'counts' => true,
        ],
    ];

    public static function isTerminal(string $status): bool
    {
        return $status === self::ARRIVED || $status === self::CANCELLED;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::FLOW, true) || $status === self::CANCELLED;
    }

    /** Next forward status, or null at the end / for unknown input. */
    public static function next(string $status): ?string
    {
        $i = array_search($status, self::FLOW, true);
        if ($i === false || $i >= count(self::FLOW) - 1) return null;
        return self::FLOW[$i + 1];
    }

    /** The action descriptor whose `from` matches $status, or null. */
    public static function actionFrom(string $status): ?array
    {
        foreach (self::ACTIONS as $name => $spec) {
            if ($spec['from'] === $status) return ['action' => $name] + $spec;
        }
        return null;
    }

    public static function canApply(string $status, string $action): bool
    {
        if ($action === 'cancel') return ! self::isTerminal($status);
        $spec = self::ACTIONS[$action] ?? null;
        return $spec !== null && $spec['from'] === $status;
    }

    /**
     * Resolve a transition. Returns ['ok'=>true,'to'=>..,'event'=>..,'actor'=>..,'counts'=>..]
     * or ['ok'=>false,'error'=>..] — never throws, so callers can branch cleanly.
     */
    public static function apply(string $status, string $action): array
    {
        if ($action === 'cancel') {
            if (self::isTerminal($status)) {
                return ['ok' => false, 'error' => "cannot cancel a {$status} shipment"];
            }
            return ['ok' => true, 'to' => self::CANCELLED, 'event' => 'cancelled',
                    'actor' => self::ACTOR_SYSTEM, 'counts' => false];
        }

        $spec = self::ACTIONS[$action] ?? null;
        if ($spec === null) {
            return ['ok' => false, 'error' => "unknown action '{$action}'"];
        }
        if ($spec['from'] !== $status) {
            return ['ok' => false, 'error' => "action '{$action}' needs status '{$spec['from']}', shipment is '{$status}'"];
        }
        return ['ok' => true, 'to' => $spec['to'], 'event' => $spec['event'],
                'actor' => $spec['actor'], 'counts' => $spec['counts']];
    }
}
