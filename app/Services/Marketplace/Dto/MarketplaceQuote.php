<?php

namespace App\Services\Marketplace\Dto;

/**
 * A fully-priced, customer-facing special-order option. This is the ONLY shape
 * BotBrain ever sees — it has no idea a marketplace or a provider exists.
 *
 * Rich by design (point 4 of the brief): even fields the bot doesn't use today
 * (url, provider, cached, breakdown) travel with the quote so future features
 * (audit, supplier links, landed-cost receipts) need no re-plumbing.
 */
final class MarketplaceQuote
{
    public function __construct(
        public readonly string $name,
        public readonly int $sellPrice,        // final price in $currency after the pricing pipeline
        public readonly string $currency,      // 'UGX' | 'USD' | ...
        public readonly string $image,         // '' if the source image failed the host allow-list
        public readonly string $url,           // source URL — INTERNAL ONLY, never shown to a customer
        public readonly string $provider,      // provider key, e.g. 'jumia' — INTERNAL ONLY
        public readonly bool $cached,
        public readonly float $confidence,     // 0..1 name-vs-query match strength
        public readonly array $breakdown = [], // landed-cost trail: base -> shipping -> duty -> markup -> fx
    ) {}

    /** Plain array for storing in conversation state / JSON (customer-safe fields only used by the bot). */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'sell_price' => $this->sellPrice,
            'currency'   => $this->currency,
            'image_url'  => $this->image,
            'url'        => $this->url,
            'provider'   => $this->provider,
            'cached'     => $this->cached,
            'confidence' => $this->confidence,
        ];
    }

    /** Rebuild a quote from stored state (used when a customer confirms "yes, that one"). */
    public static function fromArray(array $a): self
    {
        return new self(
            name: (string) ($a['name'] ?? ''),
            sellPrice: (int) ($a['sell_price'] ?? 0),
            currency: (string) ($a['currency'] ?? 'UGX'),
            image: (string) ($a['image_url'] ?? ''),
            url: (string) ($a['url'] ?? ''),
            provider: (string) ($a['provider'] ?? ''),
            cached: (bool) ($a['cached'] ?? false),
            confidence: (float) ($a['confidence'] ?? 0.0),
            breakdown: (array) ($a['breakdown'] ?? []),
        );
    }
}
