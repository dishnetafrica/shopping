<?php

namespace App\Services\Marketplace\Dto;

/**
 * The result of a special-order lookup, as a decision the bot acts on:
 *
 *   QUOTE   -> strong match(es); quote the price(s) straight away.
 *   CONFIRM -> a plausible but uncertain match; ask "Did you mean X?" first.
 *   NONE    -> nothing worth quoting; behave exactly like today (shop confirms).
 *
 * This keeps confidence logic inside the marketplace layer. BotBrain just
 * branches on the decision — it never re-implements scoring.
 */
final class MarketplaceOutcome
{
    public const QUOTE   = 'quote';
    public const CONFIRM = 'confirm';
    public const NONE    = 'none';

    /**
     * @param array<int,MarketplaceQuote> $quotes
     */
    private function __construct(
        public readonly string $decision,
        private readonly array $quotes = [],
        private readonly ?MarketplaceQuote $candidate = null,
    ) {}

    /** @return array<int,MarketplaceQuote> */
    public function quotes(): array { return $this->quotes; }

    public function candidate(): ?MarketplaceQuote { return $this->candidate; }

    /** @param array<int,MarketplaceQuote> $quotes */
    public static function quote(array $quotes): self
    {
        return new self(self::QUOTE, array_values($quotes), null);
    }

    public static function confirm(MarketplaceQuote $candidate): self
    {
        return new self(self::CONFIRM, [], $candidate);
    }

    public static function none(): self
    {
        return new self(self::NONE, [], null);
    }

    public function isQuote(): bool   { return $this->decision === self::QUOTE; }
    public function isConfirm(): bool { return $this->decision === self::CONFIRM; }
    public function isNone(): bool    { return $this->decision === self::NONE; }
}
