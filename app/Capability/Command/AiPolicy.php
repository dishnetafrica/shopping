<?php

namespace App\Capability\Command;

use App\Models\Tenant;

/** Per-tenant autonomy dial. Everything is suggest-only until a command is explicitly promoted. */
final class AiPolicy
{
    public function __construct(private Tenant $tenant, private string $commandKey) {}

    public static function for(Tenant $tenant, string $commandKey): self
    {
        return new self($tenant, $commandKey);
    }

    public function cap(AiCommand $c): string
    {
        $allowed = (array) $this->tenant->setting('ai_autonomy', []);
        $level = $allowed[$this->commandKey] ?? 'suggest';

        return in_array($level, ['suggest', 'draft', 'auto'], true) ? $level : 'suggest';
    }
}
