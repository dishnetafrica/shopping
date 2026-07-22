<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use Illuminate\Http\Request;

/**
 * BOS Slice 5 — Offline field notes. POST /papi/customer-note records ONE customer event (visit / note /
 * follow-up) captured in the field. The client supplies an idempotency_key + the time it was really taken,
 * so a note queued offline that replays on reconnect is deduped by the event spine (never a second fact)
 * and dated to when it happened, not when it synced. Additive + guarded; it never mutates a business
 * object and never throws into the caller (Emit is safe).
 */
class BosNoteController extends Controller
{
    private const KINDS = [
        'visit'    => 'CustomerVisited',
        'followup' => 'FollowupScheduled',
        'note'     => 'CustomerNote',
    ];

    public function store(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $c = CustomerProfile::where('tenant_id', $t->id)->find((int) $request->input('customer_id'));
        if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $text = trim((string) $request->input('text', ''));
        if ($text === '') return response()->json(['ok' => false, 'error' => 'empty'], 422);
        if (mb_strlen($text) > 1000) $text = mb_substr($text, 0, 1000);

        $name = self::KINDS[(string) $request->input('kind', 'note')] ?? 'CustomerNote';
        $key  = trim((string) $request->input('idempotency_key', '')) ?: null;

        // The true field time (a queued note may sync minutes/hours later). Fall back to now.
        $occurredAt = null;
        $oc = trim((string) $request->input('occurred_at', ''));
        if ($oc !== '') { try { $occurredAt = \Illuminate\Support\Carbon::parse($oc); } catch (\Throwable $e) { $occurredAt = null; } }

        $payload = ['note' => $text];
        $due = trim((string) $request->input('due', ''));
        if ($name === 'FollowupScheduled' && $due !== '') $payload['due'] = $due;

        $meta = [
            'actor'        => ['type' => 'user', 'id' => (int) ($request->user()->id ?? 0), 'name' => (string) ($request->user()->name ?? '')],
            'connectivity' => (string) ($request->input('connectivity', '') ?: 'online'),
        ];
        if ($key) $meta['idempotency_key'] = $key;
        if ($occurredAt) $meta['occurred_at'] = $occurredAt;

        $ev = \App\BOS\Emit::customerEvent((int) $t->id, $name, (string) $c->phone, $payload, $meta);
        if (! $ev) return response()->json(['ok' => false, 'error' => 'spine_off'], 200);

        return response()->json([
            'ok'    => true,
            'event' => [
                'event' => $name,
                'data'  => $payload,
                'at'    => optional($ev->occurred_at)->toIso8601String(),
                'actor' => (string) ($request->user()->name ?? ''),
            ],
        ]);
    }
}
