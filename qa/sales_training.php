<?php declare(strict_types=1);
// Pure test of sales training and certification (App\Services\Sales\Training\Assessment) and its
// governed question topic in the Sales Brain. Framework-free; questions are test values.
//   php qa/sales_training.php
require __DIR__ . '/../app/Services/Delivery/ZoneResolver.php';
require __DIR__ . '/../app/Services/Sales/Training/Assessment.php';
require __DIR__ . '/../app/Services/Sales/Brain/Knowledge.php';

use App\Services\Sales\Brain\Knowledge as K;
use App\Services\Sales\Training\Assessment as A;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

echo "== the five modules\n";
chk('weights are management\'s 25/20/25/20/10 and add up to 100', [array_column(A::MODULES, 'weight'), array_sum(array_column(A::MODULES, 'weight'))], [[25, 20, 25, 20, 10], 100]);
chk('pass mark 80%', A::PASS_MARK, 80.0);
chk('every module reads real Sales Brain topics, never the questions', array_values(array_unique(array_map(fn ($t) => isset(K::TOPICS[$t]) && ! in_array($t, K::INTERNAL, true), array_merge(...array_column(A::MODULES, 'topics'))))), [true]);

echo "== writing questions\n";
$text = "Q: Which Starlink kit do we suggest first?\n- Standard\n* Mini\nWhy: lower price, fits most setups\n\nQ: How many questions at once?\n* One\n- Three";
$qs = A::parseQuestions($text);
chk('two questions, the starred option is correct, why kept', [count($qs), $qs[0]['answer'], $qs[0]['why'], $qs[1]['answer']], [2, 1, 'lower price, fits most setups', 0]);
chk('round-trip through the text box', A::parseQuestions(A::questionsText(A::validate('product', $qs)['data']['questions'])), A::validate('product', $qs)['data']['questions']);
chk('no correct option → refused, naming the question', A::validate('product', A::parseQuestions("Q: x\n- a\n- b"))['question'] ?? null, 1);
chk('two correct options → refused', A::validate('product', A::parseQuestions("Q: x\n* a\n* b"))['error'], 'quiz_question_invalid');
chk('one option only → refused', A::validate('product', A::parseQuestions("Q: x\n* a"))['error'], 'quiz_question_invalid');
chk('unknown module → refused', A::validate('marketing', $qs)['error'], 'quiz_unknown_module');
$v = K::validate('quiz', null, ['module' => 'product', 'questions' => $qs]);
chk('as a Sales Brain entry: valid, summary as its text', [$v['ok'], $v['body']], [true, '2 question(s) for Product knowledge']);
chk('the screen text box works for the quiz topic', K::fromText('quiz', $text, 'Product quiz', 'product')[1]['module'], 'product');
chk('training questions are an internal topic (never the bot\'s knowledge)', in_array('quiz', K::INTERNAL, true), true);

echo "== a sitting\n";
$mk = fn (string $p, int $n) => array_map(fn ($i) => ['q' => "$p question $i", 'options' => ["$p right $i", "$p wrong $i", "$p other $i"], 'answer' => 0, 'why' => ''], range(1, $n));
$bank = ['product' => $mk('P', 10), 'qualification' => $mk('Q', 3), 'communication' => $mk('C', 4), 'quotation' => $mk('T', 2), 'service' => $mk('S', 1)];
chk('ready only when every module has questions', [A::missing($bank), A::missing(['product' => $bank['product']])], [[], ['qualification', 'communication', 'quotation', 'service']]);
$sit = A::pick($bank, 42);
chk('up to 8 per module (product capped at 8)', array_count_values(array_column($sit, 'module')), ['product' => 8, 'qualification' => 3, 'communication' => 4, 'quotation' => 2, 'service' => 1]);
chk('options shuffled but the answer still points at the right one', count(array_filter($sit, fn ($q) => str_contains($q['options'][$q['answer']], 'right'))), count($sit));
chk('the same seed gives the same sitting', A::pick($bank, 42), $sit);

$letter = fn ($q) => A::LETTERS[$q['answer']];
$wrongL = fn ($q) => A::LETTERS[($q['answer'] + 1) % count($q['options'])];
$all = A::score($sit, array_map($letter, $sit));
chk('all correct: 100%, passed', [$all['score'], $all['passed']], [100.0, true]);
// Wrong on the whole Service module (10%) and one Quotation question (1 of 2 = 50% of 20% = 10 points): 80 → passes exactly at the mark.
$ans = array_map(fn ($q) => $q['module'] === 'service' ? $wrongL($q) : $letter($q), $sit);
foreach ($sit as $i => $q) { if ($q['module'] === 'quotation') { $ans[$i] = $wrongL($q); break; } }
$s = A::score($sit, $ans);
chk('weighted: lose Service (10) and half of Quotation (10) → 80%, which passes', [$s['score'], $s['passed'], $s['modules']['service']['percent'], $s['modules']['quotation']['percent']], [80.0, true, 0.0, 50.0]);
$ans2 = $ans; foreach ($sit as $i => $q) { if ($q['module'] === 'qualification') { $ans2[$i] = $wrongL($q); break; } }
$s2 = A::score($sit, $ans2);
chk('one more wrong in Qualification → below 80, not passed', [$s2['score'] < 80, $s2['passed']], [true, false]);
chk('unanswered ("—") and nonsense count as wrong', A::score($sit, array_fill(0, count($sit), '—'))['score'], 0.0);
chk('results say what was given and what was right', A::score($sit, array_map($wrongL, $sit))['results'][0], ['correct' => false, 'given' => $wrongL($sit[0]), 'answer' => $letter($sit[0])]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
