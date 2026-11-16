<?php declare(strict_types=1);
// Pure test of the Internet-provider quotation: App\Services\Isp\IspQuote (one-time vs monthly
// grouping and totals) and App\Services\Isp\IspQuoteLayout (the page). Framework-free.
// Figures here are made-up test numbers, not DishNet prices.
//   php qa/isp_quote.php            (add --html=/path/file.html to write the sample page)
require __DIR__ . '/../app/Services/Isp/IspQuote.php';
require __DIR__ . '/../app/Services/Isp/IspQuoteLayout.php';

use App\Services\Isp\IspQuote as Q;
use App\Services\Isp\IspQuoteLayout as L;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}
// Lines as QuotationService::priced() leaves them: _unit (ex-VAT unit price), _sub (after discount).
$line = fn (string $name, int $qty, float $unit, float $disc = 0) => ['name' => $name, 'qty' => $qty, 'price' => $unit, 'matched' => true,
    '_unit' => $unit, '_sub' => round($qty * $unit * (1 - $disc / 100), 2), 'unit' => ''];
$products = [
    'test kit a'        => ['billing' => 'one_off', 'service_line' => 'starlink'],
    'test plan monthly' => ['billing' => 'monthly', 'service_line' => 'starlink'],
    'test install'      => ['billing' => null,      'service_line' => null],
    'test fibre plan'   => ['billing' => 'monthly', 'service_line' => 'fiber'],
];

echo "== what is paid once and what is paid every month\n";
$s = Q::split([$line('Test Kit A', 1, 100), $line('Test Plan Monthly', 1, 50), $line('Test Install', 1, 30), ['name' => 'unknown', 'matched' => false]], $products);
chk('kit and installation are one-time; the plan is monthly', [array_column($s['one_off'], 'name'), array_column($s['monthly'], 'name')], [['Test Kit A', 'Test Install'], ['Test Plan Monthly']]);
chk('one-time total', $s['one_off_total']['total'], 130.0);
chk('monthly total, never added to the one-time total', $s['monthly_total']['total'], 50.0);
chk('a product not marked monthly is a one-time charge', $s['one_off'][1]['billing'], 'one_off');
chk('an unmatched line is not quoted', count($s['one_off']) + count($s['monthly']), 3);
chk('the service is Starlink', $s['service_lines'], ['starlink']);
chk('a product whose name has different case is still recognised', Q::split([$line('TEST PLAN MONTHLY', 1, 50)], $products)['monthly'][0]['name'], 'TEST PLAN MONTHLY');

echo "== discount and tax stay within each group\n";
$s2 = Q::split([$line('Test Kit A', 2, 100, 10), $line('Test Plan Monthly', 1, 50)], $products, 0.18);
chk('one-time: 2 × 100 less 10% = 180, tax 32.40', [$s2['one_off_total']['before'], $s2['one_off_total']['discount'], $s2['one_off_total']['subtotal'], $s2['one_off_total']['vat'], $s2['one_off_total']['total']], [200.0, 20.0, 180.0, 32.4, 212.4]);
chk('monthly: 50, tax 9', [$s2['monthly_total']['subtotal'], $s2['monthly_total']['total']], [50.0, 59.0]);
chk('a quotation for both services carries both badges', Q::split([$line('Test Kit A', 1, 1), $line('Test Fibre Plan', 1, 1)], $products)['service_lines'], ['fiber', 'starlink']);
chk('no service known: no badge', Q::split([$line('Test Install', 1, 1)], $products)['service_lines'], []);

echo "== the page\n";
$doc = [
    'company' => 'DishNet Africa Limited', 'address_lines' => ['Plot 1, Example Road', 'Juba, South Sudan'], 'phone' => '+211 900 000 000',
    'email' => 'sales@example.com', 'website' => 'www.example.com', 'tagline' => 'Of course we can ...',
    'quote_no' => 'QUO-2026-0001', 'date' => '16 Nov 2026', 'valid_until' => '23 Nov 2026', 'currency' => 'USD',
    'customer' => ['name' => 'Sample Customer <b>', 'company' => 'Sample Co', 'address_lines' => ['Hai Malakal'], 'phone' => '+211 911 000 001'],
    'service_lines' => $s['service_lines'], 'split' => $s, 'terms' => [],
    'prepared_by' => ['name' => 'Akol Deng', 'phone' => '+211 922 000 001'], 'notes' => '',
];
$h = L::html($doc);
chk('one-time and monthly are separate tables with their own totals', [str_contains($h, 'One-time total'), str_contains($h, 'Monthly total'), str_contains($h, 'Total Due')], [true, true, false]);
chk('the STARLINK badge shows', str_contains($h, '>STARLINK<'), true);
chk('the real expiry date is printed, not a fixed number of days', [str_contains($h, 'Valid until <strong>23 Nov 2026</strong>'), str_contains($h, '7 days')], [true, false]);
chk('prepared by the salesperson, with their phone in the footer', [str_contains($h, 'Akol Deng'), str_contains($h, 'Sales: +211 922 000 001'), str_contains($h, 'Yash')], [true, true, false]);
chk('customer text is escaped', [str_contains($h, 'Sample Customer &lt;b&gt;'), str_contains($h, 'Sample Customer <b>')], [true, false]);
chk('no published terms: no terms section and no invented terms', [str_contains($h, 'agree to the above terms'), stripos($h, 'Kampala'), stripos($h, 'excise')], [false, false, false]);
$withTerms = L::html(['terms' => [['title' => 'Starlink service — terms and conditions', 'items' => [['label' => 'Payment', 'text' => 'Test term one'], ['label' => 'Contract', 'text' => 'Test term two']]]]] + $doc);
chk('published terms print, numbered, with the acceptance line', [str_contains($withTerms, '1. Payment:'), str_contains($withTerms, '2. Contract:'), str_contains($withTerms, 'agree to the above terms')], [true, true, true]);
chk('no monthly lines: no monthly table', str_contains(L::html(['split' => Q::split([$line('Test Kit A', 1, 100)], $products)] + $doc), 'Monthly charges'), false);
chk('nobody named: an empty signature line, never a default name', str_contains(L::html(['prepared_by' => []] + $doc), '______________________</span>'), true);

foreach ($argv as $a) {
    if (str_starts_with($a, '--html=')) {
        file_put_contents(substr($a, 7), L::html(['terms' => [['title' => 'Terms and conditions (sample — not approved text)', 'items' => [['label' => 'Sample', 'text' => 'Approved terms from the Sales Brain print here.'], ['label' => 'Sample', 'text' => 'Only published versions are used.'], ['label' => 'Sample', 'text' => 'Nothing prints until management approves it.']]]]] + $doc));
    }
}

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
