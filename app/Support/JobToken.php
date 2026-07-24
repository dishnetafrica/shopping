<?php declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Per-person, login-free "my jobs" link token (the DishNet scheduling-link model) — short + DB-backed.
 *
 * Each user carries a short random token (like DishNet's `3ik6igzoQD`) stamped with the Juba calendar day
 * it was minted. It authorizes ONE thing — viewing that user's own open task list — never account access or
 * another user's tasks. It regenerates DAILY: the first reminder of a new day rotates the token, so
 * yesterday's link dies. Verification accepts today or yesterday (a short grace so an evening link still
 * opens the next morning until the 9am reminder rotates it).
 *
 * Columns on `users`: job_token (short unique code) + job_token_day (Ymd it was minted).
 */
final class JobToken
{
    private const TZ = 'Africa/Juba';

    /** Return this user's short token for today, minting/rotating it if the stored one isn't today's. */
    public static function forUser(User $u): string
    {
        $today = self::today();
        if (! empty($u->job_token) && (string) $u->job_token_day === $today) {
            return (string) $u->job_token;
        }

        // A fresh short code, unique across users.
        do {
            $tok = Str::random(10);
        } while (User::withoutGlobalScopes()->where('job_token', $tok)->exists());

        User::withoutGlobalScopes()->where('id', $u->id)->update([
            'job_token'     => $tok,
            'job_token_day' => $today,
        ]);
        $u->job_token = $tok;
        $u->job_token_day = $today;

        return $tok;
    }

    /** Resolve a token to [tenantId, userId], or null if unknown/expired. Today or yesterday only. */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 40 || ! ctype_alnum($token)) {
            return null;
        }
        $u = User::withoutGlobalScopes()
            ->where('job_token', $token)
            ->whereIn('job_token_day', [self::today(), self::yesterday()])
            ->first();

        return $u ? [(int) $u->tenant_id, (int) $u->id] : null;
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
