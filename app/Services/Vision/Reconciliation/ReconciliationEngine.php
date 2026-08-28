<?php declare(strict_types=1);

namespace App\Services\Vision\Reconciliation;

/**
 * ShopBot Vision — Reconciliation Engine (Phase-0, single-door vertical slice).
 *
 * THE PRODUCT MOAT. The camera only observes; the inventory ledger is the source of truth; THIS
 * decides the outcome. A vision movement event is correlated against ledger transactions and graded
 * MATCH / PENDING / UNRECONCILED — never "theft", never an accusation. Every red goes to a human.
 *
 * Deliberately PURE: plain arrays in, a decision array out, no framework, no clock, no DB. Time is
 * passed in ($asOf) so the temporal states (PENDING → resolves later) are deterministic and testable
 * in the sandbox. A thin Laravel adapter (controller + persistence) wires real events/ledger to this;
 * this core carries the logic and the tests.
 *
 * ── CONTRACT: the movement EVENT (frozen for the edge CV service to target) ─────────────────────────
 *   [
 *     'kind'       => 'carton.move',        // carton.move|pallet.move|zone.entry|area.intrusion|tracking.gap|camera.offline
 *     'uid'        => 'a3f0…',              // dedupe key (sha256[:32] of store|zone|ts|dir)
 *     'zone_uid'   => 'D->EXIT',            // instrumented boundary; maps to a location via config
 *     'direction'  => 'out',               // in|out|null
 *     'object'     => ['class'=>'carton','qty'=>1,'qty_conf'=>0.94],
 *     'assoc'      => ['track_id'=>17,'candidates'=>[17],'assoc_conf'=>0.88],  // who — soft, never gates a red
 *     'confidence' => 0.86,                // composite; sets review priority, never the verdict
 *     'occlusion'  => false,
 *     'ts'         => 1723987654,          // unix seconds
 *   ]
 *
 * ── CONTRACT: a ledger TRANSACTION (from stock_movements / dispatch) ─────────────────────────────────
 *   ['type'=>'dispatch','location'=>'EXIT','direction'=>'out','qty'=>1,'ts'=>1723987650]
 *
 * ── CONTRACT: the DECISION returned ─────────────────────────────────────────────────────────────────
 *   ['result'=>MATCH|PENDING|UNRECONCILED|NET_ZERO|QTY_DISCREPANCY|ACCESS|ACCESS_VIOLATION|COVERAGE_GAP,
 *    'severity'=>green|amber|red|info|mute, 'review_required'=>bool, 'attribution'=>known|undetermined,
 *    'matched'=>?txn, 'confidence'=>float, 'reason'=>string, 'notes'=>string[]]
 */
final class ReconciliationEngine
{
    // Results
    public const MATCH           = 'MATCH';
    public const PENDING         = 'PENDING';
    public const UNRECONCILED    = 'UNRECONCILED';
    public const NET_ZERO        = 'NET_ZERO';
    public const QTY_DISCREPANCY = 'QTY_DISCREPANCY';
    public const ACCESS          = 'ACCESS';
    public const ACCESS_VIOLATION = 'ACCESS_VIOLATION';
    public const COVERAGE_GAP    = 'COVERAGE_GAP';
    public const UNMAPPED_ZONE   = 'UNMAPPED_ZONE';   // zone has no ledger location — misconfig, never a red
    public const IGNORED         = 'IGNORED';         // not a movement event — nothing to reconcile

    // Severities (semantic, separate from any UI accent)
    public const GREEN = 'green';
    public const AMBER = 'amber';
    public const RED   = 'red';
    public const INFO  = 'info';
    public const MUTE  = 'mute';

    /** @return array<string,mixed> config with defaults filled in */
    public static function defaults(array $cfg = []): array
    {
        return $cfg + [
            'grace_seconds'         => 900,   // 15 min — paperwork may lag the physical move
            'match_window_seconds'  => 300,   // ±5 min around the event to find a transaction
            'net_zero_window_seconds' => 300, // out-then-back-in inside this cancels
            'qty_tolerance'         => 0,     // exact by default
            'qty_conf_min'          => 0.60,  // below this the counted qty is "uncertain"
            'confidence_low'        => 0.50,  // below this a red is flagged low-confidence
            'assoc_min'             => 0.60,  // below this (or >1 candidate) attribution is undetermined
            'controlled_zones'      => [],    // e.g. ['D->EXIT','X'] — a red only fires on these
            'zone_location_map'     => [],    // ['D->EXIT'=>'EXIT'] — event zone → ledger location
            'authorized'            => [],    // ['X'=>[17,23]] — track_ids allowed in an access zone
        ];
    }

    /**
     * @param  array               $event   one movement/health/access event (contract above)
     * @param  array<int,array>     $ledger  candidate transactions to reconcile against
     * @param  array<int,array>     $prior   recent movement events (for net-zero detection)
     * @param  array                $config  see defaults()
     * @param  int                  $asOf    evaluation time (unix seconds) — enables PENDING → resolve
     * @return array                the decision (contract above)
     */
    public static function reconcile(array $event, array $ledger, array $prior, array $config, int $asOf): array
    {
        $cfg  = self::defaults($config);
        $kind = (string) ($event['kind'] ?? '');
        $conf = (float) ($event['confidence'] ?? 0.0);

        // 1) Absence of observation is NEVER movement — a hard architectural rule.
        if ($kind === 'tracking.gap' || $kind === 'camera.offline') {
            return self::decide(self::COVERAGE_GAP, self::MUTE, false, 'undetermined', null, $conf,
                'Sensor gap — zone marked reduced-assurance. Absence of observation is never movement.', []);
        }

        // 2) Person / access events — reconciled against AUTHORISATION, not the ledger.
        if ($kind === 'zone.entry' || $kind === 'area.intrusion') {
            $zone  = (string) ($event['zone_uid'] ?? '');
            $track = $event['assoc']['track_id'] ?? null;
            $allow = (array) ($cfg['authorized'][$zone] ?? []);
            $ev    = ['zone_uid' => $zone, 'direction' => 'in', 'qty' => 0,
                      'track_id' => $track !== null ? (int) $track : null, 'confidence' => round($conf, 3), 'ts' => (int) ($event['ts'] ?? $asOf)];
            if ($allow === [] || ($track !== null && in_array((int) $track, array_map('intval', $allow), true))) {
                return self::decide(self::ACCESS, self::INFO, false, 'known', null, $conf,
                    'Authorised presence — no stock moved.', [], $ev);
            }
            return self::decide(self::ACCESS_VIOLATION, self::RED, true, 'known', null, $conf,
                'Entry to a controlled zone by an unauthorised track.', [], $ev);
        }

        // Anything that is not a stock-movement event has nothing to reconcile. Explicit, so a shelf
        // event that somehow reaches here can NEVER be processed as a carton movement (defence in depth
        // behind the controller's whitelist).
        if ($kind !== 'carton.move' && $kind !== 'pallet.move') {
            return self::decide(self::IGNORED, self::MUTE, false, 'undetermined', null, $conf,
                'Not a stock-movement event — nothing to reconcile.', [], []);
        }

        // 3) Stock movement (carton.move / pallet.move).
        $qty        = (int) ($event['object']['qty'] ?? 1);
        $qtyConf    = (float) ($event['object']['qty_conf'] ?? 1.0);
        $zoneUid    = (string) ($event['zone_uid'] ?? '');
        $direction  = (string) ($event['direction'] ?? '');
        $eventTs    = (int) ($event['ts'] ?? $asOf);
        $controlled = in_array($zoneUid, (array) $cfg['controlled_zones'], true);
        $trackId    = $event['assoc']['track_id'] ?? null;

        // Evidence travels with EVERY decision so a red is self-contained for human review.
        $evidence = [
            'zone_uid' => $zoneUid, 'direction' => $direction, 'qty' => $qty,
            'track_id' => $trackId !== null ? (int) $trackId : null, 'confidence' => round($conf, 3), 'ts' => $eventTs,
        ];

        // Zone→location mapping is MANDATORY. An unmapped zone can never be reconciled against the
        // ledger, so it must NOT silently fall back to the zone name as a location (that would match
        // nothing and manufacture a false red). Surfaced as a misconfiguration, never an exception.
        $map = (array) $cfg['zone_location_map'];
        if (! array_key_exists($zoneUid, $map)) {
            return self::decide(self::UNMAPPED_ZONE, self::MUTE, false, 'undetermined', null, $conf,
                'Zone "' . $zoneUid . '" is not mapped to a ledger location — fix vision_zone_location_map. Not reconciled.', [], $evidence);
        }
        $location = (string) $map[$zoneUid];

        // Attribution is soft and NEVER gates a red (two workers near one carton → movement still real).
        $cands       = (array) ($event['assoc']['candidates'] ?? []);
        $assocConf   = (float) ($event['assoc']['assoc_conf'] ?? 0.0);
        $attribution = ($assocConf >= (float) $cfg['assoc_min'] && count($cands) <= 1 && $trackId !== null)
            ? 'known' : 'undetermined';

        $notes = [];
        if (! empty($event['occlusion']))            { $notes[] = 'act_not_directly_observed'; }
        if ($conf < (float) $cfg['confidence_low'])  { $notes[] = 'low_confidence'; }
        if ($qtyConf < (float) $cfg['qty_conf_min']) { $notes[] = 'qty_uncertain'; }

        // 3a) Net-zero: an opposite-direction crossing of the same carton/track within the window cancels.
        if (self::hasNetZeroCounterpart($event, $prior, (int) $cfg['net_zero_window_seconds'])) {
            return self::decide(self::NET_ZERO, self::GREEN, false, $attribution, null, $conf,
                'Out-then-in within the window cancels — no net movement.', $notes, $evidence);
        }

        // 3b) Look for a matching ledger transaction. The window is ASYMMETRIC: a transaction may
        // precede the crossing by up to match_window, but the paperwork usually LAGS, so it may follow
        // by up to the full grace window. A symmetric ±match_window silently misses a dispatch booked
        // 6–14 min after the carton left, escalating a legitimate move to a false red.
        $before = (int) $cfg['match_window_seconds'];
        $after  = (int) $cfg['grace_seconds'];
        $sameSlot = array_values(array_filter($ledger, function ($t) use ($location, $direction, $eventTs, $before, $after) {
            $dt = ((int) ($t['ts'] ?? 0)) - $eventTs;
            return (string) ($t['location'] ?? '') === $location
                && (string) ($t['direction'] ?? '') === $direction
                && $dt >= -$before && $dt <= $after;
        }));

        foreach ($sameSlot as $t) {
            if (abs(((int) ($t['qty'] ?? 0)) - $qty) <= (int) $cfg['qty_tolerance']) {
                return self::decide(self::MATCH, self::GREEN, false, $attribution, $t, $conf,
                    'Movement matches a recorded transaction.', $notes, $evidence);
            }
        }
        // A transaction is present in the slot but the quantity disagrees → discrepancy (scenario 9).
        if ($sameSlot !== []) {
            return self::decide(self::QTY_DISCREPANCY, self::RED, true, $attribution, $sameSlot[0], $conf,
                'Quantity moved does not match the recorded transaction.', $notes, $evidence);
        }

        // 3c) No transaction. Inside grace it is simply PENDING; the vision leads the paperwork.
        if (($asOf - $eventTs) < (int) $cfg['grace_seconds']) {
            return self::decide(self::PENDING, self::AMBER, false, $attribution, null, $conf,
                'No transaction yet — within the grace window.', $notes, $evidence);
        }

        // 3d) Grace expired and still nothing. On a CONTROLLED boundary this is the headline exception.
        if ($controlled) {
            return self::decide(self::UNRECONCILED, self::RED, true, $attribution, null, $conf,
                'Movement across a controlled boundary with no matching transaction.', $notes, $evidence);
        }
        // On an internal boundary it is unexplained but lower-stakes — surfaced amber, not a red alert.
        return self::decide(self::UNRECONCILED, self::AMBER, false, $attribution, null, $conf,
            'Unexplained internal movement with no matching transaction.', $notes, $evidence);
    }

    /** Is there an opposite-direction move of the same carton/track within the window? */
    private static function hasNetZeroCounterpart(array $event, array $prior, int $window): bool
    {
        $dir   = (string) ($event['direction'] ?? '');
        $opp   = $dir === 'out' ? 'in' : ($dir === 'in' ? 'out' : '');
        if ($opp === '') { return false; }
        $track = $event['assoc']['track_id'] ?? null;
        $zone  = (string) ($event['zone_uid'] ?? '');
        $ts    = (int) ($event['ts'] ?? 0);

        foreach ($prior as $p) {
            if (! in_array((string) ($p['kind'] ?? ''), ['carton.move', 'pallet.move'], true)) { continue; }
            if ((string) ($p['direction'] ?? '') !== $opp) { continue; }
            if ((string) ($p['zone_uid'] ?? '') !== $zone) { continue; }
            // Same-carton proxy: same track id (the PoC has no per-carton identity — see limitation note).
            if ($track !== null && (($p['assoc']['track_id'] ?? null) !== $track)) { continue; }
            if (abs(((int) ($p['ts'] ?? 0)) - $ts) <= $window) { return true; }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function decide(string $result, string $severity, bool $review, string $attribution, ?array $matched, float $confidence, string $reason, array $notes, array $evidence = []): array
    {
        // A red under heavy occlusion or low confidence is still a red — but the reviewer is told.
        if ($severity === self::RED && $notes !== []) {
            $reason .= ' (' . implode(', ', $notes) . ')';
        }

        return [
            'result'          => $result,
            'severity'        => $severity,
            'review_required' => $review,
            'attribution'     => $attribution,
            'matched'         => $matched,
            'confidence'      => round($confidence, 3),
            'reason'          => $reason,
            'notes'           => $notes,
            'evidence'        => $evidence,
        ];
    }
}
