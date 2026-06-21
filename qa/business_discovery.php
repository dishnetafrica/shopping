<?php
/**
 * Framework-free QA for Business Discovery — export parsing, corpus, miners, report.
 * Run: php qa/business_discovery.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$base = __DIR__ . '/../app/Services/Bot/Discovery/';
foreach (['WhatsAppExportParser','MessageCorpus','ProductMiner','FaqMiner','DeliveryMiner',
          'PatternMiner','StyleProfiler','AutomationReadiness','DiscoveryReport'] as $c) {
    require $base . $c . '.php';
}

use App\Services\Bot\Discovery\WhatsAppExportParser as WX;
use App\Services\Bot\Discovery\MessageCorpus as CORP;
use App\Services\Bot\Discovery\ProductMiner as PROD;
use App\Services\Bot\Discovery\FaqMiner as FAQ;
use App\Services\Bot\Discovery\DeliveryMiner as DEL;
use App\Services\Bot\Discovery\PatternMiner as PAT;
use App\Services\Bot\Discovery\StyleProfiler as STY;
use App\Services\Bot\Discovery\AutomationReadiness as RDY;
use App\Services\Bot\Discovery\DiscoveryReport as REP;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

$export = <<<TXT
12/01/24, 9:15 AM - Owner Pal: Hello! Welcome to Pal's Snacks 😊 karibu
12/01/24, 9:16 AM - Owner Pal: We are open 9am to 9pm daily
12/01/24, 9:16 AM - Owner Pal: Closed on Sunday
12/01/24, 9:17 AM - Owner Pal: Free delivery above 50000. Delivery fee 3000 otherwise
12/01/24, 9:18 AM - Owner Pal: We deliver to Kololo, Ntinda and Bugolobi
12/01/24, 9:19 AM - Owner Pal: We accept Mpesa and cash
12/01/24, 9:20 AM - Owner Pal: Today special 10% off on fafda!
12/01/24, 9:21 AM - Owner Pal: Today's thali: dal, rice, roti, sabji
12/01/24, 9:22 AM - Owner Pal: Minimum order 10000 please
12/01/24, 10:00 AM - Amit: how much is fafda?
12/01/24, 10:01 AM - Amit: how much is fafda?
12/01/24, 10:02 AM - Sara: what time you open?
12/01/24, 10:03 AM - Sara: what time you open today?
12/01/24, 10:04 AM - Joel: do you deliver to ntinda?
12/01/24, 10:05 AM - Mary: do you deliver to kololo?
12/01/24, 10:06 AM - Mary: home delivery available?
12/01/24, 10:07 AM - Amit: I want fafda
12/01/24, 10:08 AM - Joel: send me fafda please
12/01/24, 10:09 AM - Sara: bei gani fafda
12/01/24, 10:10 AM - Mary: kitla na jalebi
12/01/24, 10:11 AM - Amit: <Media omitted>
12/01/24, 10:12 AM - Joel: this is a long one
that continues on the next line
TXT;

$rows = WX::parse($export, ['Owner Pal']);
ok('parser got messages',     count($rows) >= 20);
ok('owner attributed',        $rows[0]['from_owner'] === true);
ok('customer attributed',     $rows[9]['from_owner'] === false);
ok('media flagged',           (bool) array_filter($rows, fn ($r) => $r['media']) !== false);
$cont = array_values(array_filter($rows, fn ($r) => str_contains($r['body'], 'continues on the next line')));
ok('continuation folded',     $cont && str_contains($cont[0]['body'], 'long one'));

$corpus = CORP::fromRows($rows);
ok('corpus splits owner',     $corpus->ownerCount() >= 9);
ok('corpus splits customer',  $corpus->customerCount() >= 10);

/* products — orders are ground truth */
$orders = [
    ['items_json' => [['name' => 'Fafda 250g'], ['name' => 'Jalebi']]],
    ['items_json' => [['name' => 'Fafda'], ['name' => 'Samosa']]],
    ['items_json' => [['name' => 'Fafda']]],
];
$prods = PROD::mine($corpus, $orders);
ok('products found',          count($prods) >= 3);
ok('fafda is top product',    ($prods[0]['name'] ?? '') === 'Fafda');
ok('top product from orders', ($prods[0]['source'] ?? '') === 'orders');
ok('order product confident', ($prods[0]['confidence'] ?? 0) >= 70);

/* faqs */
$faqs = FAQ::mine($corpus);
$topics = array_column($faqs, 'topic');
ok('faq hours',    in_array('hours', $topics, true));
ok('faq delivery', in_array('delivery', $topics, true));
ok('faq price',    in_array('price', $topics, true));

/* delivery + rules */
$del = DEL::delivery($corpus);
ok('delivery fee',        ($del['fee'] ?? 0) === 3000);
ok('free threshold',      ($del['free_threshold'] ?? 0) === 50000);
ok('delivery areas',      in_array('kololo', $del['areas'] ?? [], true));
$rules = DEL::rules($corpus);
$rkinds = array_column($rules, 'rule');
ok('rule minimum order',  in_array('minimum_order', $rkinds, true));
ok('rule payment',        in_array('payment', $rkinds, true));

/* hours / promo / menu */
$hours = PAT::hours($corpus);
ok('hours text',      str_contains((string) ($hours['text'] ?? ''), '9'));
ok('closed sunday',   in_array('Sunday', $hours['closed_days'] ?? [], true));
$promos = PAT::promotions($corpus);
ok('promo discount',  (bool) array_filter($promos, fn ($p) => str_contains($p['detail'], '10%')));
$menus = PAT::menuPatterns($corpus);
ok('menu pattern',    count($menus) >= 1);

/* language + style */
$langs = STY::languages($corpus);
ok('multilingual',    count($langs) >= 2);
$style = STY::ownerStyle($corpus);
ok('owner style msgs',$style['messages'] >= 9);
ok('owner tone set',  ! empty($style['tone']));

/* readiness */
ok('readiness 0-100', RDY::score(['products'=>80,'faqs'=>70,'delivery'=>84,'hours'=>75,'language'=>70,'owner_style'=>40,'promotions'=>20,'menu'=>20,'rules'=>75]) >= 60);
ok('readiness band',  RDY::band(82) === 'Excellent — ready for high automation');
ok('low band',        RDY::band(10) !== '');

/* full report */
$report = REP::build($corpus, $orders, "Pal's Snacks");
ok('report sections',   count($report['sections']) === 11);
ok('report readiness',  $report['readiness_score'] >= 0 && $report['readiness_score'] <= 100);
ok('report band',       ! empty($report['readiness_band']));
$wa = REP::toWhatsApp($report);
ok('wa has readiness',  str_contains($wa, 'Automation readiness'));
ok('wa has products',   str_contains($wa, 'Fafda'));
ok('wa not-live note',  str_contains($wa, 'Nothing is live yet'));

echo "\n=== business_discovery: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
