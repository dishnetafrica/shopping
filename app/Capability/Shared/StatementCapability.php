<?php

namespace App\Capability\Shared;

use App\Capability\Contracts\Capability;
use App\Capability\Contracts\CapabilityContext;
use App\Capability\Dto\Financial;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Invoicing\StatementService;

/** Thin adapter over the FROZEN StatementService. Recomputes nothing. */
final class StatementCapability implements Capability
{
    public function __construct(private StatementService $statements) {}

    public function key(): string { return 'statement'; }

    public function permission(): string { return 'menu:finance'; }

    /** @return Financial[] */
    public function financialsFor(CapabilityContext $ctx, string $phone): array
    {
        $s = $this->statements->for($ctx->tenant, $phone);
        $currency = array_key_first($s['outstanding'] ?? []) ?: 'USD';
        $balance = (float) array_sum($s['outstanding'] ?? []);

        return [
            new Financial('balance', 'Balance', round($balance, 2), $currency, $balance > 0 ? 'warn' : 'ok'),
            new Financial('billed', 'Billed', round((float) array_sum($s['billed'] ?? []), 2), $currency),
            new Financial('paid', 'Paid', round((float) array_sum($s['paid'] ?? []), 2), $currency, 'ok'),
        ];
    }

    public function raw(CapabilityContext $ctx, string $phone): array
    {
        return $this->statements->for($ctx->tenant, $phone);
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->meta(['capability' => 'statement']);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'no_command'];
    }
}
