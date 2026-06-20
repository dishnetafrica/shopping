<?php
$pass=0;$fail=0;
function chk($label,$cond,$want){global $pass,$fail; if((bool)$cond===$want)$pass++; else{$fail++;echo "  FAIL [$label] expected ".($want?'MATCH':'NO').": differs\n";}}

// ---- buy intent (gujlishReply) ----
$buy = function($lc){
  return !preg_match('/\b(nathi|nthi|nai|nahi)\b/', $lc)
    && preg_match('/^(hu |me |mare |mane |mara |kaik |kainck |kainik |kuch |thodu |saman )*'
        .'(levanu|levu|lewu|leva|joiye|joie|joitu|joeye|kharidvu|khareedvu|kharidva|lena|khareedna)'
        .'( che| chhe| chee| hai| chu| joiye che)?\s*$/u', $lc);
};
chk('buy: levanu che',     $buy('levanu che'), true);
chk('buy: joiye che',      $buy('joiye che'),  true);
chk('buy: lena hai',       $buy('lena hai'),   true);
chk('buy NOT: kaju levanu che (has product)', $buy('kaju levanu che'), false);
chk('buy NOT: nathi levanu (decline)',        $buy('nathi levanu'),    false);
chk('buy NOT: aaj nathi levanu',              $buy('aaj nathi levanu'),false);

// ---- billing (gujlishReply) ----
$bill = function($lc){
  return preg_match('/\bbill\s*(ma|may|me|mein)?\s*(nathi|nthi|nahi|nai)\b/', $lc)
    || preg_match('/\b(bill|hisab|hisaab)\b[^.]*\b(khoto|khotu|wrong|galat|gadbad)\b/', $lc);
};
chk('bill: ama bill ma nathi', $bill('ama bill ma nathi'), true);
chk('bill: bill ma nathi',     $bill('bill ma nathi'),     true);
chk('bill NOT: nathi levanu',  $bill('nathi levanu'),      false);

// ---- bolo (gujlishReply) ----
$bolo = function($lc){ return preg_match('/^(ha+|haa|haan)?\s*(bolo|bol|kaho|kao)\s*[!.]*$/u', $lc); };
chk('bolo: ha bolo', $bolo('ha bolo'), true);
chk('bolo: bolo',    $bolo('bolo'),    true);
chk('bolo NOT: bolo aapo kaju', $bolo('bolo aapo kaju'), false);

// ---- was out (gujlishReply) ----
$out = function($lc){
  return preg_match('/\b(bahar|baar|bar)\s+(hati|hato|hto|gayo|gaya|gayi|chu|chhu)\b/', $lc)
    || preg_match('/\b(busy|kaam ma|kam ma)\b/', $lc);
};
chk('out: hu bar hati',   $out('hu bar hati'),   true);
chk('out: bahar gayo',    $out('bahar gayo'),    true);
chk('out NOT: bar of soap',$out('bar of soap'),  false);

// ---- by mistake (socialReply) ----
$mis = function($lc){
  return in_array($lc, ['by mistake','by mistek','mistake','wrong one','wrong item','galti se','galati se','bhul thi','bhulthi','bhul thai'], true)
    || preg_match('/\bby mistake\b/', $lc);
};
chk('mistake: by mistake',          $mis('by mistake'),            true);
chk('mistake: added by mistake',    $mis('added by mistake'),      true);
chk('mistake NOT: i want this',     $mis('i want this'),           false);

echo "conversational_routes: $pass passed".($fail?", FAIL $fail":", 0 failed")."\n";
