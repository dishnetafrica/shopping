<?php

namespace App\Services\Marketplace\Contracts;

use App\Services\Marketplace\Dto\MarketplaceResult;

/**
 * A source of live product prices (Jumia, Amazon, Noon, Alibaba, a supplier
 * API, a manual supplier database ...). This is the ONLY marketplace surface
 * the rest of the app knows about: BotBrain talks to MarketplaceSearch, and
 * MarketplaceSearch talks to whichever provider is configured for the tenant.
 *
 * Adding a marketplace tomorrow = one new class implementing this interface,
 * registered in config/marketplace.php. Nothing else changes.
 *
 * Implementations MUST NOT throw from search()/healthy() — a dead or slow
 * backend returns [] / false so the bot degrades gracefully to its normal
 * "the shop will confirm" flow.
 */
interface MarketplaceProvider
{
    /** Stable key used in config + tenant settings, e.g. 'jumia'. */
    public function key(): string;

    /** Is this provider usable at all (credentials / base URL present)? */
    public function isEnabled(): bool;

    /** Cheap liveness probe. Never throws; false when unreachable. */
    public function healthy(): bool;

    /**
     * Look up a query on the marketplace. Never throws.
     *
     * @return array<int,MarketplaceResult> raw results (base prices, unpriced),
     *                                       newest/most-relevant first, or [].
     */
    public function search(string $query): array;
}
