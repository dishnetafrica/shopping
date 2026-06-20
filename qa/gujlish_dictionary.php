<?php
// Gujlish Intent Dictionary V1 — normalize + lookup. Pure logic.
require __DIR__ . '/../app/Services/Bot/GujlishDictionary.php';
use App\Services\Bot\GujlishDictionary as D;

$pass = 0; $fail = 0;
function eq($got, $want, string $l): void { global $pass,$fail; if($got===$want)$pass++; else {$fail++; echo "  FAIL $l → ".var_export($got,true)." != ".var_export($want,true)."\n";} }
function intent(string $m, ?string $want): void { eq(D::lookup($m), $want, "lookup('$m')"); }

// ---- normalization (spelling variants converge) ----
eq(D::normalize('Kaale  Aavishu!!'), 'kale avishu', 'normalize collapse+strip');
eq(D::normalize('OKK'), 'ok', 'normalize repeats');
eq(D::normalize('Good Morning'), 'god morning', 'normalize good->god');
eq(D::normalize('Hello'), 'helo', 'normalize hello->helo');

// ---- the screenshot failure + variants => visit ----
intent('Kale aavishu', 'visit');
intent('kaale aavishu', 'visit');
intent('kale avishu', 'visit');
intent('Kale avana chho', 'visit');
intent('aje ava na cho', 'visit');
intent('hu aavu chu', 'visit');

// ---- confirmation ----
foreach (['ok','Okay','OKK','ha','Ha barabar che','thik che','done','noted'] as $m) intent($m,'confirm');

// ---- greeting / social ----
foreach (['kem cho','jsk','good morning','jai shree krishna'] as $m) intent($m,'greeting');
foreach (['bhabhi','how are you','thank you','aa tamaru che'] as $m) intent($m,'social');

// ---- cancellation -> removal ----
foreach (['nathi lavanu','cancel','ap cancel kar do'] as $m) intent($m,'removal');

// ---- delivery / price / menu / support ----
foreach (['location','thali mokali dejo ne','mokli dejo'] as $m) intent($m,'delivery');
foreach (['how much','ketla thay','total'] as $m) intent($m,'price');
intent('menu','menu');
foreach (['contact','call me'] as $m) intent($m,'human');

// ---- exclusions: product words must NOT match (router/catalogue handle them) ----
foreach (['paneer','sev','khakhra','gathiya','nice paneer','2 packet sev','mavo'] as $m) intent($m,null);

// ---- a greeting/social PREFIX must not dominate a real request (exact-only) ----
intent('good morning please add lato ghee', null);   // -> router peels greeting, addition flows
intent('bhabhi thali mokali dejo', 'delivery');       // delivery phrase still caught (contains)

// ---- ambiguous "coming tomorrow" is NOT visit (regex delivery owns it) ----
intent('will you be coming tomorrow', null);

echo "\n" . ($fail===0 ? "ALL GREEN: $pass passed, 0 failed.\n" : "$pass passed, $fail FAILED.\n");
if ($fail) exit(1);
