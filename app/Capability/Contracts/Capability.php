<?php

namespace App\Capability\Contracts;

use App\Capability\Dto\WorkspacePayload;

interface Capability
{
    public function key(): string;

    public function permission(): string;

    public function project(CapabilityContext $ctx): WorkspacePayload;

    /** @return array<string,mixed> */
    public function command(CapabilityContext $ctx, string $action, array $input): array;
}
