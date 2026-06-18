<?php
/** Gujlish snack synonyms normalise query + product to the same canonical token. */
require __DIR__ . '/../app/Services/Bot/CatalogueMatcher.php';

use App\Services\Bot\CatalogueMatcher;

$m = new CatalogueMatcher();
$pass = 0; $fail = 0;
function has(array $toks, string $w, string $l): void { global $pass,$fail; if(in_array($w,$toks,true))$pass++; else {$fail++; echo "  FAIL $l → ".implode(',',$toks)." (missing $w)\n";} }
function same(array $a, array $b, string $l): void { global $pass,$fail; sort($a); sort($b); if($a===$b)$pass++; else {$fail++; echo "  FAIL $l → ".implode(',',$a)." != ".implode(',',$b)."\n";} }

// peanut cluster
has($m->tokens('masala sing'), 'peanut', 'masala sing → peanut');
has($m->tokens('mungfali'), 'peanut', 'mungfali → peanut');

// End-to-end: "masala sing" must resolve a product named "Masala Peanuts" (plural) via the
// full search() pipeline (synonym + Damerau bridges peanut/peanuts).
function resolves(CatalogueMatcher $m, string $q, string $name): bool {
    $res = $m->search($q, [['id'=>1,'name'=>$name,'keywords'=>'','category'=>'Namkeen','price'=>5000]]);
    return ! empty($res) && ($res[0]['product']['id'] ?? null) === 1;
}
global $pass,$fail;
foreach ([
    ['masala sing','Masala Peanuts','sing → Masala Peanuts'],
    ['golgappa','Panipuri','golgappa → Panipuri'],
    ['ghathiya','Ganthiya','ghathiya → Ganthiya'],
    ['bundi','Boondi','bundi → Boondi'],
    ['chiwda','Chevdo','chiwda → Chevdo'],
] as [$q,$name,$lbl]) {
    if (resolves($m,$q,$name)) { $pass++; } else { $fail++; echo "  FAIL search: $lbl\n"; }
}

// ganthiya cluster — all spellings collapse together
same($m->tokens('gathiya'), $m->tokens('ghathiya'), 'gathiya == ghathiya');
same($m->tokens('ganthia'), $m->tokens('gathiya'), 'ganthia == gathiya');

// panipuri / golgappa
has($m->tokens('golgappa'), 'panipuri', 'golgappa → panipuri');
has($m->tokens('gupchup'), 'panipuri', 'gupchup → panipuri');

// others
has($m->tokens('bundi'), 'boondi', 'bundi → boondi');
has($m->tokens('shev'), 'sev', 'shev → sev');
has($m->tokens('chiwda'), 'chevdo', 'chiwda → chevdo');
has($m->tokens('kachauri'), 'kachori', 'kachauri → kachori');
has($m->tokens('khakra'), 'khakhra', 'khakra → khakhra');
has($m->tokens('murmura'), 'mamra', 'murmura → mamra');

// existing grocery synonyms still work (no regression)
has($m->tokens('kaju'), 'cashew', 'kaju → cashew');
has($m->tokens('chokha'), 'rice', 'chokha → rice');

// a non-synonym word passes through unchanged
has($m->tokens('boondi'), 'boondi', 'boondi unchanged');

echo "gujlish_synonyms: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
