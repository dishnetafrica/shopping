<?php

namespace App\Services\Pricing;

/**
 * v4.9.1 — Dealer Programme 4b: session-less dealer identity for the storefront (spec §4/§8).
 *
 * Possession of the WhatsApp number IS the identity (the same trust base as the entire bot):
 * a 4-digit code is sent to the phone ON WhatsApp by the shop's own connected number, and a
 * successful verify mints a signed token the browser holds for 30 days.
 *
 * Token format: base64url("tenantId|digitsPhone|expiresAt") . "." . HMAC-SHA256(payload, app key).
 * Pure functions (key injectable) so the harness can prove mint/verify/tamper/expiry without
 * booting Laravel. No tables anywhere — codes live in cache for 5 minutes.
 */
final class DealerAuth
{
    public const TTL_DAYS = 30;

    public static function mint(int $tenantId, string $phone, int $expiresAt, ?string $key = null): string
    {
        $payload = $tenantId . '|' . preg_replace('/[^0-9]/', '', $phone) . '|' . $expiresAt;
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $payload, self::key($key));
    }

    /** @return array{tenant:int, phone:string, exp:int}|null null on tamper, malformed or expired. */
    public static function check(string $token, ?int $now = null, ?string $key = null): ?array
    {
        $now = $now ?? time();
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) return null;
        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($payload === false || $payload === '') return null;
        if (! hash_equals(hash_hmac('sha256', $payload, self::key($key)), $parts[1])) return null;
        $bits = explode('|', $payload);
        if (count($bits) !== 3) return null;
        [$tenant, $phone, $exp] = $bits;
        if (! ctype_digit($tenant) || ! ctype_digit($exp) || $phone === '') return null;
        if ((int) $exp < $now) return null;
        return ['tenant' => (int) $tenant, 'phone' => $phone, 'exp' => (int) $exp];
    }

    private static function key(?string $key): string
    {
        if ($key !== null && $key !== '') return $key;
        $k = (string) config('app.key');
        if (str_starts_with($k, 'base64:')) $k = (string) base64_decode(substr($k, 7));
        return $k !== '' ? $k : 'cloudbss-dealer';
    }
}
