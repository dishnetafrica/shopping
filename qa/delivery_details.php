<?php
$pass=0;$fail=0;
function chk($l,$c,$w){global $pass,$fail; if((bool)$c===$w)$pass++; else{$fail++;echo "  FAIL [$l] expected ".($w?'MATCH':'NO')."\n";}}

$timeRe = '/^(?:'
  . '(?:at|by|after|before|around|aaje|aaj)?\s*(?:1[0-2]|0?[1-9])(?::[0-5]\d)?\s*(?:am|pm)'
  . '|(?:[01]?\d|2[0-3]):[0-5]\d'
  . '|(?:at|by|after|before|around)\s+(?:1?\d)(?:\s*o.?clock)?'
  . '|(?:morning|afternoon|evening|night|noon|midday|tonight)'
  . '|(?:savare|sawre|bapore|sanje|saanje|raat|raate|ratre|aaje sanje|kale)'
  . ')\s*(?:thi|sudhi|vagye|vage|baad|pachi|o.?clock|onwards?)?\s*[!.]*$/u';
$T=fn($s)=>preg_match($timeRe,trim(mb_strtolower($s)));

chk('time: 7 pm',$T('7 pm'),true);
chk('time: 7pm',$T('7pm'),true);
chk('time: 7:30 pm',$T('7:30 pm'),true);
chk('time: evening',$T('evening'),true);
chk('time: sanje',$T('sanje'),true);
chk('time: after 6',$T('after 6'),true);
chk('time NOT: 7up',$T('7up'),false);
chk('time NOT: 2 kg sugar',$T('2 kg sugar'),false);
chk('time NOT: 5kg',$T('5kg'),false);
chk('time NOT: 2 pm rice',$T('2 pm rice'),false);

$locRefs = ['this location','on this location','on this','here','deliver here','deliver it here',
  'deliver to this location','at this location','same location','same address','same place',
  'my location','this place','this address','ahiya','ahi','aahiya','aa jagya','aa jagyae','ahin','ahija'];
$L=fn($s)=>in_array(trim(mb_strtolower($s)),$locRefs,true);

chk('loc: this location',$L('this location'),true);
chk('loc: on this',$L('on this'),true);
chk('loc: deliver here',$L('deliver here'),true);
chk('loc: ahiya',$L('ahiya'),true);
chk('loc NOT: location of shop',$L('location of shop'),false);
chk('loc NOT: rice here please',$L('rice here please'),false);

echo "delivery_details: $pass passed".($fail?", FAIL $fail":", 0 failed")."\n";
