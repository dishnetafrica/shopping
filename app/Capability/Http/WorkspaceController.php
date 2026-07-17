<?php

namespace App\Capability\Http;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Registry\CapabilityRegistry;
use Illuminate\Http\Request;

/**
 * Thin transport. Resolves a capability by key, checks permission, returns the envelope.
 * ARCHITECTURE RULE: this file must not reference App\Models or App\Services.
 */
final class WorkspaceController
{
    /** Catalog: the capabilities this user may open — the shell builds its nav from this. */
    public function index(Request $request, CapabilityRegistry $registry)
    {
        $ctx = CapabilityContext::fromUser($request->user());
        $ctx->displayOverride = $request->query('cur');
        $caps = [];
        foreach ($registry->all() as $m) {
            if (! $ctx->can($m->permission)) {
                continue;
            }
            $caps[] = ['key' => $m->key, 'type' => $m->type, 'owner' => $m->owner, 'permission' => $m->permission, 'api' => $m->api];
        }

        // Sidebar badge counts, resolved via the registry (no model access in the HTTP layer).
        $counts = [];
        try {
            if ($registry->has('_nav')) {
                $nav = $registry->get('_nav');
                if (method_exists($nav, 'counts')) {
                    $counts = $nav->counts($ctx);
                }
            }
        } catch (\Throwable $e) {
            // Badges are cosmetic. The catalog (and therefore the ENTIRE sidebar) must
            // never fail because a count did — incident 2026-07-18.
            $counts = [];
        }

        // Currency & reporting: the shell renders a global report-currency selector from this.
        $override = strtolower(trim((string) $request->query('cur')));
        $currency = [
            'base' => $ctx->baseCurrency(),
            'mode' => $ctx->reportMode(),
            'display' => $ctx->displayCurrency(),           // null => original
            'override' => $override !== '' ? $override : null,
            'options' => ['base', 'original', 'USD', 'UGX', 'SSP'],
        ];

        return response()->json([
            'ok' => true,
            'user' => ['name' => (string) ($request->user()->name ?? 'User'), 'role' => $ctx->role, 'org' => (string) ($ctx->tenant->name ?? '')],
            'counts' => $counts,
            'currency' => $currency,
            'capabilities' => $caps,
        ]);
    }

    public function show(Request $request, CapabilityRegistry $registry, string $type, ?string $id = null)
    {
        if (! $registry->has($type)) {
            return response()->json(['ok' => false, 'error' => 'unknown_capability'], 404);
        }
        $cap = $registry->get($type);
        $ctx = CapabilityContext::fromUser($request->user());
        $ctx->displayOverride = $request->query('cur');

        if (! $ctx->can($cap->permission())) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $payload = ($id !== null && $cap instanceof ObjectCapability)
            ? $cap->forId($ctx, $id)
            : $cap->project($ctx);

        return response()->json($payload->toArray());
    }
}
