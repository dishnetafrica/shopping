<?php declare(strict_types=1);

namespace App\Services\Vision\Reconciliation;

use App\Models\Tenant;
use App\Models\VisionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laravel adapter around the pure ReconciliationEngine (Phase-0 single-door slice).
 *
 * It does the framework-bound half the engine deliberately avoids: normalise the ingested movement
 * payload into the engine's event contract, load the candidate ledger window from `stock_movements`
 * and the recent prior movements for net-zero, run the engine, and persist the decision onto the
 * vision_events row. The verdict logic itself stays in the (unit-tested) engine.
 *
 * Scope: MOVEMENT events only. Classical shelf events never reach here. Quantity-level reconciliation
 * is deferred (a carton is not a SKU-unit) — the slice reconciles on the PRESENCE of a matching
 * outbound transaction, via a wide qty tolerance.
 */
final class VisionReconciler
{
    public const MOVEMENT_KINDS = [
        'carton.move', 'pallet.move', 'zone.entry', 'area.intrusion', 'tracking.gap', 'camera.offline',
    ];

    public static function handles(string $kind): bool
    {
        return in_array($kind, self::MOVEMENT_KINDS, true);
    }

    public static function enabled(): bool
    {
        return (bool) config('vision.reconcile.enabled', false);
    }

    /** Reconcile one movement event and stamp the result onto its row. Returns the decision. */
    public function reconcile(int $tenantId, array $payload, VisionEvent $row): array
    {
        $cfg    = $this->engineConfig($tenantId);
        $event  = $this->buildEvent($payload);
        $asOf   = time();
        $ledger = $this->loadLedger($tenantId, $event, $cfg);
        $prior  = $this->loadPrior($tenantId, $event, $cfg, (int) $row->id);

        $decision = ReconciliationEngine::reconcile($event, $ledger, $prior, $cfg, $asOf);
        $this->persist($row, $decision);

        return $decision;
    }

    /** Normalise an ingested payload into the engine's event contract. */
    private function buildEvent(array $p): array
    {
        $ts = isset($p['timestamp']) ? (strtotime((string) $p['timestamp']) ?: time()) : time();

        return [
            'kind'       => (string) ($p['event'] ?? ''),
            'uid'        => (string) ($p['uid'] ?? ''),
            'zone_uid'   => (string) ($p['zone_uid'] ?? ($p['zone'] ?? '')),
            'direction'  => (string) ($p['direction'] ?? ''),
            'object'     => [
                'class'    => (string) ($p['object']['class'] ?? ''),
                'qty'      => (int) ($p['object']['qty'] ?? 1),
                'qty_conf' => (float) ($p['object']['qty_conf'] ?? 1.0),
            ],
            'assoc'      => [
                'track_id'   => isset($p['assoc']['track_id']) ? (int) $p['assoc']['track_id'] : null,
                'candidates' => array_map('intval', (array) ($p['assoc']['candidates'] ?? [])),
                'assoc_conf' => (float) ($p['assoc']['assoc_conf'] ?? 0.0),
            ],
            'confidence' => (float) ($p['confidence'] ?? 0.0),
            'occlusion'  => (bool) ($p['occlusion'] ?? false),
            'ts'         => $ts,
        ];
    }

    /**
     * Candidate ledger transactions: signed stock movements at the mapped location within the match
     * window. Direction is read from the SIGN of qty (OUT = negative), which is robust across
     * IN/OUT/MOVE/ADJUST types. Quantity is passed as-is; the wide tolerance makes this presence-based.
     */
    private function loadLedger(int $tenantId, array $event, array $cfg): array
    {
        // Mapping is mandatory — never fall back to the zone name as a location (the engine also
        // guards this). An unmapped zone loads no ledger and the engine returns UNMAPPED_ZONE.
        $map = (array) ($cfg['zone_location_map'] ?? []);
        if (! array_key_exists($event['zone_uid'], $map) || ($event['direction'] ?? '') === '') {
            return [];
        }
        $location = (string) $map[$event['zone_uid']];
        // ASYMMETRIC window, matching the engine: up to match_window BEFORE the crossing, up to the full
        // grace AFTER it (paperwork lags). A symmetric ±match_window would miss a late-but-in-grace txn.
        $from  = Carbon::createFromTimestamp($event['ts'] - (int) $cfg['match_window_seconds']);
        $to    = Carbon::createFromTimestamp($event['ts'] + (int) $cfg['grace_seconds']);

        $rows = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('location', $location)
            ->whereBetween('created_at', [$from, $to])
            ->get(['type', 'qty', 'ref_type', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            if (abs($qty) < 0.0005) {
                continue;
            }
            $out[] = [
                'type'      => (string) ($r->ref_type ?: $r->type),
                'location'  => $location,
                'direction' => $qty < 0 ? 'out' : 'in',
                'qty'       => (int) round(abs($qty)),
                'ts'        => Carbon::parse($r->created_at)->getTimestamp(),
            ];
        }

        return $out;
    }

    /** Recent movement events in the same zone, for net-zero (out-then-in) detection. */
    private function loadPrior(int $tenantId, array $event, array $cfg, int $excludeId): array
    {
        $win  = (int) $cfg['net_zero_window_seconds'];
        $from = Carbon::createFromTimestamp($event['ts'] - $win);

        $rows = VisionEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('zone', $event['zone_uid'])
            ->whereIn('event', ['carton.move', 'pallet.move'])
            ->where('id', '!=', $excludeId)
            ->where('occurred_at', '>=', $from)
            ->latest('id')->limit(20)->get();

        $out = [];
        foreach ($rows as $r) {
            $pl = is_array($r->payload) ? $r->payload : [];
            $out[] = [
                'kind'      => (string) $r->event,
                'zone_uid'  => (string) ($pl['zone_uid'] ?? $r->zone),
                'direction' => (string) ($pl['direction'] ?? ''),
                'assoc'     => ['track_id' => isset($pl['assoc']['track_id']) ? (int) $pl['assoc']['track_id'] : null],
                'ts'        => $r->occurred_at ? Carbon::parse($r->occurred_at)->getTimestamp() : 0,
            ];
        }

        return $out;
    }

    /** Merge config/vision.php reconcile settings with any per-tenant overrides. */
    private function engineConfig(int $tenantId): array
    {
        $base = (array) config('vision.reconcile', []);
        $t    = Tenant::find($tenantId);

        $zoneMap    = $t ? (array) $t->setting('vision_zone_location_map', []) : [];
        $controlled = $t ? (array) $t->setting('vision_controlled_zones', []) : [];
        $authorized = $t ? (array) $t->setting('vision_authorized_tracks', []) : [];

        return ReconciliationEngine::defaults([
            'grace_seconds'           => (int) ($base['grace_seconds'] ?? 900),
            'match_window_seconds'    => (int) ($base['match_window_seconds'] ?? 300),
            'net_zero_window_seconds' => (int) ($base['net_zero_window_seconds'] ?? 300),
            'qty_tolerance'           => (int) ($base['qty_tolerance'] ?? 1_000_000),
            'zone_location_map'       => $zoneMap ?: (array) ($base['zone_location_map'] ?? []),
            'controlled_zones'        => $controlled ?: (array) ($base['controlled_zones'] ?? []),
            'authorized'              => $authorized,
        ]);
    }

    private function persist(VisionEvent $row, array $decision): void
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $payload['reconciliation'] = $decision;

        $row->forceFill([
            'recon_result'    => (string) $decision['result'],
            'recon_severity'  => (string) $decision['severity'],
            'review_required' => (bool) $decision['review_required'],
            'reconciled_at'   => now(),
            'payload'         => $payload,
        ])->save();
    }
}
