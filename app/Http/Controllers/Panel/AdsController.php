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
            'ok'               => true,
            'enabled'          => (bool) $t->setting('bot_ad_awareness', true),
            'bot_mode'         => (string) $t->setting('bot_mode', 'auto'),
            'is_owner'         => $this->priv($request->user()),
            'arrivals_7d'      => $count7,
            'events'           => $events,
            // Bot behaviour controls surfaced on this screen
            'business_name'    => (string) $t->name,
            'ad_ignore_titles' => (string) $t->setting('ad_ignore_titles', ''),
            'keep_ai_on'       => ! (bool) $t->setting('human_takeover_on_reply', true), // true = AI keeps replying after a manual message
            'warehouse'        => (string) $t->setting('warehouse_location', ''),
            'moq_note'         => (string) $t->setting('moq_note', ''),
            'basis_city'       => (string) $t->setting('export_basis_city', ''),
            // Virtual salesperson (auto follow-up cadence)
            'sales_on'         => (bool) $t->setting('sales_followup_enabled', false),
            'sales_buyers'     => (bool) $t->setting('sales_include_buyers', true),
            'sales_reorder_days' => (int) $t->setting('sales_reorder_days', 21),
            'sales_msg_day1'   => (string) $t->setting('sales_msg_day1', ''),
            'sales_msg_reorder'=> (string) $t->setting('sales_msg_reorder', ''),
        ]);
    }

    /** POST /papi/ads-settings — owner/manager saves the bot-behaviour controls on this screen. */
    public function saveSettings(Request $request)
    {
        $t = $request->user()->tenant; $u = $request->user();
        if (! $t) return response()->json(['ok' => false], 401);
        if (! $this->priv($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        if ($request->has('ad_ignore_titles')) {
            $t->putSetting('ad_ignore_titles', trim((string) $request->input('ad_ignore_titles')));
        }
        if ($request->has('keep_ai_on')) {
            // UI sends keep_ai_on = 1 when the owner wants the AI to keep replying after a manual
            // message. That is the INVERSE of human_takeover_on_reply.
            $keep = filter_var($request->input('keep_ai_on'), FILTER_VALIDATE_BOOLEAN);
            $t->putSetting('human_takeover_on_reply', ! $keep);
        }
        if ($request->has('warehouse')) {
            $t->putSetting('warehouse_location', trim((string) $request->input('warehouse')));
        }
        if ($request->has('moq_note')) {
            $t->putSetting('moq_note', trim((string) $request->input('moq_note')));
        }
        if ($request->has('basis_city')) {
            $t->putSetting('export_basis_city', trim((string) $request->input('basis_city')));
        }
        // Virtual salesperson
        if ($request->has('sales_on')) {
            $t->putSetting('sales_followup_enabled', filter_var($request->input('sales_on'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->has('sales_buyers')) {
            $t->putSetting('sales_include_buyers', filter_var($request->input('sales_buyers'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->has('sales_reorder_days')) {
            $t->putSetting('sales_reorder_days', max(3, (int) $request->input('sales_reorder_days')));
        }
        if ($request->has('sales_msg_day1')) {
            $t->putSetting('sales_msg_day1', trim((string) $request->input('sales_msg_day1')));
        }
        if ($request->has('sales_msg_reorder')) {
            $t->putSetting('sales_msg_reorder', trim((string) $request->input('sales_msg_reorder')));
        }
        return response()->json(['ok' => true]);
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
