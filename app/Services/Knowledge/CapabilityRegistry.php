<?php
namespace App\Services\Knowledge;

use App\Services\Knowledge\Contracts\Capability;

/**
 * Apps register Capabilities here; the engine resolves "which capability owns this intent?".
 * Pure in-memory map — keeps OKE completely domain-free.
 */
class CapabilityRegistry
{
    /** @var array<string,Capability> name => capability */
    private array $byName = [];
    /** @var array<string,string> intent => capability name (first registrant wins) */
    private array $byIntent = [];

    public function register(Capability $cap): void
    {
        $this->byName[$cap->name()] = $cap;
        foreach ($cap->intents() as $intent) {
            $this->byIntent[$intent] ??= $cap->name();
        }
    }

    public function forIntent(string $intent): ?Capability
    {
        $name = $this->byIntent[$intent] ?? null;
        return $name ? ($this->byName[$name] ?? null) : null;
    }

    public function get(string $name): ?Capability { return $this->byName[$name] ?? null; }

    /** @return Capability[] */
    public function all(): array { return array_values($this->byName); }

    /** @return string[] */
    public function intents(): array { return array_keys($this->byIntent); }
}
