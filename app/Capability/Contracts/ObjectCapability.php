<?php

namespace App\Capability\Contracts;

use App\Capability\Dto\WorkspacePayload;

interface ObjectCapability extends Capability
{
    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload;
}
