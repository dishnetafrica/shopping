<?php declare(strict_types=1);
// Pure test of the turn-taking rule in App\Services\Sales\SalesOwnership::pickInTurn — which
// salesperson gets the next NEW customer. Framework-free.
//   php qa/sales_ownership.php
$src = (string) file_get_contents(__DIR__ . '/../app/Services/Sales/SalesOwnership.php');
// Load just the pure method without the framework: extract pickInTurn into a tiny class.
preg_match('/public static function pickInTurn\(array \$ids, int \$pointer\): array\s*\{.*?\n    \}/s', $src, $m);
if (! $m) { echo "FAILURES: pickInTurn not found\n"; exit(1); }
eval('final class Turn { ' . $m[0] . ' }');

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== new customers go to each salesperson in turn\n";
$ids = [11, 12, 13];
$p = 0; $seq = [];
for ($i = 0; $i < 7; $i++) { $r = Turn::pickInTurn($ids, $p); $seq[] = $r['id']; $p = $r['next']; }
chk('three salespeople, seven new customers', $seq, [11, 12, 13, 11, 12, 13, 11]);
chk('nobody to give it to', Turn::pickInTurn([], 5), ['id' => null, 'next' => 0]);
chk('one salesperson gets everyone', [Turn::pickInTurn([7], 0)['id'], Turn::pickInTurn([7], 3)['id']], [7, 7]);

echo "== the team changes size between customers\n";
chk('a pointer past the end wraps (someone left the team)', Turn::pickInTurn([11, 12], 5), ['id' => 12, 'next' => 0]);
chk('a negative pointer never breaks it', Turn::pickInTurn([11, 12, 13], -1), ['id' => 13, 'next' => 0]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
