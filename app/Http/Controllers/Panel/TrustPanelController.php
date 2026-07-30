<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VisionBox;
use App\Services\Trust\TrustRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory Trust panel (backlog T6) — the owner's screen for what WhatsApp summarizes.
 *
 * GET  /panel/trust                 the page (resources/panel/trust.html, house pattern)
 * GET  /papi/trust-summary          weekly pilot metrics (reuses trust:weekly --json), queue + vision counts
 * GET  /papi/trust-queue            open investigations (+ recently resolved), ranked by expected loss
 * POST /papi/trust-resolve          resolve an investigation → TrustRuntime::resolveInvestigation
 * GET  /papi/trust-scores           latest scored movements (filterable by decision)
 * GET  /papi/vision-status          this tenant's boxes + latest shelf events
 * POST /papi/vision-claim           claim a pending box BY CODE into the signed-in tenant
 * POST /papi/vision-box-revoke      revoke one of this tenant's boxes
 *
 * Everything is tenant-scoped through the signed-in user (same guard as the other panel screens).
 * Spec §5.2 stays law here: actor data routes review only — the UI never ranks people.
 */
class TrustPanelController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/trust.html');
        if (! is_file($path)) {
            abort(500, 'Trust panel asset missing.');
        }
        $name = (string) ($user->tenant->name ?? 'CloudBSS');
        $html = str_replace('{{TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    private function tenant(Request $request): ?Tenant
    {
        return $request->user()?->tenant;
    }

    public function summary(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $metrics = null;
        try {
            Artisan::call('trust:weekly', [
                '--tenant' => (int) $t->id,
                '--days'   => max(1, min(90, (int) $request->query('days', 7))),
                '--json'   => true,
            ]);
            $out = Artisan::output();
            $a = strpos($out, '{');
            $b = strrpos($out, '}');
            if ($a !== false && $b !== false && $b > $a) {
                $metrics = json_decode(substr($out, $a, $b - $a + 1), true);
            }
        } catch (\Throwable $e) {
            \Log::warning('trust-summary weekly failed: ' . $e->getMessage());
        }

        $open = DB::table('trust_investigations')->where('tenant_id', $t->id)->whereNull('outcome')->count();
        $boxes = VisionBox::where('tenant_id', $t->id)->get(['status'])->groupBy('status')->map->count();
        $events24 = DB::table('vision_events')->where('tenant_id', $t->id)
            ->where('created_at', '>=', now()->subDay())->count();
        $scored7 = DB::table('trust_scores')->where('tenant_id', $t->id)
            ->where('created_at', '>=', now()->subDays(7))->count();

        return response()->json([
            'ok' => true,
            'metrics' => $metrics,
            'open_queue' => $open,
            'scored_7d' => $scored7,
            'boxes' => ['claimed' => (int) ($boxes['claimed'] ?? 0), 'revoked' => (int) ($boxes['revoked'] ?? 0)],
            'vision_events_24h' => $events24,
        ]);
    }

    public function queue(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $base = DB::table('trust_investigations as ti')
            ->join('stock_movements as m', 'm.id', '=', 'ti.stock_movement_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('ti.tenant_id', $t->id)
            ->select('ti.id', 'ti.source', 'ti.expected_loss', 'ti.outcome', 'ti.outcome_value',
                'ti.resolved_by', 'ti.resolved_at', 'ti.notes', 'ti.created_at as opened_at',
                'm.type', 'm.sku', 'm.location', 'm.qty', 'm.created_by as actor',
                'm.created_at as moved_at', 'p.name as product');

        $open = (clone $base)->whereNull('ti.outcome')
            ->orderByDesc('ti.expected_loss')->limit(50)->get();
        $recent = (clone $base)->whereNotNull('ti.outcome')
            ->orderByDesc('ti.resolved_at')->limit(10)->get();

        return response()->json(['ok' => true, 'open' => $open, 'recent' => $recent]);
    }

    public function resolve(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate([
            'id'      => 'required|integer',
            'outcome' => 'required|string|in:clean,error,confirmed_loss,unresolved',
            'value'   => 'nullable|numeric|min:0',
            'notes'   => 'nullable|string|max:500',
        ]);

        $by = (string) ($request->user()->name ?? $request->user()->email ?? 'panel');
        $res = TrustRuntime::forTenant($t)->resolveInvestigation(
            (int) $data['id'],
            (string) $data['outcome'],
            isset($data['value']) && $data['value'] !== null && $data['value'] !== '' ? (float) $data['value'] : null,
            (string) ($data['notes'] ?? ''),
            $by . ' (panel)'
        );

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function scores(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $q = DB::table('trust_scores as s')
            ->join('stock_movements as m', 'm.id', '=', 's.stock_movement_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('s.tenant_id', $t->id)
            ->select('s.id', 's.trust', 's.trust_required', 's.decision', 's.expected_loss',
                's.reason_codes', 's.created_at as scored_at',
                'm.type', 'm.sku', 'm.location', 'm.qty', 'm.created_by as actor',
                'm.created_at as moved_at', 'p.name as product')
            ->orderByDesc('s.id');

        if ($d = (string) $request->query('decision', '')) {
            if (in_array($d, ['auto_post', 'step_up', 'hold'], true)) {
                $q->where('s.decision', $d);
            }
        }

        return response()->json(['ok' => true, 'scores' => $q->limit(60)->get()]);
    }

    public function vision(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $boxes = VisionBox::where('tenant_id', $t->id)->orderByDesc('last_seen_at')->get()
            ->map(fn (VisionBox $b) => [
                'uid' => $b->box_uid, 'uid_short' => substr($b->box_uid, 0, 10),
                'status' => $b->status, 'store' => $b->store, 'fw' => $b->fw_version,
                'hw' => $b->hw_model, 'ip' => $b->last_ip,
                'last_seen' => optional($b->last_seen_at)->diffForHumans(),
                'online' => $b->last_seen_at && $b->last_seen_at->gt(now()->subMinutes(12)),
            ]);

        $events = DB::table('vision_events')->where('tenant_id', $t->id)
            ->orderByDesc('id')->limit(30)
            ->get(['event', 'store', 'camera', 'zone', 'from_state', 'to_state', 'fill', 'occurred_at']);

        return response()->json(['ok' => true, 'boxes' => $boxes, 'events' => $events]);
    }

    public function visionClaim(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate([
            'code'  => 'required|string|min:4|max:12',
            'store' => 'nullable|string|max:80',
        ]);

        $code = Str::upper(trim($data['code']));
        $box = VisionBox::where('claim_code', $code)
            ->where('status', 'pending')
            ->where('claim_expires_at', '>', now())
            ->first();
        if (! $box) {
            return response()->json(['ok' => false, 'error' => 'No pending box with that code (codes expire — read the current one off the box screen).'], 422);
        }

        $box->forceFill([
            'status'           => 'claimed',
            'tenant_id'        => (int) $t->id,
            'store'            => (string) ($data['store'] ?: 'kampala'),
            'webhook_token'    => Str::random(64),
            'claim_code'       => null,
            'claim_expires_at' => null,
            'claimed_at'       => now(),
        ])->save();

        return response()->json(['ok' => true, 'uid' => $box->box_uid, 'store' => $box->store]);
    }

    public function visionRevoke(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $data = $request->validate(['uid' => 'required|string|max:64']);

        $box = VisionBox::where('tenant_id', $t->id)->where('box_uid', $data['uid'])->first();
        if (! $box) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $box->forceFill(['status' => 'revoked', 'webhook_token' => null])->save();

        return response()->json(['ok' => true]);
    }
}
