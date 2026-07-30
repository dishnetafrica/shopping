<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the CloudBSS Vision Gateway (M1 frozen event schema) into vision_events.
 *
 * Auth: shared secret header X-Vision-Token, compared against services.vision.token
 * (config/services.php → 'vision' => ['token' => env('VISION_WEBHOOK_TOKEN'), 'tenant_id' => ...]).
 * Thumbnails are dropped before storage (size); everything else is kept verbatim in payload.
 *
 * v1 scope: STORAGE ONLY. Joining shelf events to stock movements as passive P-class evidence is
 * M3 (needs zone→SKU mapping); this endpoint exists now so no event from the pilot store is lost.
 */
class VisionEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = (string) config('services.vision.token', env('VISION_WEBHOOK_TOKEN', ''));
        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Vision-Token'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $tenantId = (int) config('services.vision.tenant_id', env('VISION_TENANT_ID', 0));
        if (! $tenantId) {
            return response()->json(['error' => 'VISION_TENANT_ID not configured'], 500);
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

        $ev = VisionEvent::create([
            'tenant_id' => $tenantId,
            'event' => $data['event'],
            'store' => $data['store'] ?? null,
            'camera' => $data['camera'] ?? null,
            'zone' => $data['zone'] ?? null,
            'from_state' => $data['from_state'] ?? null,
            'to_state' => $data['to_state'] ?? null,
            'fill' => isset($data['fill']) ? (float) $data['fill'] : null,
            'payload' => $payload,
            'occurred_at' => isset($data['timestamp']) ? date('Y-m-d H:i:s', strtotime($data['timestamp']) ?: time()) : now(),
        ]);

        return response()->json(['ok' => true, 'id' => $ev->id]);
    }
}
