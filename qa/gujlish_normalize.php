<?php
require __DIR__.'/../app/Services/Bot/ShoppingParser.php';
use App\Services\Bot\ShoppingParser as SP;
$pass=0;$fail=0;
function eq($in,$want){global $pass,$fail; $got=SP::preNormalize($in);
  if($got===$want)$pass++; else{$fail++;echo "  FAIL\n    in:   '$in'\n    want: '$want'\n    got:  '$got'\n";}}

// the real lost order
eq('250gm pista1/2 kg khahoor,1/2kaju,1/2badam',
   '250 gm pista , 500 gm khahoor,500 gm kaju,500 gm badam');
// pack-compose style multi (should split into two sized items)
eq('2 kg almond 3 kg walnut', '2 kg almond , 3 kg walnut');
// single sized item — must NOT split or change meaning
eq('pista 1/2 kg', 'pista 500 gm');
eq('1 kg sev', '1 kg sev');
eq('sev 1 kg', 'sev 1 kg');
eq('2 packet kachori', '2 packet kachori');
// half / haf / paav words
eq('half kg kaju', '500 gm kaju');
eq('haf kg badam', '500 gm badam');
eq('paav kg pista', '250 gm pista');
// must not mangle a product that has a digit but no fraction/unit (7up)
eq('7up', '7up');
eq('2 7up', '2 7up');

echo "gujlish_normalize: $pass passed".($fail?", FAIL $fail":", 0 failed")."\n";
