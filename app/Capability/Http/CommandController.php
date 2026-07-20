<?php

namespace App\Capability\Http;

use App\Capability\Command\CommandBus;
use App\Capability\Command\HumanCommand;
use App\Capability\Contracts\CapabilityContext;
use App\Capability\Registry\CapabilityRegistry;
use Illuminate\Http\Request;

/** Executes a capability command. ARCHITECTURE RULE: no App\Models or App\Services here. */
final class CommandController
{
    public function dispatch(Request $request, CapabilityRegistry $registry, CommandBus $bus, string $type, string $id, string $action)
    {
        if (! $registry->has($type)) {
            return response()->json(['ok' => false, 'error' => 'unknown_capability'], 404);
        }
        $ctx = CapabilityContext::fromUser($request->user());
        $ctx->displayOverride = $request->query('cur');
        $permission = $registry->manifest($type)->permission;
        $command = new HumanCommand($action, $permission);
        // Route ids are authoritative — the left side of `+` wins on key collision,
        // so a body-supplied `id`/`customer_id` can never redirect the command.
        $input = ['customer_id' => $id, 'id' => $id] + $request->all();

        return response()->json($bus->dispatch($type, $command, $ctx, $input));
    }
}
