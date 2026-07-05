<?php

namespace App\Services\Marketplace\Support;

/**
 * Load-bearing guardrail: the bot may only ever send an image URL whose host
 * matches a known marketplace CDN. A bad scrape must never let the bot forward
 * an arbitrary URL to a customer.
 *
 * Patterns support a leading '*.' wildcard (matches any subdomain), e.g.
 * '*.jumia.is' allows 'ug.jumia.is' and 'sc.jumia.is' but not 'jumia.is.evil.com'.
 */
final class ImageHostAllowList
{
    /**
     * @param string        $url      candidate image URL
     * @param array<string> $patterns allowed host patterns
     */
    public static function allowed(string $url, array $patterns): bool
    {
        $url = trim($url);
        if ($url === '' || $patterns === []) {
            return false;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        foreach ($patterns as $pat) {
            $pat = mb_strtolower(trim($pat));
            if ($pat === '') continue;

            if (str_starts_with($pat, '*.')) {
                $suffix = substr($pat, 1);                 // '.jumia.is'
                $bare   = substr($pat, 2);                 // 'jumia.is'
                if ($host === $bare || str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($host === $pat) {
                return true;
            }
        }
        return false;
    }
}
