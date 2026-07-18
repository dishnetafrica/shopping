<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Http\Request;

/** Web Push endpoints for the v3 shell: key discovery, subscribe, test. */
class PushController extends Controller
{
    public function vapidPublic(Request $r, WebPushService $push)
    {
        if (! $r->user()?->tenant) return response()->json(['ok' => false], 401);

        return response()->json(['ok' => true, 'key' => $push->publicKey()]);
    }

    public function subscribe(Request $r, WebPushService $push)
    {
        $t = $r->user()?->tenant;
        $endpoint = trim((string) $r->input('endpoint', ''));
        $p256dh   = trim((string) $r->input('p256dh', ''));
        $auth     = trim((string) $r->input('auth', ''));
        if (! $t || $endpoint === '' || $p256dh === '' || $auth === '') {
            return response()->json(['ok' => false, 'error' => 'incomplete'], 422);
        }
        $push->subscribe($t->id, $r->user()->id, $endpoint, $p256dh, $auth);

        return response()->json(['ok' => true, 'message' => 'This device will now be notified']);
    }

    public function unsubscribe(Request $r)
    {
        $t = $r->user()?->tenant;
        $endpoint = trim((string) $r->input('endpoint', ''));
        if ($t && $endpoint !== '') {
            PushSubscription::where('tenant_id', $t->id)->where('endpoint', $endpoint)->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Owner sanity check: buzz my own devices right now. */
    public function test(Request $r, WebPushService $push)
    {
        $u = $r->user();
        if (! $u?->tenant || ! $u->isOwnerLike()) return response()->json(['ok' => false], 403);
        $n = $push->sendToTenant($u->tenant->id, 'CloudBSS test', 'Push notifications are working 🎉', '/v3#/chats');

        return response()->json(['ok' => true, 'message' => 'Sent to '.$n.' device'.($n === 1 ? '' : 's')]);
    }
}
