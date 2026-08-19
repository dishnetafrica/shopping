<?php declare(strict_types=1);

/**
 * Pure, sandbox-runnable proof of the ShopBot Vision reconciliation engine (Phase-0 single-door slice).
 *   php tests/Vision/reconciliation_harness.php
 *
 * Covers the five demanded dispatch-door tests plus the hard scenarios from the PoC design. No Laravel,
 * no DB, no clock — time is passed in, so PENDING → MATCH is deterministic.
 */

require __DIR__ . '/../../app/Services/Vision/Reconciliation/ReconciliationEngine.php';

use App\Services\Vision\Reconciliation\ReconciliationEngine as RE;

$T   = 1_000_000;               // base "now" (unix seconds)
$CFG = [
    'controlled_zones'  => ['D->EXIT', 'X'],
    'zone_location_map' => ['D->EXIT' => 'EXIT'],
    'authorized'        => ['X' => [17]],
];

$pass = 0; $fail = 0;
function check(string $name, array $got, array $want): void {
    global $pass, $fail;
    $ok = true;
    foreach ($want as $k => $v) {
        if (($got[$k] ?? null) !== $v) { $ok = false; }
    }
    if ($ok) { $pass++; printf("  \e[32mPASS\e[0m  %-46s → %s\n", $name, (string) ($got['result'] ?? '?')); return; }
    $fail++;
    printf("  \e[31mFAIL\e[0m  %-46s\n", $name);
    foreach ($want as $k => $v) {
        $g = $got[$k] ?? null;
        if ($g === $v) { continue; }
        $vs = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        $gs = is_bool($g) ? ($g ? 'true' : 'false') : (string) $g;
        printf("          expected %s=%s, got %s\n", $k, $vs, $gs);
    }
}

function carton(string $dir, string $zone, int $ts, array $o = []): array {
    return array_replace([
        'kind' => 'carton.move', 'uid' => 'u'.$ts, 'zone_uid' => $zone, 'direction' => $dir,
        'object' => ['class' => 'carton', 'qty' => 1, 'qty_conf' => 0.95],
        'assoc' => ['track_id' => 17, 'candidates' => [17], 'assoc_conf' => 0.9],
        'confidence' => 0.9, 'occlusion' => false, 'ts' => $ts,
    ], $o);
}

echo "\nShopBot Vision — reconciliation engine (single dispatch door)\n";
echo str_repeat('─', 66) . "\n";
echo "The five acceptance tests\n";

// Test 1 — Legitimate: carton out, dispatch recorded → MATCH
check('1 · legitimate dispatch',
    RE::reconcile(carton('out','D->EXIT',$T),
        [['type'=>'dispatch','location'=>'EXIT','direction'=>'out','qty'=>1,'ts'=>$T-10]], [], $CFG, $T),
    ['result'=>RE::MATCH, 'severity'=>RE::GREEN, 'review_required'=>false]);

// Test 2 — Delayed paperwork: PENDING at T, then MATCH when the txn lands at T+120
check('2a · delayed paperwork — at crossing',
    RE::reconcile(carton('out','D->EXIT',$T), [], [], $CFG, $T),
    ['result'=>RE::PENDING, 'severity'=>RE::AMBER, 'review_required'=>false]);
check('2b · delayed paperwork — txn arrives +2min',
    RE::reconcile(carton('out','D->EXIT',$T),
        [['type'=>'dispatch','location'=>'EXIT','direction'=>'out','qty'=>1,'ts'=>$T+120]], [], $CFG, $T+120),
    ['result'=>RE::MATCH, 'severity'=>RE::GREEN, 'review_required'=>false]);

// Test 3 — Missing transaction: grace expires on a controlled boundary → UNRECONCILED
check('3 · missing transaction (grace expired)',
    RE::reconcile(carton('out','D->EXIT',$T), [], [], $CFG, $T+901),
    ['result'=>RE::UNRECONCILED, 'severity'=>RE::RED, 'review_required'=>true]);

// Test 4 — Returned carton: out then back in → NET ZERO
$out = carton('out','D->EXIT',$T);
$in  = carton('in','D->EXIT',$T+30);
check('4 · returned carton (net zero)',
    RE::reconcile($in, [], [$out], $CFG, $T+30),
    ['result'=>RE::NET_ZERO, 'severity'=>RE::GREEN, 'review_required'=>false]);

// Test 5 — Camera failure: coverage gap, explicitly NOT theft
check('5 · camera offline (coverage gap, not theft)',
    RE::reconcile(['kind'=>'camera.offline','ts'=>$T], [], [], $CFG, $T),
    ['result'=>RE::COVERAGE_GAP, 'severity'=>RE::MUTE, 'review_required'=>false]);

echo "\nHard scenarios from the PoC design\n";

// 6 — Two workers near one carton: movement still real, attribution undetermined, never auto-blamed
check('6 · two workers — movement real, who undetermined',
    RE::reconcile(carton('out','D->EXIT',$T,['assoc'=>['track_id'=>17,'candidates'=>[17,22],'assoc_conf'=>0.3]]),
        [], [], $CFG, $T+901),
    ['result'=>RE::UNRECONCILED, 'severity'=>RE::RED, 'review_required'=>true, 'attribution'=>'undetermined']);

// 7 — Occlusion during the act: still a red if unreconciled, but reviewer is told
$occ = RE::reconcile(carton('out','D->EXIT',$T,['occlusion'=>true]), [], [], $CFG, $T+901);
check('7 · occlusion — red, act not directly observed',
    $occ, ['result'=>RE::UNRECONCILED, 'severity'=>RE::RED, 'review_required'=>true]);
check('7 · occlusion note carried to reviewer',
    ['result'=> in_array('act_not_directly_observed', $occ['notes'], true) ? RE::UNRECONCILED : 'NO_NOTE'],
    ['result'=>RE::UNRECONCILED]);

// 8 — Access: authorised vs unauthorised entry to a restricted zone (no stock moved)
check('8a · restricted entry, authorised',
    RE::reconcile(['kind'=>'zone.entry','zone_uid'=>'X','assoc'=>['track_id'=>17],'confidence'=>0.9,'ts'=>$T], [], [], $CFG, $T),
    ['result'=>RE::ACCESS, 'severity'=>RE::INFO, 'review_required'=>false]);
check('8b · restricted entry, unauthorised',
    RE::reconcile(['kind'=>'zone.entry','zone_uid'=>'X','assoc'=>['track_id'=>99],'confidence'=>0.9,'ts'=>$T], [], [], $CFG, $T),
    ['result'=>RE::ACCESS_VIOLATION, 'severity'=>RE::RED, 'review_required'=>true]);

// 9 — Multiple cartons: moved 6, dispatch booked 4 → quantity discrepancy
check('9 · quantity discrepancy (moved 6, booked 4)',
    RE::reconcile(carton('out','D->EXIT',$T,['kind'=>'pallet.move','object'=>['class'=>'pallet','qty'=>6,'qty_conf'=>0.8]]),
        [['type'=>'dispatch','location'=>'EXIT','direction'=>'out','qty'=>4,'ts'=>$T]], [], $CFG, $T),
    ['result'=>RE::QTY_DISCREPANCY, 'severity'=>RE::RED, 'review_required'=>true]);

echo str_repeat('─', 66) . "\n";
printf("%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
