<?php
require __DIR__ . '/../app/Services/Bot/GreetingDictionary.php';
use App\Services\Bot\GreetingDictionary as G;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}

// new Gujlish greetings / address terms now detected
ok(G::isGreeting('kem cho'),        'kem cho');
ok(G::isGreeting('Kem Cho!!'),      'Kem Cho with punctuation/case');
ok(G::isGreeting('majama'),         'majama');
ok(G::isGreeting('kaise ho'),       'kaise ho');
ok(G::isGreeting('jai swaminarayan'),'jai swaminarayan');
ok(G::isGreeting('bhabhi'),         'bhabhi alone');
ok(G::isGreeting('hi bhabhi'),      'hi bhabhi (trailing address stripped)');
ok(G::isGreeting('good morning bhabhi'),'good morning bhabhi');
ok(G::isGreeting('jsk bhai'),       'jsk bhai (trailing address stripped)');

// must NOT treat products / orders as greetings
ok(! G::isGreeting('sev'),          'sev is not a greeting');
ok(! G::isGreeting('250gm fafda'),  'order line is not a greeting');
ok(! G::isGreeting('gathiya'),      'gathiya is not a greeting');
ok(! G::isGreeting('paneer 1 kg'),  'paneer order is not a greeting');

echo "bot_greeting_gu: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
