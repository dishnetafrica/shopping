<?php

namespace App\Services\Travel\Passport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Where a travel customer's photo or PDF waits between the webhook and the message job, when the
 * provider sent its bytes inline. The bytes are kept OFF the queue (a queued job, and a failed job,
 * are stored as plain text): they are encrypted with the application key and held in the cache for
 * at most a quarter of an hour, then taken (read and deleted) by the job that reads the document.
 */
final class PassportInbox
{
    public const TTL_SECONDS = 900;

    /** Larger than any phone photo or short PDF; anything bigger is not kept at all. */
    public const MAX_BASE64 = 16_000_000;

    public static function put(int $tenantId, string $messageId, string $base64): bool
    {
        if ($messageId === '' || $base64 === '' || strlen($base64) > self::MAX_BASE64) {
            return false;
        }
        try {
            return Cache::put(self::key($tenantId, $messageId), Crypt::encryptString($base64), self::TTL_SECONDS);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The bytes (base64), once: they are deleted as they are read. */
    public static function take(int $tenantId, string $messageId): ?string
    {
        if ($messageId === '') {
            return null;
        }
        try {
            $sealed = Cache::pull(self::key($tenantId, $messageId));

            return is_string($sealed) && $sealed !== '' ? Crypt::decryptString($sealed) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function key(int $tenantId, string $messageId): string
    {
        return 'travelmedia:' . $tenantId . ':' . sha1($messageId);
    }
}
