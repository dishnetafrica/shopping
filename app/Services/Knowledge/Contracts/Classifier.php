<?php
namespace App\Services\Knowledge\Contracts;

/**
 * Determines the intent of an owner message so the engine can route it via the registry.
 * Domain-free: returns an intent string (well-known or capability-defined). Phase-1 ships a
 * deterministic implementation; Phase-3 swaps an AI one in — engine code does not change.
 */
interface Classifier
{
    /** @return string an intent string (see Intent::*, or any capability-registered intent) */
    public function classify(string $text, array $profile = []): string;
}
