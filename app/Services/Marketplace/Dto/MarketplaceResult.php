<?php

namespace App\Services\Marketplace\Dto;

/**
 * A raw product hit from a provider, BEFORE any tenant pricing is applied.
 * Price is carried in the provider's own base currency (Jumia = UGX). The
 * pricing pipeline turns this into a customer-facing MarketplaceQuote.
 *
 * Keep this dumb: providers only report what they found. Confidence scoring,
 * margins, FX and host allow-listing all happen upstream in MarketplaceSearch,
 * so every provider benefits from the same logic (DRY).
 */
final class MarketplaceResult
{
    public function __construct(
        public readonly string $name,
        public readonly int $baseAmount,          // price in $baseCurrency (e.g. 302000 UGX)
        public readonly string $baseCurrency,     // 'UGX'
        public readonly string $image = '',       // product image URL (validated later)
        public readonly string $url = '',          // product URL on the source (internal only)
        public readonly string $provider = '',    // provider key, e.g. 'jumia'
        public readonly bool $cached = false,      // did the provider serve this from its cache?
        public readonly array $raw = [],           // original payload, for debugging / future fields
    ) {}

    /** Defensive factory from a loose associative array (what an HTTP API returns). */
    public static function fromArray(array $r, string $provider, string $baseCurrency = 'UGX'): ?self
    {
        $name = trim((string) ($r['name'] ?? ''));
        $amount = (int) ($r['ugx'] ?? $r['amount'] ?? $r['price'] ?? 0);
        if ($name === '' || $amount <= 0) {
            return null;
        }

        return new self(
            name: mb_substr($name, 0, 120),
            baseAmount: $amount,
            baseCurrency: $baseCurrency,
            image: trim((string) ($r['image'] ?? '')),
            url: trim((string) ($r['url'] ?? '')),
            provider: $provider,
            cached: (bool) ($r['cached'] ?? false),
            raw: $r,
        );
    }
}
