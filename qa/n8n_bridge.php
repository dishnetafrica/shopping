<?php
/** qa/n8n_bridge.php — pure-logic mirror of the n8n routing + bridge auth decisions. */

// ---- 1. inbound brain resolution (ProcessIncomingMessage) ----
function brain(string $botMode): string {
    if ($botMode === 'n8n') return 'n8n';
    if (in_array($botMode, ['auto', 'inbuilt'], true)) return 'inbuilt';
    return 'off';
}

// ---- 2. owner on/off toggle guard (chatBotMode) ----
function toggle(string $current, string $requested): string {
    $mode = in_array($requested, ['auto', 'off'], true) ? $requested : 'auto';
    if ($current === 'n8n' && $mode === 'auto') $mode = 'n8n';   // preserve admin's smart bot
    return $mode;
}

// ---- 3. bridge auth (BotBridgeController::authTenant) ----
function authOk(string $expected, string $given, string $botMode): bool {
    if ($expected === '' || ! hash_equals($expected, $given)) return false;
    if ($botMode !== 'n8n') return false;
    return true;
}

// ---- 4. alert_routing normalisation (normalizeRouting) ----
function normRouting(array $routing): array {
    $out = [];
    foreach ($routing as $role => $val) {
        $list = is_array($val) ? $val : preg_split('/[,\s]+/', (string) $val);
        $nums = array_values(array_filter(array_map(fn($p) => preg_replace('/[^0-9]/', '', (string) $p), $list)));
        if ($nums) $out[$role] = $nums;
    }
    return $out;
}

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== n8n_bridge QA ===\n";

check('n8n mode → n8n brain',           brain('n8n')==='n8n');
check('auto mode → inbuilt (legacy)',   brain('auto')==='inbuilt');
check('inbuilt mode → inbuilt',         brain('inbuilt')==='inbuilt');
check('off mode → off',                 brain('off')==='off');
check('unknown mode → off',             brain('monitor')==='off');

check('toggle: n8n + on stays n8n',     toggle('n8n','auto')==='n8n');
check('toggle: n8n + off → off',        toggle('n8n','off')==='off');
check('toggle: auto + on → auto',       toggle('auto','auto')==='auto');
check('toggle: auto + off → off',       toggle('auto','off')==='off');
check('toggle: junk request → auto',    toggle('auto','garbage')==='auto');

check('auth ok when secret matches + n8n', authOk('s3cret','s3cret','n8n')===true);
check('auth fails on wrong secret',        authOk('s3cret','nope','n8n')===false);
check('auth fails on empty expected',      authOk('','','n8n')===false);
check('auth fails when not n8n tenant',    authOk('s3cret','s3cret','auto')===false);

$r = normRouting(['sales'=>'256772111222, 256700333444','accounts'=>['+256 701 555 666'],'empty'=>'  ']);
check('routing splits comma string',  $r['sales']===['256772111222','256700333444']);
check('routing keeps array + strips',  $r['accounts']===['256701555666']);
check('routing drops empty role',      !isset($r['empty']));

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
