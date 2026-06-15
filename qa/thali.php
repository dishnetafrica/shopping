<?php
// Stress test for ThaliMenu detection + rendering (pure logic).
require __DIR__.'/../app/Services/Bot/ThaliMenu.php';
use App\Services\Bot\ThaliMenu;

$cfg = ThaliMenu::palsSeed();
$pass = 0; $fail = 0;
function check($label, $got, $want){ global $pass,$fail;
  if ($got === $want){ $pass++; } else { $fail++; printf("FAIL  %-46s got=%s want=%s\n",$label,var_export($got,true),var_export($want,true)); }
}

// --- isMenuQuery: should be TRUE (asking about the menu) ---
$menuQ = [
  "what's in today's thali","todays thali menu","thali menu","what is in the thali",
  "what comes with the thali","thali items","whats in friday thali","monday thali menu",
  "what's today's special","aaj ka thali menu","thali price","how much is the thali",
  "is thali available today","what's for lunch today","menu for thali",
];
foreach ($menuQ as $m) check("menuQ TRUE: $m", ThaliMenu::isMenuQuery($m), true);

// --- isMenuQuery: should be FALSE (ordering / unrelated) ---
$notMenuQ = ["thali","add thali","2 thali","i want thali","kaju 1kg","add 3 thali please","rice 5kg"];
foreach ($notMenuQ as $m) check("menuQ FALSE: $m", ThaliMenu::isMenuQuery($m), false);

// --- isModification: should be TRUE ---
$mods = [
  "no onion please","without garlic","extra rotli","can i change the sabji","make it less spicy",
  "less spicy","instead of gulab jamun can i get","swap the rice","no sweet","extra papad",
  "can you remove the chaas","customize my thali","modify the order","more spicy please","add extra roti",
];
foreach ($mods as $m) check("mod TRUE: $m", ThaliMenu::isModification($m), true);

// --- isModification: should be FALSE (normal ordering) ---
$notMods = ["add thali","2 thali","i want thali","thali menu","checkout","yes","kaju 1kg","5 chappati"];
foreach ($notMods as $m) check("mod FALSE: $m", ThaliMenu::isModification($m), false);

// --- dayFromText ---
check("day mon", ThaliMenu::dayFromText("monday thali"), 'mon');
check("day fri", ThaliMenu::dayFromText("what's in friday thali"), 'fri');
check("day sat", ThaliMenu::dayFromText("saturday menu"), 'sat');
check("day today->null", ThaliMenu::dayFromText("today's thali"), null);
check("day none->null", ThaliMenu::dayFromText("thali menu"), null);

// --- render: each weekday shows its dishes + price + order hint ---
foreach (['mon'=>'Rajwadi Dhokli','tue'=>'Ringan Bateta Mix','wed'=>'Rajma Sabji','thu'=>'Bharelo Bhindo','fri'=>'Chole Bhature','sat'=>'Gulab Jamun'] as $d=>$dish) {
  $out = ThaliMenu::render($cfg, $d, 'UGX');
  check("render $d has dish",  str_contains($out, $dish), true);
  check("render $d has price", str_contains($out, '15,000'), true);
  check("render $d order hint",str_contains($out, 'add thali'), true);
}
// Sunday: no thali
$sun = ThaliMenu::render($cfg, 'sun', 'UGX');
check("sunday no-thali msg", str_contains($sun, 'Monday') && str_contains($sun, 'no thali'), true);

// enabled()
check("enabled true", ThaliMenu::enabled($cfg), true);
check("enabled false (off)", ThaliMenu::enabled(['enabled'=>false,'days'=>['mon'=>['x']]]), false);
check("enabled false (empty)", ThaliMenu::enabled([]), false);

echo "\n==== thali stress test: PASS {$pass}  FAIL {$fail} ====\n";
