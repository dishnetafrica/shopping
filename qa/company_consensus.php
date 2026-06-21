<?php
/**
 * Framework-free QA for Multi-Employee KnowledgeConsensusEngine.
 * Run: php qa/company_consensus.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../app/Services/Bot/Company/KnowledgeConsensusEngine.php';

use App\Services\Bot\Company\KnowledgeConsensusEngine as KCE;

$pass = 0; $fail = 0;
function ok(string $l, bool $c): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $l\n"; } }

/** Build a per-employee discovery-report-shaped fixture. */
function emp(string $name, array $products, array $faqs, ?int $fee, ?int $thr, array $areas, array $offers, array $style): array {
    return ['employee' => $name, 'report' => ['sections' => [
        'top_products' => array_map(fn ($p) => ['name' => $p, 'confidence' => 70], $products),
        'faqs'         => array_map(fn ($t) => ['topic' => $t, 'confidence' => 70], $faqs),
        'delivery'     => ['fee' => $fee, 'free_threshold' => $thr, 'areas' => $areas],
        'promotions'   => array_map(fn ($o) => ['detail' => $o], $offers),
        'owner_style'  => $style,
    ]]];
}

$employees = [
    emp('Asha', ['Fafda','Jalebi','Samosa'], ['hours','delivery'], 3000, 50000, ['Kololo','Ntinda'], ['10% off'],
        ['tone' => 'warm & polite', 'emoji_per_msg' => 1.2, 'greeting_rate' => 80]),
    emp('Ben',  ['Fafda','Jalebi','Dhokla'], ['hours','delivery','price'], 3000, 50000, ['Kololo'], ['10% off','combo'],
        ['tone' => 'concise & direct', 'emoji_per_msg' => 0.1, 'greeting_rate' => 20]),
    emp('Cara', ['Fafda','Jalebi'], ['hours','delivery'], 5000, 50000, ['Kololo'], [],
        ['tone' => 'casual & friendly', 'emoji_per_msg' => 2.0, 'greeting_rate' => 95]),
];

$r = KCE::consensus($employees);

/* ---- company memory: agreed facts ---- */
$cp = array_map(fn ($p) => $p['value'], $r['company_memory']['products']);
ok('Fafda is company (all 3)',  in_array('Fafda', $cp, true));
ok('Jalebi is company (all 3)', in_array('Jalebi', $cp, true));
ok('Samosa NOT company (1 emp)', ! in_array('Samosa', $cp, true));
ok('Dhokla NOT company (1 emp)', ! in_array('Dhokla', $cp, true));

$cf = array_map(fn ($f) => $f['value'], $r['company_memory']['faqs']);
ok('hours is company',  in_array('hours', $cf, true));
ok('delivery is company', in_array('delivery', $cf, true));
ok('price NOT company (1 emp)', ! in_array('price', $cf, true));

ok('Kololo is company area', (bool) array_filter($r['company_memory']['delivery']['areas'], fn ($a) => $a['value'] === 'Kololo'));
ok('Ntinda NOT company area', ! array_filter($r['company_memory']['delivery']['areas'], fn ($a) => $a['value'] === 'Ntinda'));

$co = array_map(fn ($o) => $o['value'], $r['company_memory']['offers']);
ok('10% off is company offer (2 emp)', in_array('10% off', $co, true));
ok('combo NOT company (1 emp)', ! in_array('combo', $co, true));

/* ---- delivery fee conflict (3000,3000,5000 → company 3000, conflict logged) ---- */
ok('company fee = majority 3000', ($r['company_memory']['delivery']['fee']['value'] ?? '') === '3000');
ok('fee marked contested', ! empty($r['company_memory']['delivery']['fee']['contested']));
ok('free threshold agreed (no conflict)', ($r['company_memory']['delivery']['free_threshold']['value'] ?? '') === '50000');
$feeConflict = array_filter($r['conflicts'], fn ($c) => $c['key'] === 'delivery_fee');
ok('fee conflict recorded', (bool) $feeConflict);
ok('threshold not in conflicts', ! array_filter($r['conflicts'], fn ($c) => $c['key'] === 'free_delivery_threshold'));

/* ---- employee memory: individual habits ---- */
ok('Asha unique product Samosa', in_array('Samosa', $r['employee_memory']['Asha']['unique_products'], true));
ok('Ben unique product Dhokla',  in_array('Dhokla', $r['employee_memory']['Ben']['unique_products'], true));
ok('Ben unique offer combo',     in_array('combo', $r['employee_memory']['Ben']['unique_offers'], true));
ok('Asha style polite',  $r['employee_memory']['Asha']['style']['tone'] === 'warm & polite');
ok('Ben style direct',   $r['employee_memory']['Ben']['style']['tone'] === 'concise & direct');
ok('Cara high emoji',    $r['employee_memory']['Cara']['style']['emoji_per_msg'] === 2.0);
ok('Ben upsell high (2 offers)', $r['employee_memory']['Ben']['upsell']['level'] === 'high');
ok('Cara upsell none',   $r['employee_memory']['Cara']['upsell']['level'] === 'none');

/* ---- Company DNA report sections ---- */
$rep = $r['report'];
ok('report 4 sections', isset($rep['common_company_rules'], $rep['employee_variations'], $rep['conflicting_information'], $rep['confidence_levels']));
ok('common rules has products', (bool) array_filter($rep['common_company_rules'], fn ($x) => str_contains($x, 'Fafda')));
ok('employee variations = 3', count($rep['employee_variations']) === 3);
ok('conflicting info has fee', (bool) array_filter($rep['conflicting_information'], fn ($c) => $c['fact'] === 'delivery_fee'));
ok('confidence overall set', ($rep['confidence_levels']['overall'] ?? 0) > 0);

/* ---- single-employee business: lone employee defines the company ---- */
$solo = KCE::consensus([emp('Solo', ['Rice','Sugar'], ['hours'], 2000, null, ['Kira'], ['5% off'],
    ['tone' => 'concise & direct', 'emoji_per_msg' => 0.2, 'greeting_rate' => 30])]);
ok('solo: products become company', count($solo['company_memory']['products']) === 2);
ok('solo: fee is company', ($solo['company_memory']['delivery']['fee']['value'] ?? '') === '2000');
ok('solo: no conflicts', $solo['conflicts'] === []);

/* ---- empty ---- */
$empty = KCE::consensus([]);
ok('empty safe', $empty['employee_count'] === 0 && $empty['company_memory']['products'] === []);

echo "\n=== company_consensus: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
