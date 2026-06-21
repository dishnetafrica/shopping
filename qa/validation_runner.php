<?php
/**
 * Platform Validation — run the real onboarding pipeline against all five business types and
 * measure discovery accuracy. Run: php qa/validation_runner.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = __DIR__ . '/../app/Services/Bot/';
foreach ([
    'Discovery/WhatsAppExportParser','Discovery/MessageCorpus','Discovery/ProductMiner','Discovery/FaqMiner',
    'Discovery/DeliveryMiner','Discovery/PatternMiner','Discovery/StyleProfiler','Discovery/AutomationReadiness',
    'Discovery/DiscoveryReport','Readiness/ReadinessModes','Readiness/BusinessReadinessEvaluator',
    'Validation/ValidationComparator','Validation/ValidationRunner','Validation/ValidationFixtures',
] as $c) require $root . $c . '.php';

use App\Services\Bot\Validation\ValidationFixtures as FIX;
use App\Services\Bot\Validation\ValidationRunner as RUN;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

$rows = [];
printf("\n%-11s %5s %5s %5s %6s %6s %6s %5s\n", 'TYPE', 'MSGS', 'PROD', 'FAQ', 'PROD%', 'FAQ%', 'READY', 'ACC');
printf("%s\n", str_repeat('-', 56));

foreach (FIX::all() as $type => $fx) {
    $r = RUN::run($type, $fx['export'], $fx['actual'], $fx['owner'], $fx['orders']);
    $rows[] = $r;
    printf("%-11s %5d %5d %5d %5d%% %5d%% %5d%% %4d%%\n",
        $type, $r['messages_scanned'], $r['products_found'], $r['faq_found'],
        $r['products_discovery_pct'], $r['faq_discovery_pct'], $r['readiness_score'], $r['accuracy_score']);

    ok("$type scanned messages",     $r['messages_scanned'] >= 18);
    ok("$type found products",       $r['products_found'] >= 4);
    ok("$type product recall >=60",  $r['products_discovery_pct'] >= 60);
    ok("$type faq recall >=60",      $r['faq_discovery_pct'] >= 60);
    ok("$type accuracy >=60",        $r['accuracy_score'] >= 60);
    ok("$type readiness 50-95",      $r['readiness_score'] >= 50 && $r['readiness_score'] <= 95);
    ok("$type delivery rules found", $r['delivery_rules_found'] >= 2);
    ok("$type has mode",             ! empty($r['recommended_mode']));
}

/* aggregate accuracy report */
$avgAcc   = (int) round(array_sum(array_column($rows, 'accuracy_score')) / count($rows));
$avgReady = (int) round(array_sum(array_column($rows, 'readiness_score')) / count($rows));
$avgProd  = (int) round(array_sum(array_column($rows, 'products_discovery_pct')) / count($rows));
$avgFaq   = (int) round(array_sum(array_column($rows, 'faq_discovery_pct')) / count($rows));
$goLive   = count(array_filter($rows, fn ($r) => $r['can_go_live']));

printf("%s\n", str_repeat('-', 56));
printf("%-11s %5s %5s %5s %5d%% %5d%% %5d%% %4d%%\n", 'AVG', '', '', '', $avgProd, $avgFaq, $avgReady, $avgAcc);
echo "\nReadiness in 70-90 band: " . count(array_filter($rows, fn ($r) => $r['readiness_score'] >= 70 && $r['readiness_score'] <= 90)) . "/" . count($rows) . "\n";
echo "Can go live (>=70%): {$goLive}/" . count($rows) . "\n";

ok('avg accuracy >=65',        $avgAcc >= 65);
ok('avg product recall >=65',  $avgProd >= 65);
ok('avg faq recall >=65',      $avgFaq >= 65);
ok('most reach go-live band',  $goLive >= 4);

echo "\n=== validation_runner: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
