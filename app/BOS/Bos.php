<?php

namespace App\BOS;

use App\BOS\Projections\TimelineProjection;
use App\BOS\Rules\RuleRegistry;

/**
 * The internal BOS SDK — one small toolkit every capability uses, so implementations stay consistent
 * (event spine, rules, timelines). Keep this thin: it delegates to the registries/stores.
 *
 *   Bos::emit('PaymentReceived', $payload, ['subject_type'=>'invoice','subject_id'=>$id]);
 *   Bos::enforce('R3', ['kind'=>'settlement','amount'=>$a,'balance'=>$b]);
 *   $lines = Bos::timeline('customer', $customerId, $tenantId);
 */
final class Bos
{
    /** Record a business fact + fan out to subscribers (idempotent). */
    public static function emit(string $name, array $payload = [], array $meta = []): Event
    {
        return EventStore::instance()->emit($name, $payload, $meta);
    }

    /** Boolean check of a business rule. */
    public static function check(string $ruleCode, array $context): bool
    {
        return RuleRegistry::instance()->check($ruleCode, $context);
    }

    /** Enforce a business rule (throws RuleViolation on failure). */
    public static function enforce(string $ruleCode, array $context): void
    {
        RuleRegistry::instance()->enforce($ruleCode, $context);
    }

    /** The timeline (newest-first events) for any subject. */
    public static function timeline(string $subjectType, int $subjectId, int $tenantId, int $limit = 100): array
    {
        return TimelineProjection::for($subjectType, $subjectId, $tenantId, $limit)->build();
    }
}
