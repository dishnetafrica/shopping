<?php

namespace App\Capability\Command;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Registry\CapabilityRegistry;

/**
 * Routes a Command to a capability. Human path is the unchanged v1.0 command(). AI path obeys the
 * autonomy ladder (suggest -> draft -> auto) and never bypasses the frozen engine.
 */
final class CommandBus
{
    public function __construct(private CapabilityRegistry $registry) {}

    /** @return array<string,mixed> */
    public function dispatch(string $capabilityKey, Command $command, CapabilityContext $ctx, array $input): array
    {
        if (! $ctx->can($command->permission())) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $capability = $this->registry->get($capabilityKey);

        if ($command->actor() === 'human') {
            return $capability->command($ctx, $command->key(), $input);
        }

        $autonomy = $command instanceof AiCommand
            ? AiPolicy::for($ctx->tenant, $command->key())->cap($command)
            : 'suggest';

        return match ($autonomy) {
            'auto' => $command->reversible()
                ? $capability->command($ctx, $command->key(), $input)
                : ['ok' => true, 'queued' => true, 'autonomy' => 'suggest', 'reason' => 'irreversible requires human approval'],
            'draft' => $capability->command($ctx, $command->key() . '_draft', $input),
            default => ['ok' => true, 'queued' => true, 'autonomy' => 'suggest'],
        };
    }
}
