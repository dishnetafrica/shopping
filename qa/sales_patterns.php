<?php
/**
 * Framework-free QA for SalesPatternMiner — learns HOW employees sell.
 * Run: php qa/sales_patterns.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$base = __DIR__ . '/../app/Services/Bot/Discovery/';
foreach (['MessageCorpus','SalesPatternMiner'] as $c) require $base . $c . '.php';
use App\Services\Bot\Discovery\MessageCorpus as CORP;
use App\Services\Bot\Discovery\SalesPatternMiner as SPM;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

$ts = 0;
function turn(array &$rows, bool $owner, string $body): void {
    global $ts; $ts++;
    $rows[] = ['from_owner'=>$owner,'body'=>$body,'ts'=>sprintf('2024-01-01 %02d:%02d',9 + intdiv($ts,60),$ts%60),'media'=>false];
}

$rows = [];
// Qualification (spec example) x3
turn($rows,false,'Need internet');           turn($rows,true,'Home or business use?');
turn($rows,false,'I need internet at home');  turn($rows,true,'Home or business use?');
turn($rows,false,'want internet connection'); turn($rows,true,'Home or business use?');
// Upsell (spec example) x3
turn($rows,false,'Need Starlink');     turn($rows,true,'Fiber is cheaper inside Juba');
turn($rows,false,'I want Starlink');   turn($rows,true,'Fiber is cheaper inside Juba, better value');
turn($rows,false,'Starlink price?');   turn($rows,true,'fiber is cheaper for home use');
// Cross-sell x2
turn($rows,false,'I will take fiber'); turn($rows,true,'You can also add a WiFi router');
turn($rows,false,'order fiber 40');    turn($rows,true,'you can also add a router for full coverage');
// Objection handling x2
turn($rows,false,'Starlink is too expensive'); turn($rows,true,'We have a fiber plan at lower cost');
turn($rows,false,'that is too much money');     turn($rows,true,'I can offer the 20mbps plan, cheaper');
// Delivery workflow x2
turn($rows,false,'how do I get it'); turn($rows,true,'Our rider will deliver to your location tomorrow');
turn($rows,false,'delivery?');       turn($rows,true,'we deliver same day, our rider brings it');
// Order workflow x2
turn($rows,false,'how to order'); turn($rows,true,'Share your location and pay via momo');
turn($rows,false,'I want to buy'); turn($rows,true,'send your location, payment via mobile money');
// Escalation x2
turn($rows,false,'is there network in Gudele'); turn($rows,true,'Let me check and call you back');
turn($rows,false,'installation date?');          turn($rows,true,'our engineer will call you to confirm');
// Noise — generic chat that must NOT become a pattern
turn($rows,false,'hello');  turn($rows,true,'hi welcome');
turn($rows,false,'thanks'); turn($rows,true,'most welcome');

$corpus = CORP::fromRows($rows);
$res = SPM::mine($corpus);
$bt = $res['by_type'];

ok('turns reconstructed',     $res['turns_seen'] >= 18);
ok('qualification learned',   isset($bt['qualification']));
ok('qualification example',   str_contains(strtolower(json_encode($bt['qualification'] ?? [])), 'home or business'));
ok('upsell learned',          isset($bt['upsell']));
ok('upsell example',          str_contains(strtolower(json_encode($bt['upsell'] ?? [])), 'fiber is cheaper'));
ok('cross_sell learned',      isset($bt['cross_sell']));
ok('objection learned',       isset($bt['objection_handling']));
ok('delivery workflow',       isset($bt['delivery_workflow']));
ok('order workflow',          isset($bt['order_workflow']));
ok('escalation learned',      isset($bt['escalation']));
ok('escalation example',      str_contains(strtolower(json_encode($bt['escalation'] ?? [])), 'call you'));
ok('questions checklist',     in_array('Home or business use?', $res['questions'], true));
ok('summary built',           count($res['summary']) >= 6);
ok('overall confidence',      $res['confidence'] > 0 && $res['confidence'] <= 95);
ok('greeting not a pattern',  !str_contains(strtolower(json_encode($res['patterns'])), 'welcome'));
ok('counts aggregated',       ($bt['qualification']['count'] ?? 0) >= 3);
ok('upsell counts',           ($bt['upsell']['count'] ?? 0) >= 3);
ok('examples capped',         count($bt['qualification']['examples'] ?? []) <= 3);
ok('patterns flat capped',    count($res['patterns']) <= 12);

// min-count gate: single occurrence shouldn't surface
$rows2 = [];
turn($rows2,false,'one off question'); turn($rows2,true,'a unique reply that appears once only');
$res2 = SPM::mine(CORP::fromRows($rows2));
ok('single-shot gated',       empty($res2['by_type']));

echo "\n=== sales_patterns: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
