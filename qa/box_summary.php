<?php
/**
 * qa/box_summary.php — proves the v6.1 batch-scan summary: expected/scanned/missing/extra counts, the
 * specific missing box codes, progress %, and the "furthest scanned stage" pick for the panel.
 * Mirrors BoxCustodyService::summary() logic. Run: php qa/box_summary.php
 */

function codes(string $sh, int $total): array {
    $c = []; for ($i = 1; $i <= $total; $i++) $c[$i] = $sh . '-B' . $i; return $c; // number => code
}

/** @param int[] $scannedNums  box numbers scanned at the stage */
function summary(string $sh, int $total, array $scannedNums): array {
    $scannedNums = array_values(array_unique($scannedNums));
    $scanned = count($scannedNums);
    $all = codes($sh, $total);
    $missingCodes = [];
    foreach ($all as $n => $code) if (! in_array($n, $scannedNums, true)) $missingCodes[] = $code;
    return [
        'expected'      => $total,
        'scanned'       => $scanned,
        'missing'       => max(0, $total - $scanned),
        'extra'         => max(0, $scanned - $total),
        'missing_codes' => $missingCodes,
        'pct'           => $total > 0 ? (int) round($scanned / $total * 100) : 0,
    ];
}

function furthestScanned(array $scannedByStage): ?string {
    foreach (['delivered', 'collected_by_rider', 'arrived', 'received_by_transport'] as $st)
        if (($scannedByStage[$st] ?? 0) > 0) return $st;
    return null;
}

$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  XX  $l\n"; } }

echo "=== box_summary QA ===\n";

// 3 of 5 scanned: B1,B2,B3 → missing B4,B7? (here 1..5, missing B4,B5)
$u = summary('SH-0001', 5, [1, 2, 3]);
check('expected 5',  $u['expected'] === 5);
check('scanned 3',   $u['scanned'] === 3);
check('missing 2',   $u['missing'] === 2);
check('extra 0',     $u['extra'] === 0);
check('missing codes are SH-0001-B4, SH-0001-B5', $u['missing_codes'] === ['SH-0001-B4', 'SH-0001-B5']);
check('progress 60%', $u['pct'] === 60);

// non-contiguous missing (the spec example shape: B4 and B7 missing of 7)
$u2 = summary('SH-0001', 7, [1, 2, 3, 5, 6]);
check('7-box, B4+B7 missing → codes match', $u2['missing_codes'] === ['SH-0001-B4', 'SH-0001-B7']);
check('7-box missing count 2', $u2['missing'] === 2);

// duplicate scans don't inflate the count
$u3 = summary('SH-0009', 4, [1, 1, 2, 2, 2, 3]);
check('dupes ignored → scanned 3 of 4', $u3['scanned'] === 3 && $u3['missing'] === 1);

// all scanned → clean, no missing codes
$u4 = summary('SH-0002', 4, [1, 2, 3, 4]);
check('all 4 scanned → missing 0, no codes, 100%', $u4['missing'] === 0 && $u4['missing_codes'] === [] && $u4['pct'] === 100);

// furthest scanned stage selection for the panel
check('picks arrived over transport when both scanned',
    furthestScanned(['received_by_transport' => 5, 'arrived' => 4, 'collected_by_rider' => 0, 'delivered' => 0]) === 'arrived');
check('picks transport when only it has scans',
    furthestScanned(['received_by_transport' => 2, 'arrived' => 0, 'collected_by_rider' => 0, 'delivered' => 0]) === 'received_by_transport');
check('null when nothing scanned',
    furthestScanned(['received_by_transport' => 0, 'arrived' => 0, 'collected_by_rider' => 0, 'delivered' => 0]) === null);

echo "\n$pass / " . ($pass + $fail) . " passed\n";
echo $fail === 0 ? "ALL GREEN\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
