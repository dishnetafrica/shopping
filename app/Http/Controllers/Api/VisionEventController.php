<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the CloudBSS Vision Gateway (M1 frozen event schema) into vision_events.
 *
 * Auth: shared secret header X-Vision-Token. Two accepted forms, tried in order:
 *   1. A per-box webhook_token minted at claim time (vision_boxes, status=claimed) — the fleet
 *      path. Tenant + fallback store label come from the box row; box heartbeat is refreshed.
 *   2. The legacy global token from config('vision.token') + config('vision.tenant_id') — the
 *      hand-configured single-box pilot path. (config/vision.php, survives config:cache.)
 * Thumbnails are dropped before storage (size); everything else is kept verbatim in payload.
 *
 * v1 scope: STORAGE ONLY. Joining shelf events to stock movements as passive P-class evidence is
 * M3 (needs zone→SKU mapping); this endpoint exists now so no event from the pilot store is lost.
 */
class VisionEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = (string) $request->header('X-Vision-Token');
        if ($token === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $tenantId = 0;
        $box      = null;

        $box = \App\Models\VisionBox::where('webhook_token', $token)->where('status', 'claimed')->first();
        if ($box) {
            $tenantId = (int) $box->tenant_id;
            $box->forceFill(['last_seen_at' => now(), 'last_ip' => (string) $request->ip()])->save();
        } else {
            $legacy = (string) config('vision.token', '');
            if ($legacy === '' || ! hash_equals($legacy, $token)) {
                return response()->json(['error' => 'unauthorized'], 401);
            }
            $tenantId = (int) config('vision.tenant_id', 0);
        }

        if (! $tenantId) {
            return response()->json(['error' => 'vision tenant not configured'], 500);
        }

        $data = $request->validate([
            'event' => 'required|string|max:60',
            'store' => 'nullable|string|max:80',
            'camera' => 'nullable|string|max:120',
            'zone' => 'nullable|string|max:120',
            'from_state' => 'nullable|string|max:20',
            'to_state' => 'nullable|string|max:20',
            'fill' => 'nullable|numeric',
            'timestamp' => 'nullable|string',
        ]);

        $payload = $request->all();
        unset($payload['thumbnail']);   // never store base64 frames in the row
        if ($box) {
            $payload['_box_uid'] = $box->box_uid;   // provenance without a schema change
        }

        // Idempotency: the edge outbox retries at-least-once, so a re-delivered event (same uid) must
        // return the ORIGINAL row and decision — never a second row or a duplicate reconciliation.
        $uid = (string) ($payload['uid'] ?? '');
        if ($uid !== '') {
            $dup = VisionEvent::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('event_uid', $uid)->first();
            if ($dup) {
                return response()->json([
                    'ok' => true, 'id' => $dup->id, 'duplicate' => true,
                    'reconciliation' => is_array($dup->payload) ? ($dup->payload['reconciliation'] ?? null) : null,
                ]);
            }
        }

        try {
            $ev = VisionEvent::create([
                'tenant_id' => $tenantId,
                'event_uid' => $uid !== '' ? $uid : null,
                'event' => $data['event'],
                'store' => $data['store'] ?? ($box->store ?? null),
                'camera' => $data['camera'] ?? null,
                // A movement event carries its instrumented boundary in `zone_uid`; fall back to `zone`.
                'zone' => $payload['zone_uid'] ?? ($data['zone'] ?? null),
                'from_state' => $data['from_state'] ?? null,
                'to_state' => $data['to_state'] ?? null,
                'fill' => isset($data['fill']) ? (float) $data['fill'] : null,
                'payload' => $payload,
                'occurred_at' => isset($data['timestamp']) ? date('Y-m-d H:i:s', strtotime($data['timestamp']) ?: time()) : now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique (tenant_id, event_uid) race — a concurrent re-delivery won. Return the original.
            $dup = $uid !== '' ? VisionEvent::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('event_uid', $uid)->first() : null;
            if ($dup) {
                return response()->json([
                    'ok' => true, 'id' => $dup->id, 'duplicate' => true,
                    'reconciliation' => is_array($dup->payload) ? ($dup->payload['reconciliation'] ?? null) : null,
                ]);
            }
            throw $e;
        }

        // Phase-0 Stock & Loss: reconcile MOVEMENT events against the ledger (opt-in; shelf events are
        // never touched). Best-effort — a reconciliation failure must never drop the event.
        $decision = null;
        if (\App\Services\Vision\Reconciliation\VisionReconciler::enabled()
            && \App\Services\Vision\Reconciliation\VisionReconciler::handles((string) $data['event'])) {
            try {
                $decision = app(\App\Services\Vision\Reconciliation\VisionReconciler::class)
                    ->reconcile($tenantId, $payload, $ev);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('vision reconcile failed: ' . $e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'id' => $ev->id, 'reconciliation' => $decision]);
    }
}
