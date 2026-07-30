<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisionBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Zero-touch provisioning for Vision Gateway boxes (handover §8: "box boots, shows a claim
 * code, you claim it from CloudBSS").
 *
 * Protocol (all JSON, no session, rate-limited at the route):
 *   POST /api/vision/box/hello  {box_uid, secret, fw_version?, hw_model?}
 *       First contact after flash/boot. Registers (or re-syncs) the box and returns a short
 *       claim code. A CLAIMED box that re-hellos with the right secret gets its full config
 *       back immediately — so a reflashed-but-already-claimed box heals without human help
 *       ONLY if it kept its secret; a reflash that lost the secret needs vision:boxes --forget.
 *   POST /api/vision/box/poll   {box_uid, secret}
 *       Pending: returns the (possibly refreshed) claim code. Claimed: returns config.
 *       Doubles as the fleet heartbeat (last_seen_at / last_ip) — claimed boxes keep polling
 *       at a slow interval, which is also the future hook for signed OTA (backlog V3).
 *
 * Security model: the box invents a random secret at first boot and keeps it in
 * /etc/cloudbss/box-secret; we store only sha256(secret). A claimed box_uid can never be
 * re-bound by a stranger who knows the uid alone (secret mismatch → 403). Claim codes are
 * short-lived (vision.claim_ttl_hours), single-use, and unambiguous (no 0/O/1/I).
 */
class VisionBoxController extends Controller
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function hello(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box_uid'    => 'required|string|min:8|max:64',
            'secret'     => 'required|string|min:24|max:128',
            'fw_version' => 'nullable|string|max:40',
            'hw_model'   => 'nullable|string|max:80',
        ]);

        $hash = hash('sha256', $data['secret']);
        $box  = VisionBox::where('box_uid', $data['box_uid'])->first();

        if ($box && $box->status === 'claimed') {
            if (! hash_equals($box->secret_sha256, $hash)) {
                return response()->json(['error' => 'identity mismatch — box_uid already claimed'], 403);
            }
            $this->touch($box, $request, $data);
            return $this->claimedResponse($box);
        }

        if ($box && $box->status === 'revoked') {
            return response()->json(['error' => 'box revoked'], 403);
        }

        // Secret is write-once per box_uid. A legit reflash regenerates /etc/machine-id, so it
        // arrives as a NEW uid — a secret change on an existing uid is never legitimate traffic.
        // Recovery for the rare corrupted-secret case: vision:boxes --forget=<uid>.
        if ($box && ! hash_equals($box->secret_sha256, $hash)) {
            return response()->json(['error' => 'identity mismatch — vision:boxes --forget to re-register'], 403);
        }

        if (! $box) {
            $box = new VisionBox([
                'box_uid'       => $data['box_uid'],
                'status'        => 'pending',
                'secret_sha256' => $hash,
            ]);
        }
        if (! $box->claim_code || ! $box->claim_expires_at || $box->claim_expires_at->isPast()) {
            $box->claim_code       = $this->freshCode();
            $box->claim_expires_at = now()->addHours(max(1, (int) config('vision.claim_ttl_hours', 24)));
        }
        $this->touch($box, $request, $data);

        return response()->json([
            'status'       => 'pending',
            'claim_code'   => $box->claim_code,
            'poll_seconds' => 10,
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box_uid' => 'required|string|min:8|max:64',
            'secret'  => 'required|string|min:24|max:128',
        ]);

        $box = VisionBox::where('box_uid', $data['box_uid'])->first();
        if (! $box || ! hash_equals($box->secret_sha256, hash('sha256', $data['secret']))) {
            return response()->json(['error' => 'unknown box'], 403);
        }
        if ($box->status === 'revoked') {
            return response()->json(['error' => 'box revoked'], 403);
        }

        $this->touch($box, $request);

        if ($box->status === 'claimed') {
            return $this->claimedResponse($box);
        }

        if (! $box->claim_code || ! $box->claim_expires_at || $box->claim_expires_at->isPast()) {
            $box->claim_code       = $this->freshCode();
            $box->claim_expires_at = now()->addHours(max(1, (int) config('vision.claim_ttl_hours', 24)));
            $box->save();
        }

        return response()->json([
            'status'       => 'pending',
            'claim_code'   => $box->claim_code,
            'poll_seconds' => 10,
        ]);
    }

    private function claimedResponse(VisionBox $box): JsonResponse
    {
        return response()->json([
            'status'        => 'claimed',
            'store'         => $box->store,
            'webhook_url'   => rtrim((string) config('app.url'), '/') . '/api/vision/events',
            'webhook_token' => $box->webhook_token,
            'poll_seconds'  => 300,
        ]);
    }

    private function touch(VisionBox $box, Request $request, array $data = []): void
    {
        $box->last_seen_at = now();
        $box->last_ip      = (string) $request->ip();
        if (! empty($data['fw_version'])) $box->fw_version = $data['fw_version'];
        if (! empty($data['hw_model']))   $box->hw_model   = $data['hw_model'];
        $box->save();
    }

    private function freshCode(): string
    {
        // 6 chars, ~1e9 combinations over a 24h window with rate-limited endpoints; collision
        // with another OUTSTANDING pending code is the only thing that matters — retry a few times.
        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($c = 0; $c < 6; $c++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $inUse = VisionBox::where('claim_code', $code)
                ->where('status', 'pending')
                ->where('claim_expires_at', '>', now())
                ->exists();
            if (! $inUse) {
                return $code;
            }
        }
        return Str::upper(Str::random(8)); // vanishingly unlikely fallback
    }
}
