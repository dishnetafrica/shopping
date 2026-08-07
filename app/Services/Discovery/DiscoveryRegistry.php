<?php declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Discovery\Contracts\BusinessDiscoveryProvider;
use App\Services\Discovery\Providers\CsvBusinessProvider;

/**
 * v4.59 — the providers, in the order the shop trusts them.
 *
 * Adding a source is registering a class here and nothing else: no branch anywhere else in the
 * system knows how many providers exist or what they are called. That is the entire point of the
 * pattern and the reason Google Places, a directory, a registry and a website reader can each be one
 * small class that cannot break the others.
 *
 * ORDER MATTERS, quietly. When two providers assert the same field with the same confidence, the
 * first one registered wins — so a shop's own spreadsheet beating an API is a preference expressed
 * by position, and CSV is deliberately first.
 */
final class DiscoveryRegistry
{
    /** @var array<string,BusinessDiscoveryProvider> */
    private array $providers = [];

    public function __construct()
    {
        // The shop's own data first. Everything else is somebody else's guess about the shop's market.
        $this->register(new CsvBusinessProvider());
    }

    public function register(BusinessDiscoveryProvider $p): void
    {
        $k = trim($p->key());
        if ($k === '' || isset($this->providers[$k])) {
            return;                          // a duplicate key would silently shadow the first
        }
        $this->providers[$k] = $p;
    }

    /** @return array<string,BusinessDiscoveryProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * The ones that can actually run right now.
     *
     * A provider with no API key answers `isEnabled()` false and stays out of the way, rather than
     * failing on every run and filling the log with the same error.
     *
     * @return array<string,BusinessDiscoveryProvider>
     */
    public function enabled(): array
    {
        $out = [];
        foreach ($this->providers as $k => $p) {
            try {
                if ($p->isEnabled()) {
                    $out[$k] = $p;
                }
            } catch (\Throwable $e) {
                // A provider that cannot even answer whether it is configured is not configured.
            }
        }

        return $out;
    }

    public function get(string $key): ?BusinessDiscoveryProvider
    {
        return $this->providers[trim($key)] ?? null;
    }

    /** For a picker: key => label, only what can run. */
    public function options(): array
    {
        $o = [];
        foreach ($this->enabled() as $k => $p) {
            $o[$k] = $p->label();
        }

        return $o;
    }
}
