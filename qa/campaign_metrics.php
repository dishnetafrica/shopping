<?php
/**
 * Framework-free QA for Platform Validation Campaign analytics.
 * Run: php qa/campaign_metrics.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = __DIR__ . '/../app/Services/Bot/Validation/';
require $root . 'ValidationComparator.php';
require $root . 'FieldMetrics.php';
require $root . 'CampaignMetrics.php';

use App\Services\Bot\Validation\CampaignMetrics as CM;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* category accuracy extraction from a metrics array */
$metrics = [
    'products'  => ['recall' => 90],
    'faqs'      => ['recall' => 80],
    'delivery'  => ['recall' => 70],
    'languages' => ['recall' => 100],
    'offers'    => ['accuracy' => 60],
];
$ca = CM::categoryAccuracy($metrics);
ok('cat products', $ca['products'] === 90);
ok('cat offers', $ca['offers'] === 60);

/* pearson sanity */
ok('pearson perfect+', abs(CM::pearson([1,2,3,4], [2,4,6,8]) - 1.0) < 1e-9);
ok('pearson perfect-', abs(CM::pearson([1,2,3,4], [8,6,4,2]) + 1.0) < 1e-9);
ok('pearson flat', CM::pearson([1,1,1], [2,2,2]) === 0.0);

/* ---- build a 40-business cohort (10 each × 4 types) with engineered structure ----
   snack = easiest (high accuracy, low corrections), hardware = hardest (most corrections).
   accuracy is made to track messages so messages_scanned is the strongest predictor. */
$types = [
    'snack'      => ['acc' => 92, 'corr' => 8,  'time' => 16, 'ready' => 84],
    'restaurant' => ['acc' => 86, 'corr' => 14, 'time' => 20, 'ready' => 80],
    'pharmacy'   => ['acc' => 83, 'corr' => 16, 'time' => 22, 'ready' => 78],
    'hardware'   => ['acc' => 80, 'corr' => 24, 'time' => 26, 'ready' => 76],
];
$rows = [];
foreach ($types as $type => $p) {
    for ($i = 0; $i < 10; $i++) {
        $acc = $p['acc'] + ($i - 5);                 // spread around the mean
        $rows[] = [
            'business_type'           => $type,
            'owner_approved_accuracy' => $acc,
            'owner_corrections_pct'   => $p['corr'],
            'time_to_go_live_min'     => $p['time'],
            'readiness_score'         => $p['ready'],
            'messages_scanned'        => 100 + $acc,  // messages track accuracy → strongest predictor
            'products_found'          => 6,           // constant → no correlation
            'faq_found'               => 5,           // constant → no correlation
            'delivery_rules_found'    => 3,           // constant → no correlation
            'products_accuracy'       => $acc,
            'faq_accuracy'            => $acc - 4,
            'delivery_accuracy'       => $acc - 10,
            'offer_accuracy'          => $acc - 2,
            'language_accuracy'       => 98,
        ];
    }
}

$board = CM::leaderboard($rows);
ok('leaderboard 4 types', count($board) === 4);
ok('snack easiest (rank 1)', $board[0]['business_type'] === 'snack');
ok('hardware hardest (rank last)', end($board)['business_type'] === 'hardware');
ok('each type 10 businesses', $board[0]['businesses'] === 10);
ok('ease score descending', $board[0]['ease_score'] >= $board[1]['ease_score']);

$q = CM::questions($rows);
ok('Q1 easiest = snack',          $q['easiest_type'] === 'snack');
ok('Q2 most corrections = hardware', $q['most_corrections_type'] === 'hardware');
ok('Q3 messages needed > 0',      $q['messages_needed'] > 0);
ok('Q4 avg readiness ~80',        $q['avg_readiness'] >= 75 && $q['avg_readiness'] <= 85);
ok('Q5 best predictor = messages',$q['best_predictor']['feature'] === 'messages_scanned');
ok('Q5 predictor strong',         $q['best_predictor']['correlation'] >= 0.9);
ok('Q5 ranking has 4 features',   count($q['predictor_ranking']) === 4);

/* category averages */
$avg = CM::categoryAverages($rows);
ok('cat avg products present',  $avg['products_accuracy'] > 0);
ok('cat avg language high',     $avg['language_accuracy'] === 98);

/* monthly report */
$report = CM::monthlyReport($rows, '2026-06');
ok('report period',        $report['period'] === '2026-06');
ok('report 40 businesses', $report['businesses'] === 40);
ok('report leaderboard',   count($report['leaderboard']) === 4);
ok('report criteria',      isset($report['criteria']['accuracy']));
ok('report verdict',       isset($report['verdict']['can_go_operational']));
ok('report meets criteria',$report['meets_criteria'] === true);   // 80-92 acc, ≤24 corr, ≤26 min
ok('report category avg',  isset($report['category_avg']['products_accuracy']));

echo "\n=== campaign_metrics: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
