<?php
/**
 * QA — ShipmentStateMachine (pure). Run: php qa/shipment_state_machine.php
 */

require __DIR__ . '/../app/Services/Logistics/ShipmentStateMachine.php';

use App\Services\Logistics\ShipmentStateMachine as SM;

$t = 0; $p = 0; $f = [];
function check($l, $c) { global $t, $p, $f; $t++; if ($c) $p++; else $f[] = $l; }

/* ---- forward happy path ---- */
$r = SM::apply(SM::PACKED, 'dispatch');
check('packed→dispatch ok', $r['ok'] && $r['to'] === SM::SENT_TO_TRANSPORTER);
check('dispatch emits sent_to_transport by shop', $r['event'] === 'sent_to_transport' && $r['actor'] === SM::ACTOR_SHOP);
check('dispatch carries a count', $r['counts'] === true);

$r = SM::apply(SM::SENT_TO_TRANSPORTER, 'transport_confirm');
check('sent→transport_confirm ok', $r['ok'] && $r['to'] === SM::TRANSPORT_CONFIRMED);
check('confirm actor is transport', $r['actor'] === SM::ACTOR_TRANSPORT && $r['event'] === 'received_by_transport');

$r = SM::apply(SM::TRANSPORT_CONFIRMED, 'depart');
check('transport_confirmed→depart ok', $r['ok'] && $r['to'] === SM::IN_TRANSIT);
check('depart can carry a count (custody point)', $r['counts'] === true);

$r = SM::apply(SM::IN_TRANSIT, 'arrive');
check('in_transit→arrive ok', $r['ok'] && $r['to'] === SM::ARRIVED);
check('arrive actor is destination_agent', $r['actor'] === SM::ACTOR_AGENT);

/* ---- invalid transitions rejected (no throwing) ---- */
$r = SM::apply(SM::PACKED, 'arrive');
check('cannot skip to arrive', $r['ok'] === false && str_contains($r['error'], 'in_transit'));
$r = SM::apply(SM::ARRIVED, 'depart');
check('cannot depart after arrived', $r['ok'] === false);
$r = SM::apply(SM::PACKED, 'teleport');
check('unknown action rejected', $r['ok'] === false && str_contains($r['error'], 'unknown'));

/* ---- cancel semantics ---- */
$r = SM::apply(SM::IN_TRANSIT, 'cancel');
check('cancel mid-flow ok', $r['ok'] && $r['to'] === SM::CANCELLED);
$r = SM::apply(SM::ARRIVED, 'cancel');
check('cannot cancel arrived (terminal)', $r['ok'] === false);
$r = SM::apply(SM::CANCELLED, 'cancel');
check('cannot cancel cancelled', $r['ok'] === false);

/* ---- helpers ---- */
check('next(packed)=sent', SM::next(SM::PACKED) === SM::SENT_TO_TRANSPORTER);
check('next(arrived)=null', SM::next(SM::ARRIVED) === null);
check('arrived is terminal', SM::isTerminal(SM::ARRIVED));
check('cancelled is terminal', SM::isTerminal(SM::CANCELLED));
check('in_transit not terminal', ! SM::isTerminal(SM::IN_TRANSIT));
check('isValidStatus(packed)', SM::isValidStatus(SM::PACKED));
check('isValidStatus(bogus)=false', ! SM::isValidStatus('floating'));
check('canApply packed/dispatch', SM::canApply(SM::PACKED, 'dispatch'));
check('canApply packed/depart=false', ! SM::canApply(SM::PACKED, 'depart'));

$a = SM::actionFrom(SM::SENT_TO_TRANSPORTER);
check('actionFrom(sent)=transport_confirm', $a && $a['action'] === 'transport_confirm');

/* ---- full chain walk ---- */
$status = SM::PACKED; $steps = ['dispatch','transport_confirm','depart','arrive']; $walkOk = true;
foreach ($steps as $act) {
    $r = SM::apply($status, $act);
    if (! $r['ok']) { $walkOk = false; break; }
    $status = $r['to'];
}
check('full chain reaches arrived', $walkOk && $status === SM::ARRIVED);

echo "\n=== shipment_state_machine QA ===\n$p / $t passed\n";
if ($f) { echo "FAILED:\n - " . implode("\n - ", $f) . "\n"; exit(1); }
echo "ALL GREEN\n";
