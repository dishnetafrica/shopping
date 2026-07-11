<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ads screen — control + visibility for the Facebook-ad bot understanding.
 * Lets the owner turn "understand ad customers" on/off, and see who arrived from ads and
 * which product the bot matched (from the bot_events trace: ad_referral / ad_followup).
 */
class AdsController extends Controller
{
    private function priv($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /panel/ads — the screen. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/ads.html');
        if (! is_file($path)) abort(500, 'Ads asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** GET /papi/ads — current state + recent ad arrivals. */
    public function data(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok' => false], 401);

        $events = [];
        $count7 = 0;
        try {
            $rows = DB::table('bot_events')
                ->where('tenant_id', $t->id)
                ->whereIn('stage', ['ad_referral', 'ad_followup'])
                ->orderByDesc('id')->limit(80)->get();
            foreach ($rows as $r) {
                $events[] = [
                    'when'   => (string) $r->created_at,
                    'phone'  => (string) $r->phone,
                    'stage'  => (string) $r->stage,      // ad_referral | ad_followup
                    'detail' => (string) $r->detail,     // the ad headline / matched topic
                ];
            }
            $count7 = (int) DB::table('bot_events')->where('tenant_id', $t->id)
                ->where('stage', 'ad_referral')
                ->where('created_at', '>=', now()->subDays(7))->count();
        } catch (\Throwable $e) { /* bot_events optional */ }

        return response()->json([
            'ok'          => true,
            'enabled'     => (bool) $t->setting('bot_ad_awareness', true),
            'bot_mode'    => (string) $t->setting('bot_mode', 'auto'),
            'is_owner'    => $this->priv($request->user()),
            'arrivals_7d' => $count7,
            'events'      => $events,
        ]);
    }

    /** POST /papi/ads-toggle?on=1|0 — owner/manager turns ad understanding on/off. */
    public function toggle(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $on = filter_var($request->input('on', $request->query('on', '1')), FILTER_VALIDATE_BOOLEAN);
        $t->putSetting('bot_ad_awareness', $on);
        return response()->json(['ok' => true, 'enabled' => $on]);
    }
}
