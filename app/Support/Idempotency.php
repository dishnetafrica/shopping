<?php

namespace App\Support;

/**
 * Deterministic keys for the production-safety layer. Pure functions — no I/O —
 * so they are unit-testable and identical across workers/retries.
 */
final class Idempotency
{
    /** Per-conversation serialization lock key (WithoutOverlapping / Cache::lock). */
    public static function conversationLock(int $tenantId, string $instance, string $from): string
    {
        return 'convlock:' . $tenantId . ':' . $instance . ':' . $from;
    }

    /** Checkout serialization lock key. */
    public static function checkoutLock(int $tenantId, int $conversationId): string
    {
        return 'checkout:' . $tenantId . ':' . $conversationId;
    }

    /**
     * Order idempotency key. Stable across retries of the SAME checkout (same
     * token) but different for a NEW checkout — so a customer can legitimately
     * place two identical orders, while retries collapse to one.
     */
    public static function orderKey(int $tenantId, int $conversationId, string $checkoutToken): string
    {
        return hash('sha256', $tenantId . '|' . $conversationId . '|' . $checkoutToken);
    }

    /** Normalise a recipient phone for campaign de-dup (digits only). */
    public static function recipient(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: $phone;
    }
}
