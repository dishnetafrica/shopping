<?php
require __DIR__.'/../app/Services/Bot/IntentClassifier.php';
use App\Services\Bot\IntentClassifier as IC;
$pass=0;$fail=0;
function yes($s){global $pass,$fail; if(IC::looksLikeCheckout($s))$pass++; else{$fail++;echo "  FAIL should checkout: '$s'\n";}}
function no($s){global $pass,$fail; if(!IC::looksLikeCheckout($s))$pass++; else{$fail++;echo "  FAIL should NOT checkout: '$s'\n";}}

// natural "I'm done" signals -> checkout
yes('checkout'); yes('done'); yes("that's all"); yes('thats all'); yes("that's it");
yes('nothing else'); yes('no more'); yes('send it'); yes('deliver it'); yes('bring it');
yes('ready'); yes("i'm ready"); yes('that is all thanks'); yes("that's all thank you");
// Gujlish / Hindi
yes('bas'); yes('bas itnu'); yes('ho gayu'); yes('ho gaya'); yes('thai gayu');
yes('order karo'); yes('bhej do'); yes('send karo'); yes('le aao'); yes('ghar bhej do');
// Swahili
yes('basi'); yes('nimemaliza'); yes('tuma');

// must NOT be mistaken for checkout (these are adds / products / chit-chat)
no('send 2 kg almond'); no('almond 1 kg'); no('i want walnut'); no('2 kg almond 3 kg walnut');
no('do you deliver to ntinda'); no('how are you'); no('add sugar'); no('rice 5kg');
no('cashew 500g'); no('send me the menu'); no('order status');

echo "checkout_intent: $pass passed".($fail?", FAIL $fail":", 0 failed")."\n";
