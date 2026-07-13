<?php

namespace App\Capability\Contracts;

interface ProcessCapability extends Capability
{
    /**
     * Aggregate state a queue or briefing needs (counts, exposure, blockers) — never a full workspace.
     *
     * @return array<string,mixed>
     */
    public function summary(CapabilityContext $ctx, array $scope = []): array;
}
