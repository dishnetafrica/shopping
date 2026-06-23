<?php
/**
 * qa/storefront_theme.php — proves per-tenant theme resolution (preset + overrides) and the spec-chip
 * parser. Mirrors StorefrontController::resolveTheme and shop.html specChipsFor. Run: php qa/storefront_theme.php
 */

function presets(): array {
    return [
        'default'   => ['accent'=>'#0C831F','premiumTiles'=>false,'specChips'=>false,'eyebrow'=>'','trustLine'=>''],
        'wholesale' => ['accent'=>'#103A8C','premiumTiles'=>true, 'specChips'=>true, 'eyebrow'=>'','trustLine'=>''],
    ];
}
/** $settings simulates tenant settings (key=>value); '' / missing = not set */
function resolve(array $settings): array {
    $p = presets();
    $t = $p[$settings['theme'] ?? 'default'] ?? $p['default'];
    foreach (['accent'=>'theme_accent','eyebrow'=>'eyebrow','trustLine'=>'trust_line'] as $k=>$s) {
        if (!empty($settings[$s])) $t[$k] = $settings[$s];
    }
    foreach (['premiumTiles'=>'premium_tiles','specChips'=>'spec_chips'] as $k=>$s) {
        if (isset($settings[$s]) && $settings[$s] !== '') $t[$k] = filter_var($settings[$s], FILTER_VALIDATE_BOOLEAN);
    }
    return $t;
}
/** mirror of JS specChipsFor */
function chips(string $name, string $kw=''): array {
    $s = strtolower($name.' '.$kw); $out = [];
    if (preg_match('/(\d)\s*-?\s*ply/', $s, $m)) $out[] = $m[1].'-Ply';
    if (preg_match('/\ba4\b/', $s)) $out[] = 'A4';
    if (preg_match('/(\d{2,4})\s*sheets?/', $s, $m)) $out[] = $m[1].' Sheets';
    if (preg_match('/(\d{2,3})\s*gsm/', $s, $m)) $out[] = $m[1].' GSM';
    if (preg_match('/virgin/', $s)) $out[] = '100% Virgin';
    if (preg_match('/thermal/', $s)) $out[] = 'Thermal';
    if (preg_match('/(\d{2,3})\s*[x×]\s*(\d{2,3})\s*mm/u', $s, $m)) $out[] = $m[1].'×'.$m[2].'mm';
    $seen=[]; $res=[]; foreach ($out as $c){ if(!isset($seen[$c])&&count($res)<3){$seen[$c]=1;$res[]=$c;} }
    return $res;
}

$pass=0;$fail=0;
function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}

echo "=== storefront_theme QA ===\n";

// resolution
$d = resolve([]);
check('no settings -> default green', $d['accent']==='#0C831F' && $d['premiumTiles']===false && $d['specChips']===false);

$w = resolve(['theme'=>'wholesale']);
check('wholesale preset -> navy + premium tiles + chips', $w['accent']==='#103A8C' && $w['premiumTiles']===true && $w['specChips']===true);

$o = resolve(['theme'=>'wholesale','theme_accent'=>'#222222','eyebrow'=>'Authorised Distributor','trust_line'=>'A · B']);
check('overrides win over preset', $o['accent']==='#222222' && $o['eyebrow']==='Authorised Distributor' && $o['trustLine']==='A · B');

$u = resolve(['theme'=>'nonsense']);
check('unknown preset falls back to default', $u['accent']==='#0C831F');

$f = resolve(['premium_tiles'=>'1']);
check('bool override on default preset', $f['premiumTiles']===true);

// chips
check('TP 150 2-ply virgin -> 3 chips', chips('Europearl Toilet Paper Virgin 150 Sheets','2 ply virgin') === ['2-Ply','150 Sheets','100% Virgin']);
check('A4 80gsm -> [A4, 80 GSM]', chips('A4 Photocopier Paper 80 GSM','a4 80gsm office') === ['A4','80 GSM']);
check('thermal 80x80 -> [Thermal, 80×80mm]', chips('Thermal Paper Roll 80 x 80 mm','thermal pos') === ['Thermal','80×80mm']);
check('cap at 3 chips', count(chips('A4 2 ply 80 gsm 150 sheets virgin thermal')) === 3);
check('plain grocery name -> no chips', chips('Fresh Tomatoes 1kg','vegetable') === []);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
