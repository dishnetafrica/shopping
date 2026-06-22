<?php
/**
 * qa/box_custody.php — proves the v6 box-level custody logic: box code generation, scan idempotency
 * (one row per box per stage), the stage→action map, and that the SCANNED count drives the existing
 * reconciliation engine (missing/extra on the exact leg). Uses the real CustodyReconciler. Run:
 *   php qa/box_custody.php
 */

require __DIR__ . '/../app/Services/Logistics/CustodyReconciler.php';
use App\Services\Logistics\CustodyReconciler;

// ---- mirror: BoxCustodyService code generation ----
function box_code(string $shipmentNumber, int $n): string { return $shipmentNumber . '-B' . $n; }

// ---- mirror: scan store (box_id+stage unique) ----
class FakeScans {
    private array $rows = []; // "boxId:stage" => true
    public function scan(int $boxId, string $stage): bool {
        $k = $boxId . ':' . $stage;
        if (isset($this->rows[$k])) return false; // already (idempotent)
        $this->rows[$k] = true; return true;
    }
    public function count(string $stage): int {
        return count(array_filter(array_keys($this->rows), fn ($k) => str_ends_with($k, ':' . $stage)));
    }
}

// ---- mirror: BoxCustodyService::STAGE_ACTION ----
const STAGE_ACTION = ['received_by_transport' => 'transport_confirm', 'arrived' => 'arrive'];

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== box_custody QA ===\n";

// box codes
check('box code SH-0001-B1', box_code('SH-0001', 1) === 'SH-0001-B1');
check('box code SH-0042-B7', box_code('SH-0042', 7) === 'SH-0042-B7');

// scan idempotency
$sc = new FakeScans();
check('first scan of box 1 @ transport counts',  $sc->scan(1, 'received_by_transport') === true);
check('re-scan box 1 @ transport is idempotent', $sc->scan(1, 'received_by_transport') === false);
$sc->scan(2, 'received_by_transport'); $sc->scan(3, 'received_by_transport'); $sc->scan(4, 'received_by_transport');
check('4 distinct boxes scanned @ transport', $sc->count('received_by_transport') === 4);
check('same box can scan again at a DIFFERENT stage', $sc->scan(1, 'arrived') === true);

// stage → action
check('received_by_transport → transport_confirm', STAGE_ACTION['received_by_transport'] === 'transport_confirm');
check('arrived → arrive', STAGE_ACTION['arrived'] === 'arrive');
check('last-mile stages are NOT in the action map', ! isset(STAGE_ACTION['collected_by_rider']) && ! isset(STAGE_ACTION['delivered']));

// scanned counts drive reconciliation: packed 5, only 4 scanned at transport -> missing 1 on that leg
$events = [
    ['stage' => 'packed', 'count' => 5],
    ['stage' => 'received_by_transport', 'count' => $sc->count('received_by_transport')], // 4
];
$ex = CustodyReconciler::reconcile($events);
check('4/5 scanned at transport → 1 missing flagged', count($ex) === 1 && $ex[0]['type'] === 'missing_boxes' && $ex[0]['delta'] === 1);
check('exception localised to packed → received_by_transport',
    $ex[0]['from_stage'] === 'packed' && $ex[0]['to_stage'] === 'received_by_transport');

// extra boxes appear at a later leg
$events2 = [
    ['stage' => 'received_by_transport', 'count' => 5],
    ['stage' => 'arrived', 'count' => 6],
];
$ex2 = CustodyReconciler::reconcile($events2);
check('6 scanned at arrival vs 5 → 1 extra flagged', count($ex2) === 1 && $ex2[0]['type'] === 'extra_boxes' && $ex2[0]['delta'] === 1);

// full clean chain — all 5 scanned at every counted stage
$clean = [
    ['stage' => 'packed', 'count' => 5],
    ['stage' => 'received_by_transport', 'count' => 5],
    ['stage' => 'arrived', 'count' => 5],
    ['stage' => 'collected_by_rider', 'count' => 5],
    ['stage' => 'delivered', 'count' => 5],
];
check('all-5 end to end → no exceptions', CustodyReconciler::reconcile($clean) === []);

// a box missing in transit STAYS missing even though the customer is still delivered the rest
$withLoss = [
    ['stage' => 'packed', 'count' => 5],
    ['stage' => 'received_by_transport', 'count' => 5],
    ['stage' => 'arrived', 'count' => 4],            // 1 lost in transit
    ['stage' => 'collected_by_rider', 'count' => 4],
    ['stage' => 'delivered', 'count' => 4],
];
$exLoss = CustodyReconciler::reconcile($withLoss);
check('transit loss flagged once on arrived leg (delivery of the rest is clean)',
    count($exLoss) === 1 && $exLoss[0]['from_stage'] === 'received_by_transport' && $exLoss[0]['to_stage'] === 'arrived');

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
