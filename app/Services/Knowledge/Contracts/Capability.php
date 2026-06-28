<?php
namespace App\Services\Knowledge\Contracts;

/**
 * A business capability registered by an application module.
 * The engine never knows domain specifics — it only asks the registry which capability
 * owns an intent, then delegates extraction and projection.
 */
interface Capability
{
    /** Stable machine name, e.g. 'daily_menu', 'core'. */
    public function name(): string;

    /** Intents this capability claims (see App\Services\Knowledge\Intent). @return string[] */
    public function intents(): array;

    public function extractor(): Extractor;

    public function projector(): Projector;
}
