<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transport Register (Kampala → Juba) — a shipment-centric movement ledger.
 *
 * This is an additive module: it does NOT touch inventory or the trading engine.
 * State lives in a single tenant setting ("transport_register") and syncs with an
 * offline-first, last-write-wins-by-uid protocol, so the transporter's phone and
 * the manager's screen converge without a migration or new table.
 */
class TransportController extends Controller
{
    private const SETTING   = 'transport_register';
    private const MAX_SHIP  = 4000;   // rolling caps — plenty for a busy corridor; archive later if needed
    private const MAX_LOAD  = 4000;
    private const MAX_TOMB   = 8000;

    /** Serve the full-screen register HTML with the sync config injected (behind login). */
    public function page(Request $r)
    {
        $path = resource_path('transport/register.html');
        $html = is_file($path) ? (string) file_get_contents($path) : '<h1>Transport Register unavailable</h1>';

        $cfg = [
            'syncUrl'  => url('/papi/transport-sync'),
            'getUrl'   => url('/papi/transport-get'),
            'csrf'     => csrf_token(),
        ];
        // The register page reads `window.TR_CONFIG || null`; swap in the real config.
        $html = str_replace('window.TR_CONFIG || null', json_encode($cfg, JSON_UNESCAPED_SLASHES), $html);

        // Inject the tenant's product catalogue so the RECEIVE screen is tap-to-pick (no typing).
        $products = $this->catalogue($r);
        $html = str_replace('window.TR_PRODUCTS || []', json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $html);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Product names (with category/unit) for the pick-list. Best-effort; never throws. */
    private function catalogue(Request $r): array
    {
        try {
            $tid = (int) optional($r->user())->tenant_id;
            if (! $tid) return [];
            return \App\Models\Product::query()
                ->where('tenant_id', $tid)
                ->orderBy('name')
                ->get(['name', 'category', 'unit_label'])
                ->map(fn ($p) => [
                    'name' => (string) $p->name,
                    'cat'  => (string) ($p->category ?? ''),
                    'unit' => (string) ($p->unit_label ?? ''),
                ])
                ->filter(fn ($x) => $x['name'] !== '')
                ->unique('name')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Return the current register for this tenant. */
    public function get(Request $r): JsonResponse
    {
        return response()->json(['ok' => true] + $this->store($r));
    }

    /** Merge the client's push into the tenant register (last-write-wins by uid) and return the merged set. */
    public function sync(Request $r): JsonResponse
    {
        $t = $r->user()->tenant;
        $cur = $this->store($r);

        $in = $r->all();
        $incShip = is_array($r->input('shipments')) ? $r->input('shipments') : [];
        $incLoad = is_array($r->input('loads')) ? $r->input('loads') : [];
        $incTomb = is_array($r->input('tombstones')) ? $r->input('tombstones') : [];
        $incComp = is_array($r->input('companies')) ? $r->input('companies') : [];

        // Merge tombstones (uid => updatedAt), newest wins.
        $tomb = $cur['tombstones'];
        foreach ($incTomb as $uid => $ts) {
            $uid = (string) $uid; $ts = (int) $ts;
            if (! isset($tomb[$uid]) || $ts > $tomb[$uid]) $tomb[$uid] = $ts;
        }

        $ship = $this->mergeById($cur['shipments'], $incShip);
        $load = $this->mergeById($cur['loads'], $incLoad);

        // Apply tombstones: a delete removes any entity it supersedes.
        $ship = $this->applyTombstones($ship, $tomb);
        $load = $this->applyTombstones($load, $tomb);

        // Union of remembered bus companies.
        $comp = $cur['companies'];
        foreach ($incComp as $c) {
            $c = trim((string) $c);
            if ($c !== '' && ! in_array($c, $comp, true)) $comp[] = $c;
        }

        // Caps (keep the most recently updated).
        $ship = $this->cap($ship, self::MAX_SHIP);
        $load = $this->cap($load, self::MAX_LOAD);
        if (count($tomb) > self::MAX_TOMB) {
            arsort($tomb);
            $tomb = array_slice($tomb, 0, self::MAX_TOMB, true);
        }
        $comp = array_slice($comp, 0, 60);

        $out = ['shipments' => array_values($ship), 'loads' => array_values($load), 'tombstones' => $tomb, 'companies' => array_values($comp)];
        $t->putSetting(self::SETTING, $out);

        return response()->json(['ok' => true] + $out);
    }

    /** Read + normalise the stored register. */
    private function store(Request $r): array
    {
        $t = $r->user()->tenant;
        $d = $t->setting(self::SETTING, []);
        if (! is_array($d)) $d = [];
        return [
            'shipments'  => array_values(array_filter((array) ($d['shipments'] ?? []), 'is_array')),
            'loads'      => array_values(array_filter((array) ($d['loads'] ?? []), 'is_array')),
            'tombstones' => is_array($d['tombstones'] ?? null) ? $d['tombstones'] : [],
            'companies'  => array_values(array_filter((array) ($d['companies'] ?? []), 'is_string')),
        ];
    }

    /** Merge incoming entities into current, keyed by uid, newer updatedAt wins. */
    private function mergeById(array $cur, array $inc): array
    {
        $by = [];
        foreach ($cur as $e) { if (is_array($e) && isset($e['uid'])) $by[(string) $e['uid']] = $e; }
        foreach ($inc as $e) {
            if (! is_array($e) || ! isset($e['uid'])) continue;
            $uid = (string) $e['uid'];
            $eu  = (int) ($e['updatedAt'] ?? 0);
            if (! isset($by[$uid]) || $eu > (int) ($by[$uid]['updatedAt'] ?? 0)) $by[$uid] = $e;
        }
        return $by;
    }

    private function applyTombstones(array $byId, array $tomb): array
    {
        $out = [];
        foreach ($byId as $uid => $e) {
            $td = (int) ($tomb[$uid] ?? 0);
            if ($td && $td >= (int) ($e['updatedAt'] ?? 0)) continue;   // deleted
            $out[$uid] = $e;
        }
        return $out;
    }

    private function cap(array $byId, int $max): array
    {
        if (count($byId) <= $max) return $byId;
        $list = array_values($byId);
        usort($list, fn ($a, $b) => (int) ($b['updatedAt'] ?? 0) <=> (int) ($a['updatedAt'] ?? 0));
        $list = array_slice($list, 0, $max);
        $out = [];
        foreach ($list as $e) { $out[(string) $e['uid']] = $e; }
        return $out;
    }
}
