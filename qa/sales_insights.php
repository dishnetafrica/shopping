<?php declare(strict_types=1);
// Pure test of the sales dashboard and quality-monitoring rules: App\Services\Sales\Insights\Metrics and
// IspReplyGuard::findings (people's replies checked by the bot's rules). Framework-free; test values.
//   php qa/sales_insights.php
require __DIR__ . '/../app/Services/Travel/Conversation/ReplyGuard.php';
require __DIR__ . '/../app/Services/Isp/IspReplyGuard.php';
require __DIR__ . '/../app/Services/Sales/Insights/Metrics.php';

use App\Services\Isp\IspReplyGuard as G;
use App\Services\Sales\Insights\Metrics as M;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== numbers\n";
chk('median: odd, even, empty, ignoring missing', [M::median([5, 1, 3]), M::median([1, 2, 3, 10]), M::median([]), M::median([null, 4])], [3.0, 2.5, null, 4.0]);
chk('conversion is won out of decided leads', [M::conversion(3, 1), M::conversion(0, 0), M::conversion(1, 2)], [75, null, 33]);
chk('durations read naturally', [M::duration(12.4), M::duration(150), M::duration(3 * 1440), M::duration(null)], ['12 min', '2.5 h', '3 days', '—']);

echo "== first reply\n";
$t0 = 1_700_000_000;
$leads = [['phone' => 'A', 'created' => $t0], ['phone' => 'B', 'created' => $t0], ['phone' => 'C', 'created' => $t0 + 600]];
$replies = ['A' => [$t0 - 300, $t0 + 900, $t0 + 2000], 'C' => [$t0 + 300]];
chk('minutes to the first reply AFTER the enquiry; none yet = null', M::firstReplyMinutes($leads, $replies), [15.0, null, null]);

echo "== waiting customers\n";
$now = $t0 + 3 * 3600;
chk('their message is the last one and 3h old → waiting', M::waiting($t0, $t0, $now), true);
chk('we replied after it → not waiting', M::waiting($t0, $t0 + 60, $now), false);
chk('only 1h old → not yet', M::waiting($now - 3600, $now - 3600, $now), false);
chk('never wrote → not waiting', M::waiting(null, $t0, $now), false);

echo "== people's replies, the bot's rules\n";
$f = G::findings("Hi Akol! Fibre Standard is \$45 a month. We cover Gudele Two. Which day suits you?", [75.0], ['test town']);
chk('an unapproved price and an unpublished coverage claim are found, by sentence', $f, [['kind' => 'price', 'sentence' => 'Fibre Standard is $45 a month.'], ['kind' => 'coverage', 'sentence' => 'We cover Gudele Two.']]);
chk('a catalogue price, a published place and a question are fine', G::findings('Fibre Standard is $75 a month. Yes, Test Town is covered. Shall I book a visit?', [75.0], ['test town']), []);
chk('a duration is not a price', G::findings('Installation takes 3 days.', [], []), []);
chk('findings and the bot\'s corrections agree', [count(G::findings('It is $45. We cover Gudele.', [], [])), (fn ($c) => $c['price_hits'] + $c['coverage_hits'])(G::clean('It is $45. We cover Gudele.', [], []))], [2, 2]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
