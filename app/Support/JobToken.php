<?php declare(strict_types=1);

namespace App\Support;

/**
 * Per-person, login-free "my jobs" link token (the DishNet scheduling-link model).
 *
 * Stateless + daily: the token encodes {tenant, user, Juba-date} signed with the app key. It authorizes ONE
 * thing — viewing that user's own open task list — never account access or another user's tasks. It changes
 * every day (the date is part of the signed payload), so a leaked link goes stale within ~a day. A 1-day
 * grace window is honoured so a link from yesterday's 2 PM reminder still opens the next morning.
 *
 * No DB, no migration: verification just recomputes the HMAC. Rate-limited at the route to defeat guessing.
 */
final class JobToken
{
    private const TZ = 'Africa/Juba';

    /** Mint today's token for a (tenant, user). */
    public static function mint(int $tenantId, int $userId, ?string $ymd = null): string
    {
        $ymd = $ymd ?: self::today();
        $payload = $tenantId . '.' . $userId . '.' . $ymd;
        $raw = $payload . '.' . self::sig($payload);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Verify a token. Returns [tenantId, userId] if valid (signature ok AND date is today or yesterday),
     * or null otherwise.
     */
    public static function verify(string $token): ?array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 4) {
            return null;
        }
        [$tid, $uid, $ymd, $sig] = $parts;
        if (! ctype_digit($tid) || ! ctype_digit($uid) || ! ctype_digit($ymd)) {
            return null;
        }
        if (! hash_equals(self::sig($tid . '.' . $uid . '.' . $ymd), (string) $sig)) {
            return null;
        }
        if (! in_array($ymd, [self::today(), self::yesterday()], true)) {
            return null; // expired — a fresh reminder carries a fresh token
        }
        return [(int) $tid, (int) $uid];
    }

    private static function sig(string $payload): string
    {
        $key = (string) config('app.key');
        return substr(hash_hmac('sha256', $payload, $key), 0, 24);
    }

    private static function today(): string
    {
        return now()->setTimezone(self::TZ)->format('Ymd');
    }

    private static function yesterday(): string
    {
        return now()->setTimezone(self::TZ)->subDay()->format('Ymd');
    }
}
