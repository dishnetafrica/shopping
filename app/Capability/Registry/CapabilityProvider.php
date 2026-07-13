<?php

namespace App\Capability\Registry;

/** A plugin implements this to register capabilities without editing the core. */
interface CapabilityProvider
{
    public function register(CapabilityRegistry $registry): void;
}
