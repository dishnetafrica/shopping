<?php
/**
 * Framework-free QA for Field Validation program metrics.
 * Run: php qa/field_validation.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = __DIR__ . '/../app/Services/Bot/';
require $root . 'Validation/ValidationComparator.php';
require $root . 'Validation/FieldMetrics.php';

use App\Services\Bot\Validation\ValidationComparator as CMP;
use App\Services\Bot\Validation\FieldMetrics as FM;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* edits derived from a comparison */
$sections = [
    'top_products' => array_map(fn ($n) => ['name' => $n], ['Fafda', 'Jalebi', 'Samosa', 'WrongItem']),
    'faqs'         => array_map(fn ($t) => ['topic' => $t], ['hours', 'delivery', 'price']),
    'delivery'     => ['areas' => ['Kololo', 'Ntinda']],
    'languages'    => [['lang' => 'English'], ['lang' => 'Gujlish']],
    'promotions'   => [['detail' => '10% off']],
];
$actual = [
    'products'       => ['Fafda', 'Jalebi', 'Samosa', 'Dhokla'],   // detected 4 (1 wrong), missed Dhokla
    'faqs'           => ['hours', 'delivery', 'price'],
    'delivery_areas' => ['Kololo', 'Ntinda'],
    'languages'      => ['English', 'Gujlish'],
    'offers'         => 1,
    'readiness'      => 80,
];
$m = CMP::compare($sections, $actual, 80);
$edits = FM::editsFromMetrics($m);
ok('edits = 1 wrong + 1 missing product', $edits === 2);
$gt = FM::groundTruthSize($m);
ok('ground-truth size', $gt === (4 + 3 + 2 + 2));
$corr = FM::correctionsPct($edits, $gt);
ok('corrections pct', $corr === (int) round(2 / 11 * 100));      // ~18%
ok('corrections div0 safe', FM::correctionsPct(0, 0) === 0);

/* a perfect business → 0 edits */
$perfectSections = [
    'top_products' => array_map(fn ($n) => ['name' => $n], ['Fafda', 'Jalebi', 'Samosa']),
    'faqs'         => array_map(fn ($t) => ['topic' => $t], ['hours', 'delivery']),
    'delivery'     => ['areas' => ['Kololo']],
    'languages'    => [['lang' => 'English']],
];
$pm = CMP::compare($perfectSections, ['products' => ['Fafda','Jalebi','Samosa'], 'faqs' => ['hours','delivery'], 'delivery_areas' => ['Kololo'], 'languages' => ['English'], 'offers' => 0, 'readiness' => 80], 80);
ok('perfect → 0 edits', FM::editsFromMetrics($pm) === 0);

/* ---- simulate a 20-business cohort (5 each × 4 types) that MEETS criteria ---- */
$types = ['snack', 'restaurant', 'pharmacy', 'hardware'];
$cohort = [];
foreach ($types as $t) {
    for ($i = 0; $i < 5; $i++) {
        $cohort[] = [
            'business_type'          => $t,
            'actual_accuracy'        => 86 + ($i % 3),
            'owner_approved_accuracy'=> 88 + ($i % 3),
            'time_to_go_live_min'    => 14 + $i * 2,         // 14..22
            'owner_corrections_pct'  => 10 + $i,             // 10..14
        ];
    }
}
$sum = FM::summary($cohort);
ok('cohort size 20',        $sum['businesses'] === 20);
ok('avg accuracy >= 80',    $sum['avg_accuracy'] >= 80);
ok('avg time <= 30',        $sum['avg_time_to_go_live'] <= 30);
ok('avg corrections <= 20', $sum['avg_corrections'] <= 20);
ok('meets all criteria',    $sum['meets_criteria'] === true);
ok('criteria accuracy pass',$sum['criteria']['accuracy']['pass'] === true);
ok('criteria time pass',    $sum['criteria']['time']['pass'] === true);
ok('criteria corrections pass', $sum['criteria']['corrections']['pass'] === true);
ok('by-type has 4 types',   count($sum['by_type']) === 4);
ok('by-type counts',        $sum['by_type']['snack']['businesses'] === 5);

$verdict = FM::verdict($sum);
ok('verdict yes',           $verdict['can_go_operational'] === true);
ok('verdict statement',     str_contains($verdict['statement'], 'Yes'));

/* ---- a cohort that FAILS time criterion ---- */
$slow = array_map(function ($r) { $r['time_to_go_live_min'] = 45; return $r; }, $cohort);
$sumSlow = FM::summary($slow);
ok('slow fails criteria',   $sumSlow['meets_criteria'] === false);
ok('slow time criterion fails', $sumSlow['criteria']['time']['pass'] === false);
ok('slow accuracy still ok', $sumSlow['criteria']['accuracy']['pass'] === true);
ok('verdict no',            FM::verdict($sumSlow)['can_go_operational'] === false);

/* ---- a cohort that FAILS corrections (too many edits) ---- */
$messy = array_map(function ($r) { $r['owner_corrections_pct'] = 35; return $r; }, $cohort);
ok('messy fails corrections', FM::summary($messy)['criteria']['corrections']['pass'] === false);

echo "\n=== field_validation: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
