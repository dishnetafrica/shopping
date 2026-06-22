<?php

namespace App\Services\Logistics;

use App\Models\Shipment;
use App\Models\ShipmentBox;
use App\Models\ShipmentBoxScan;
use App\Services\Logistics\ShipmentStateMachine as SM;
use Illuminate\Support\Collection;

/**
 * Shipment Platform v6 — box-level custody. Generates one box record per physical box, records a scan
 * per box per stage, and finalises a stage using the SCANNED count (no manual entry) — which feeds the
 * existing reconciliation engine to flag missing / extra boxes on the exact leg.
 *
 * Stages map onto the existing custody chain:
 *   received_by_transport → transport_confirm (state machine)
 *   arrived               → arrive             (state machine)
 *   collected_by_rider    → last-mile custody  (recordCustody)
 *   delivered             → last-mile custody  (recordCustody)
 */
class BoxCustodyService
{
    public const STAGES = ['received_by_transport', 'arrived', 'collected_by_rider', 'delivered'];

    /** stage → transport-leg action (state machine). Last-mile stages aren't here — they use recordCustody. */
    private const STAGE_ACTION = [
        'received_by_transport' => 'transport_confirm',
        'arrived'               => 'arrive',
    ];

    public function __construct(protected ShipmentService $shipments) {}

    /** Ensure box rows 1..boxes_sent exist for a shipment. Idempotent. Returns the box collection. */
    public function syncBoxes(Shipment $shipment): Collection
    {
        $n = (int) ($shipment->boxes_sent ?? 0);
        for ($i = 1; $i <= $n; $i++) {
            ShipmentBox::firstOrCreate(
                ['shipment_id' => $shipment->id, 'box_number' => $i],
                ['tenant_id' => $shipment->tenant_id, 'code' => $shipment->shipment_number . '-B' . $i]
            );
        }
        return $this->boxes($shipment);
    }

    public function boxes(Shipment $shipment): Collection
    {
        return ShipmentBox::where('shipment_id', $shipment->id)->orderBy('box_number')->get();
    }

    public function total(Shipment $shipment): int
    {
        return ShipmentBox::where('shipment_id', $shipment->id)->count();
    }

    public function scannedCount(Shipment $shipment, string $stage): int
    {
        return ShipmentBoxScan::where('shipment_id', $shipment->id)->where('stage', $stage)->count();
    }

    public function scannedNumbers(Shipment $shipment, string $stage): array
    {
        return ShipmentBoxScan::where('shipment_id', $shipment->id)->where('stage', $stage)
            ->join('shipment_boxes', 'shipment_boxes.id', '=', 'shipment_box_scans.box_id')
            ->orderBy('shipment_boxes.box_number')
            ->pluck('shipment_boxes.box_number')->all();
    }

    /** Record one box scan at a stage. Idempotent per (box, stage). */
    public function scan(Shipment $shipment, string $code, string $stage, string $actor, ?string $actorName = null): array
    {
        if (! in_array($stage, self::STAGES, true)) {
            return ['ok' => false, 'error' => 'Unknown stage.'];
        }
        $code = trim($code);
        $box = ShipmentBox::where('shipment_id', $shipment->id)->where('code', $code)->first();
        if (! $box) {
            // tolerate a bare box number being typed/scanned
            if (ctype_digit($code)) {
                $box = ShipmentBox::where('shipment_id', $shipment->id)->where('box_number', (int) $code)->first();
            }
        }
        if (! $box) {
            return ['ok' => false, 'error' => 'That box is not part of this shipment.', 'code' => $code];
        }

        $existing = ShipmentBoxScan::where('box_id', $box->id)->where('stage', $stage)->first();
        $already  = (bool) $existing;
        if (! $already) {
            ShipmentBoxScan::create([
                'tenant_id'  => $shipment->tenant_id,
                'shipment_id'=> $shipment->id,
                'box_id'     => $box->id,
                'stage'      => $stage,
                'actor'      => $actor,
                'actor_name' => $actorName,
                'scanned_at' => now(),
            ]);
        }

        return [
            'ok'         => true,
            'box_number' => $box->box_number,
            'already'    => $already,
            'scanned'    => $this->scannedCount($shipment, $stage),
            'total'      => $this->total($shipment),
        ];
    }

    /**
     * Finalise a stage using the scanned count. Transport-leg stages advance the state machine
     * (which appends the custody event + runs reconciliation); last-mile stages use recordCustody.
     */
    public function finalize(Shipment $shipment, string $stage, string $actor, ?string $actorName, array $payload = []): array
    {
        $scanned = $this->scannedCount($shipment, $stage);
        $payload = array_merge($payload, ['box_count' => $scanned, 'actor_name' => $actorName]);

        if (isset(self::STAGE_ACTION[$stage])) {
            $res = $this->shipments->recordAction($shipment, self::STAGE_ACTION[$stage], $payload);
        } else {
            $res = $this->shipments->recordCustody($shipment, $stage, $actor, $scanned, $payload);
            $res['status'] = $shipment->status;
        }

        $res['scanned'] = $scanned;
        $res['total']   = $this->total($shipment);
        return $res;
    }
}
