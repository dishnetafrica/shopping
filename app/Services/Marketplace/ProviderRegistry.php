<?php

namespace App\Services\Marketplace;

use App\Services\Marketplace\Contracts\MarketplaceProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves marketplace providers by key from config('marketplace.providers').
 * Each entry maps a key ('jumia', 'amazon', ...) to a class the container knows
 * how to build. MarketplaceSearch asks the registry for a provider by key and
 * never news up a concrete class itself — so a new marketplace is a config line
 * plus a class, with zero edits to the search/bot layers.
 */
final class ProviderRegistry
{
    /** @param array<string,string> $map key => provider class */
    public function __construct(
        private Container $container,
        private array $map,
    ) {}

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    public function resolve(string $key): ?MarketplaceProvider
    {
        $class = $this->map[$key] ?? null;
        if ($class === null || ! class_exists($class)) {
            return null;
        }
        $instance = $this->container->make($class);
        return $instance instanceof MarketplaceProvider ? $instance : null;
    }

    /** @return array<int,string> configured provider keys */
    public function keys(): array
    {
        return array_keys($this->map);
    }
}
