<?php declare(strict_types=1);
// Pure test of DishNet management's decisions (App\Services\Sales\Brain\DishNetDecisions) applied to the
// exact imported drafts: every open question gets exactly one decided answer, every edit finds its text,
// and the published wording says what was decided — nothing more. Framework-free.
//   php qa/dishnet_decisions.php
foreach (['Delivery/ZoneResolver', 'Travel/Conversation/ReplyGuard', 'Sales/Training/Assessment', 'Sales/Brain/Knowledge', 'Sales/Brain/TemplateTerms',
    'Sales/Brain/DishNetBotImport', 'Sales/Brain/DishNetDecisions'] as $f) {
    require __DIR__ . "/../app/Services/{$f}.php";
}

use App\Services\Sales\Brain\DishNetBotImport as D;
use App\Services\Sales\Brain\DishNetDecisions as X;
use App\Services\Sales\Brain\Knowledge as K;
use App\Services\Sales\Brain\TemplateTerms as T;
use App\Services\Travel\Conversation\ReplyGuard;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== every open question has exactly one decision\n";
$questions = [];
foreach (T::all() as $k => $t) { foreach ($t['questions'] as $q) { $questions["terms/$k"][] = $q; } }
foreach (D::knowledge() as [$tp, $key, , , $qs]) { foreach ($qs as $q) { $questions["$tp/$key"][] = $q; } }
$unanswered = [];
foreach ($questions as $e => $qs) { foreach ($qs as $q) { if (X::answerFor($q) === null) { $unanswered[] = $e; } } }
chk('all 29 questions answered, none ambiguous', [array_sum(array_map('count', $questions)), $unanswered], [29, []]);
chk('an unknown question gets no answer (that draft would stay a draft)', X::answerFor('Is the sky blue?'), null);
chk('every answer records when and by whom', str_ends_with((string) X::answerFor($questions['terms/starlink'][0]), '(16 Nov 2026, by the owner)'), true);
chk('customer questions are NOT part of the go-live', in_array('faq/customer-questions', X::ENTRIES, true), false);

echo "== the terms as published\n";
$terms = [];
foreach (T::all() as $k => $t) {
    $r = X::applyTerms($k, ['title' => $t['title'], 'items' => $t['items']]);
    $terms[$k] = $r['ok'] ? K::termsText($r['data']) : 'ERROR ' . $r['error'];
    chk("terms/$k: valid and the same number of terms", [K::validate('terms', null, $r['data'] ?? [])['ok'], count($r['data']['items'] ?? [])], [true, count($t['items'])]);
}
$all = implode("\n", $terms);
chk('no "26%", no "99.5%", no uptime guarantee left', [str_contains($all, '26%'), str_contains($all, '99.5%'), (bool) preg_match('/\d% guarantee/', $all)], [false, false, false]);
chk('prices include applicable taxes', substr_count($all, 'include applicable taxes'), 3);
chk('Starlink: the kit is sold, kit transfer kept, no "leased", no deposit to return', [str_contains($terms['starlink'], 'sold to the customer'), str_contains($terms['starlink'], 'Kit Transfer'), str_contains($terms['starlink'], 'leased'), str_contains($terms['starlink'], 'Deposits')], [true, true, false, false]);
chk('Fibre: routers/ONTs remain DishNet property', str_contains($terms['fiber'], 'Routers/ONTs are DishNet property'), true);
chk('reselling only through the DishNet programme (all three)', substr_count($all, 'only through DishNet\'s hotspot or reseller programme'), 3);
chk('relocation: no fixed $75 fee', [str_contains($all, '$75'), substr_count($all, 'quoted by Sales case by case')], [false, 2]);
chk('Starlink installation: free in Juba, roof/pole extra, travel outside Juba, 2–3 days', [str_contains($terms['starlink'], 'roof or pole mounting is extra'), str_contains($terms['starlink'], 'travel and accommodation'), str_contains($terms['starlink'], '2–3 days')], [true, true, true]);
chk('support number kept', substr_count($all, '+211 921 443 002'), 3);
chk('the only amounts left are the ones already in the terms ($25 reconnection, $150 kit transfer)', array_values(array_unique(ReplyGuard::amounts($all))), [25.0, 150.0]);

echo "== the knowledge as published\n";
$body = [];
foreach (D::knowledge() as [$tp, $key, , $c]) {
    if (is_string($c)) {
        $r = X::applyBody("$tp/$key", $c);
        $body["$tp/$key"] = $r['ok'] ? $r['body'] : 'ERROR';
    }
}
chk('every edit found its text', in_array('ERROR', $body, true), false);
chk('Juba: Starlink first while stock lasts; fibre if preferred', [str_contains($body['packages/which-package'], 'recommend the Starlink Mini + Residential plan first'), str_contains($body['packages/which-package'], 'offer fibre first')], [true, false]);
chk('SSP: no exchange rate in chat', [str_contains($body['payment/methods'], 'never state a rate'), str_contains($body['payment/methods'], '8200')], [true, false]);
chk('Starlink: sold kit, roof/pole extra, Priority price by Sales', [str_contains($body['starlink/service'], 'kit is sold to the customer'), str_contains($body['starlink/service'], 'roof or pole mounting is extra'), str_contains($body['starlink/service'], 'price confirmed by Sales')], [true, true, true]);
chk('Fibre: router and ONT remain DishNet property', str_contains($body['fiber/service'], 'router and ONT remain DishNet property'), true);
chk('still no money amount in any knowledge text', ReplyGuard::amounts(implode("\n", $body)), []);
chk('no time promise at handover', str_contains($body['followup/order-details'], 'within the hour'), false);
chk('editing twice is refused (the text is already changed), so a re-run cannot double-apply', X::applyBody('packages/which-package', $body['packages/which-package'])['ok'], false);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
