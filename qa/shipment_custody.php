<?php
/**
 * QA — CustodyReconciler (pure). Run: php qa/shipment_custody.php
 */

require __DIR__ . '/../app/Services/Logistics/CustodyReconciler.php';

use App\Services\Logistics\CustodyReconciler as CR;

$t = 0; $p = 0; $f = [];
function check($l, $c) { global $t, $p, $f; $t++; if ($c) $p++; else $f[] = $l; }

$ev = fn ($stage, $count) => ['stage' => $stage, 'count' => $count];

/* ---- clean chain: constant count end-to-end ---- */
$clean = [
    $ev('packed', 5), $ev('received_by_transport', 5), $ev('bus_departed', null),
    $ev('arrived', 5), $ev('collected_by_rider', 5), $ev('delivered', 5),
];
check('clean chain → no exceptions', CR::reconcile($clean) === []);
check('clean chain isClean', CR::isClean($clean));
check('clean chain netShortfall 0', CR::netShortfall($clean) === 0);

/* ---- a box lost between transport and arrival ---- */
$lost = [$ev('packed', 5), $ev('received_by_transport', 5), $ev('arrived', 4), $ev('delivered', 4)];
$ex = CR::reconcile($lost);
check('one discrepancy flagged', count($ex) === 1);
check('type is missing_boxes', $ex[0]['type'] === CR::MISSING);
check('localised to transport→arrival leg', $ex[0]['from_stage'] === 'received_by_transport' && $ex[0]['to_stage'] === 'arrived');
check('delta is 1', $ex[0]['delta'] === 1 && $ex[0]['expected'] === 5 && $ex[0]['got'] === 4);
check('netShortfall 1', CR::netShortfall($lost) === 1);

/* ---- extra box appears (mis-count / wrong shipment mixed in) ---- */
$extra = [$ev('packed', 5), $ev('arrived', 6)];
$ex = CR::reconcile($extra);
check('extra flagged', count($ex) === 1 && $ex[0]['type'] === CR::EXTRA);
check('extra delta 1', $ex[0]['delta'] === 1);
check('extra netShortfall 0', CR::netShortfall($extra) === 0);

/* ---- two separate losses on different legs ---- */
$two = [$ev('packed', 10), $ev('received_by_transport', 9), $ev('arrived', 7)];
$ex = CR::reconcile($two);
check('two losses flagged', count($ex) === 2);
check('first loss 10→9 packed→transport', $ex[0]['delta'] === 1 && $ex[0]['from_stage'] === 'packed');
check('second loss 9→7 transport→arrival', $ex[1]['delta'] === 2 && $ex[1]['to_stage'] === 'arrived');
check('net shortfall 3', CR::netShortfall($two) === 3);

/* ---- uncounted stages skipped, don't create phantom exceptions ---- */
$gap = [$ev('packed', 5), $ev('bus_departed', null), $ev('arrived', 5)];
check('null-count stages ignored', CR::reconcile($gap) === []);

/* ---- single / empty ---- */
check('single event no exception', CR::reconcile([$ev('packed', 5)]) === []);
check('empty no exception', CR::reconcile([]) === []);
check('empty netShortfall 0', CR::netShortfall([]) === 0);

/* ---- recovery: lost then found does NOT cancel the earlier flag ---- */
$recover = [$ev('packed', 5), $ev('arrived', 4), $ev('delivered', 5)];
$ex = CR::reconcile($recover);
check('lost-then-found keeps both legs visible', count($ex) === 2);
check('leg1 missing, leg2 extra', $ex[0]['type'] === CR::MISSING && $ex[1]['type'] === CR::EXTRA);

echo "\n=== shipment_custody QA ===\n$p / $t passed\n";
if ($f) { echo "FAILED:\n - " . implode("\n - ", $f) . "\n"; exit(1); }
echo "ALL GREEN\n";
