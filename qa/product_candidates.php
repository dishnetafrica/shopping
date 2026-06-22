<?php
/**
 * QA — CandidateFilter (pure). Run: php qa/product_candidates.php
 *
 * Proves the product-candidate gating: existing products de-dupe, owner decisions stick,
 * normalisation matches the miner, ordering + limit behave, and garbage stays out (by virtue
 * of the miner already having filtered STOP/GENERIC — this layer only gates by dedupe).
 */

require __DIR__ . '/../app/Services/Bot/Discovery/CandidateFilter.php';

use App\Services\Bot\Discovery\CandidateFilter;

$tests = 0; $pass = 0; $fails = [];
function check($label, $cond) { global $tests, $pass, $fails; $tests++; if ($cond) { $pass++; } else { $fails[] = $label; } }

/* ---- normalize() must mirror ProductMiner::keyNorm ---- */
check('normalize lowercases', CandidateFilter::normalize('Starlink') === 'starlink');
check('normalize strips punctuation', CandidateFilter::normalize('Fiber-20Mbps!') === 'fiber 20mbps');
check('normalize collapses spaces', CandidateFilter::normalize('  Pal  s   Snack ') === 'pal s snack');
check('normalize keeps digits distinct', CandidateFilter::normalize('Fiber 40Mbps') !== CandidateFilter::normalize('Fiber 20Mbps'));
check('normalize empty', CandidateFilter::normalize('   ') === '');

/* ---- basic surfacing ---- */
$unv = [
    ['term' => 'Starlink', 'count' => 22],
    ['term' => 'Router',   'count' => 9],
    ['term' => 'Voucher',  'count' => 5],
];
$out = CandidateFilter::filter($unv, [], []);
check('all surface when nothing decided', count($out) === 3);
check('sorted by count desc', $out[0]['term'] === 'Starlink' && $out[2]['term'] === 'Voucher');

/* ---- existing product de-dupes (approved draft must not re-appear) ---- */
$out = CandidateFilter::filter($unv, ['starlink'], []);
check('existing product removed', count($out) === 2 && $out[0]['term'] === 'Router');

/* ---- owner decision (dismissed) sticks ---- */
$out = CandidateFilter::filter($unv, [], ['router']);
check('dismissed term removed', count($out) === 2 && !in_array('Router', array_column($out, 'term'), true));

/* ---- both gates combine ---- */
$out = CandidateFilter::filter($unv, ['starlink'], ['voucher']);
check('product + decision combine', count($out) === 1 && $out[0]['term'] === 'Router');

/* ---- normalisation used for matching (case/punct insensitive) ---- */
$out = CandidateFilter::filter([['term' => 'Star-Link', 'count' => 7]], ['star link'], []);
check('match is normalised not literal', count($out) === 0);

/* ---- duplicate terms in source collapse ---- */
$out = CandidateFilter::filter([
    ['term' => 'Modem', 'count' => 4],
    ['term' => 'modem', 'count' => 8],
], [], []);
check('duplicate source terms collapse to one', count($out) === 1);
check('first occurrence wins on collapse', $out[0]['term'] === 'Modem');

/* ---- limit ---- */
$big = [];
for ($i = 0; $i < 30; $i++) $big[] = ['term' => 'T' . $i, 'count' => 30 - $i];
$out = CandidateFilter::filter($big, [], [], 10);
check('limit caps output', count($out) === 10);
check('limit keeps highest counts', $out[0]['term'] === 'T0');

/* ---- empty / malformed rows ignored ---- */
$out = CandidateFilter::filter([
    ['term' => '', 'count' => 99],
    ['count' => 5],
    ['term' => 'Antenna', 'count' => 6],
], [], []);
check('blank/malformed rows dropped', count($out) === 1 && $out[0]['term'] === 'Antenna');

/* ---- empty input ---- */
check('empty unverified → empty', CandidateFilter::filter([], [], []) === []);

echo "\n=== product_candidates QA ===\n";
echo "$pass / $tests passed\n";
if ($fails) { echo "FAILED:\n - " . implode("\n - ", $fails) . "\n"; exit(1); }
echo "ALL GREEN\n";
