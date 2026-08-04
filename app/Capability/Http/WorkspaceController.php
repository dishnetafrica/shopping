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

        // v4.21 — feature flags for bespoke menu entries that are NOT capabilities: owner tools that
        // live behind a tenant setting (TenderBot). Same discipline as counts above — a flag that
        // throws must never take the sidebar down with it.
        $flags = [];
        try {
            $flags['tenders'] = method_exists($ctx->user, 'isOwnerLike') && $ctx->user->isOwnerLike()
                && (bool) (method_exists($ctx->tenant, 'setting') ? $ctx->tenant->setting('tender_enabled', false) : false);
            // v4.30 — what kind of business this is, so the shell can stop offering a grocery
            // shop the Solar Advisor. Sent as a plain string; the shell treats an ABSENT
            // vertical as "show everything", so an older cached shell.js is unaffected and a
            // failure here can only ever show too much, never too little.
            $flags['vertical'] = method_exists($ctx->tenant, 'setting')
                ? \App\Support\SettingsVisibility::verticalOf($ctx->tenant) : '';
        } catch (\Throwable $e) {
            $flags = [];
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
            'flags' => $flags,
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
