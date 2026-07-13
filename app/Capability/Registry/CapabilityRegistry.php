<?php

namespace App\Capability\Registry;

use App\Capability\Contracts\Capability;

/**
 * The platform seam. Capabilities self-describe and register here; the Workspace layer resolves
 * them by key and never names a concrete class. Plugins register + decorate without touching core.
 */
final class CapabilityRegistry
{
    /** @var array<string,Capability> */
    private array $caps = [];

    /** @var array<string,CapabilityManifest> */
    private array $manifests = [];

    /** @var array<string,callable[]> */
    private array $decorators = [];

    public function register(Capability $cap, CapabilityManifest $manifest): void
    {
        $this->caps[$manifest->key] = $cap;
        $this->manifests[$manifest->key] = $manifest;
    }

    /** Additive-only: a decorator may add to a payload, never remove engine-owned values. */
    public function extend(string $key, callable $decorator): void
    {
        $this->decorators[$key][] = $decorator;
    }

    public function has(string $key): bool
    {
        return isset($this->caps[$key]);
    }

    public function get(string $key): Capability
    {
        if (! isset($this->caps[$key])) {
            throw new \InvalidArgumentException("Unknown capability: {$key}");
        }
        $cap = $this->caps[$key];
        foreach ($this->decorators[$key] ?? [] as $decorator) {
            $cap = $decorator($cap);
        }

        return $cap;
    }

    public function manifest(string $key): CapabilityManifest
    {
        return $this->manifests[$key];
    }

    /** @return CapabilityManifest[] */
    public function all(): array
    {
        return array_values($this->manifests);
    }

    public function byType(string $type): array
    {
        return array_values(array_filter($this->manifests, fn ($m) => $m->type === $type));
    }

    public function byOwner(string $owner): array
    {
        return array_values(array_filter($this->manifests, fn ($m) => $m->owner === $owner));
    }

    public function byModule(string $module): array
    {
        return array_values(array_filter($this->manifests, fn ($m) => $m->module === $module));
    }

    public function assertDependencies(): void
    {
        foreach ($this->manifests as $m) {
            foreach ($m->dependsOn as $dep) {
                if (! isset($this->caps[$dep])) {
                    throw new \RuntimeException("Capability {$m->key} depends on missing capability {$dep}");
                }
            }
        }
    }
}
