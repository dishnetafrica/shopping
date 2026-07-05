<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\Config\MarketplaceConfig;
use App\Services\Marketplace\Contracts\MarketplaceProvider;
use App\Services\Marketplace\Dto\MarketplaceResult;

/**
 * Shared fixtures for the marketplace unit tests. Building MarketplaceConfig
 * directly (not via ::fromTenant) keeps these tests free of Eloquent + config()
 * so they run as pure unit tests, exactly like ShoppingEngineTest.
 */
trait MakesMarketplaceFixtures
{
    protected function cfg(array $o = []): MarketplaceConfig
    {
        return new MarketplaceConfig(
            enabled: $o['enabled'] ?? true,
            providerKey: $o['providerKey'] ?? 'jumia',
            margin: $o['margin'] ?? 0.15,
            ugxRate: $o['ugxRate'] ?? 0,
            displayCurrency: $o['displayCurrency'] ?? 'UGX',
            terms: $o['terms'] ?? 'Special order terms.',
            shippingFlat: $o['shippingFlat'] ?? 0.0,
            shippingPct: $o['shippingPct'] ?? 0.0,
            importPct: $o['importPct'] ?? 0.0,
            dutyPct: $o['dutyPct'] ?? 0.0,
            roundStep: $o['roundStep'] ?? 1,
            confidenceHigh: $o['confidenceHigh'] ?? 0.60,
            confidenceMedium: $o['confidenceMedium'] ?? 0.30,
            maxOptions: $o['maxOptions'] ?? 3,
            allowedImageHosts: $o['allowedImageHosts'] ?? ['*.jumia.is'],
        );
    }

    protected function result(string $name, int $ugx, string $img = 'https://ug.jumia.is/x.jpg'): MarketplaceResult
    {
        return new MarketplaceResult($name, $ugx, 'UGX', $img, 'https://www.jumia.ug/p', 'jumia', false);
    }
}

/** A provider that returns canned results — no HTTP, no scraper. */
class FakeProvider implements MarketplaceProvider
{
    /** @param array<int,MarketplaceResult> $results */
    public function __construct(private array $results, private bool $enabled = true) {}

    public function key(): string { return 'jumia'; }
    public function isEnabled(): bool { return $this->enabled; }
    public function healthy(): bool { return true; }
    public function search(string $query): array { return $this->results; }
}
