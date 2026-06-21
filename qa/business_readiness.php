<?php
/**
 * Framework-free QA for Business Readiness Certification.
 * Run: php qa/business_readiness.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Readiness/';
require $base . 'ReadinessModes.php';
require $base . 'BusinessReadinessEvaluator.php';

use App\Services\Bot\Readiness\ReadinessModes as MODES;
use App\Services\Bot\Readiness\BusinessReadinessEvaluator as EV;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/* ---- classification + mode mapping ---- */
ok('0-49 not ready',   MODES::classify(40) === MODES::NOT_READY);
ok('50-74 pilot',      MODES::classify(60) === MODES::PILOT);
ok('75-89 assisted',   MODES::classify(82) === MODES::ASSISTED);
ok('90-100 autonomous',MODES::classify(95) === MODES::AUTONOMOUS);
ok('manual mode',      MODES::modeFor(MODES::NOT_READY) === MODES::MODE_MANUAL);
ok('suggestion mode',  MODES::modeFor(MODES::PILOT) === MODES::MODE_SUGGESTION);
ok('assisted mode',    MODES::modeFor(MODES::ASSISTED) === MODES::MODE_ASSISTED);
ok('autonomous mode',  MODES::modeFor(MODES::AUTONOMOUS) === MODES::MODE_AUTONOMOUS);

/* ---- a strong, fully-approved business ---- */
$report = [
    'sections' => [
        'top_products' => array_map(fn ($i) => ['name' => "P$i", 'confidence' => 90], range(1, 12)),
        'faqs'         => array_map(fn ($t) => ['topic' => $t, 'confidence' => 88], ['hours','delivery','price','payment','location']),
        'delivery'     => ['fee' => 3000, 'free_threshold' => 50000, 'areas' => ['Kololo','Ntinda','Kira'], 'confidence' => 84],
        'promotions'   => [['detail' => '10% off'], ['detail' => 'combo']],
        'menu'         => [['day' => 'Monday']],
        'languages'    => [['lang' => 'English', 'pct' => 60], ['lang' => 'Gujlish', 'pct' => 38]],
        'hours'        => ['text' => '9am – 9pm', 'closed_days' => ['Sunday'], 'confidence' => 75],
    ],
    'confidence' => ['products'=>90,'faqs'=>88,'delivery'=>84,'hours'=>75,'language'=>80,'owner_style'=>60,'promotions'=>40,'menu'=>20,'rules'=>75],
];
$stateApproved = [
    'approved' => ['products'=>true,'faqs'=>true,'delivery'=>true,'hours'=>true,'offers'=>true,'language'=>true],
    'areas_seen' => ['Kololo','Ntinda','Kira'],
    'supported_languages' => ['English','Gujlish','Swahili','Hindi'],
    'hours_confirmed' => true,
];
$strong = EV::evaluate(2, $report, $stateApproved);
ok('category scores present', count($strong['category_scores']) === 7);
ok('products high',  $strong['category_scores']['products'] >= 90);
ok('language high',  $strong['category_scores']['language'] >= 90);
ok('approval full',  $strong['category_scores']['owner_approval'] === 100);
ok('overall high',   $strong['overall_score'] >= 85);
ok('approved → can be autonomous', in_array($strong['classification'], [MODES::ASSISTED, MODES::AUTONOMOUS], true));
ok('no missing areas (all covered)', $strong['recommendations']['missing'] === [] || count(array_filter($strong['recommendations']['missing'], fn($m)=>($m['item']??'')==='Delivery rules')) === 0);

/* ---- same data, but NOTHING approved (fresh discovery) ---- */
$stateFresh = [
    'areas_seen' => ['Kololo','Ntinda','Kira'],
    'supported_languages' => ['English','Gujlish','Swahili','Hindi'],
];
$fresh = EV::evaluate(2, $report, $stateFresh);
ok('fresh approval zero',   $fresh['category_scores']['owner_approval'] === 0);
ok('fresh overall lower',   $fresh['overall_score'] < $strong['overall_score']);
ok('fresh NOT autonomous',  $fresh['classification'] !== MODES::AUTONOMOUS);          // gate enforced
ok('fresh mode not autonomous', $fresh['recommended_mode'] !== MODES::MODE_AUTONOMOUS);
ok('fresh needs FAQ approval', (bool) array_filter($fresh['recommendations']['need_approval'], fn ($m) => $m['item'] === 'FAQs'));
ok('fresh confirms hours',  (bool) array_filter($fresh['recommendations']['need_confirmation'], fn ($m) => $m['item'] === 'Opening hours'));

/* ---- gate: even a 95+ data business stays Assisted until approved ---- */
$perfectReport = $report;
$perfectReport['confidence'] = array_fill_keys(array_keys($report['confidence']), 99);
$gate = EV::evaluate(2, $perfectReport, $stateFresh);
ok('gate caps at assisted', $gate['classification'] !== MODES::AUTONOMOUS);

/* ---- missing delivery areas surface in recommendations ---- */
$reportGap = $report;
$reportGap['sections']['delivery']['areas'] = ['Kololo'];           // owner only set Kololo
$gap = EV::evaluate(2, $reportGap, [
    'areas_seen' => ['Kololo','Ntinda','Kira'],                     // customers also from Ntinda, Kira
]);
$miss = $gap['recommendations']['missing'];
$delMiss = array_values(array_filter($miss, fn ($m) => ($m['item'] ?? '') === 'Delivery rules'));
ok('missing areas detected', $delMiss && str_contains(mb_strtolower($delMiss[0]['detail']), 'ntinda'));
ok('missing areas list kira', $delMiss && in_array('Kira', $delMiss[0]['targets'] ?? [], true));
ok('delivery coverage partial', $gap['category_scores']['delivery'] < 80 && $gap['category_scores']['delivery'] > 20);

/* ---- empty/sparse business ---- */
$empty = EV::evaluate(9, ['sections' => [], 'confidence' => []], []);
ok('empty overall low',  $empty['overall_score'] < 50);
ok('empty not ready',    $empty['classification'] === MODES::NOT_READY);
ok('empty manual mode',  $empty['recommended_mode'] === MODES::MODE_MANUAL);

/* ---- go_live_report shape + wa summary ---- */
ok('report has tenant',  $strong['tenant_id'] === 2);
ok('report has mode',    ! empty($strong['recommended_mode']));
ok('report has generated_at', ! empty($strong['generated_at']));
$wa = EV::toWhatsApp($fresh, "Pal's Snacks");
ok('wa overall',  str_contains($wa, 'Overall readiness'));
ok('wa mode',     str_contains($wa, 'Recommended'));
ok('wa not-live', str_contains($wa, 'until you pick a mode'));

echo "\n=== business_readiness: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
