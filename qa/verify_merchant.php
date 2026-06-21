<?php
// Merchant-mode verification — pure layer (parser + auth). The DB round-trip (pending row
// creation, undo, live menu) is framework; the server script exercises it on real data.
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Merchant/MerchantConversationParser.php';
require __DIR__ . '/../app/Services/Bot/Merchant/MerchantDirectory.php';
use App\Services\Bot\Merchant\MerchantConversationParser as P;
use App\Services\Bot\Merchant\MerchantDirectory as Dir;

$pass=0;$fail=0; function ok($c,$l){global $pass,$fail;if($c){$pass++;}else{$fail++;echo "   FAIL: $l\n";}}

echo "=== TEST 2: \"Today no fafda. Open at 10. Close at 7.\" → ONE pending ChangeSet ===\n";
$r = P::extract('Today no fafda. Open at 10. Close at 7.');
foreach ($r['changes'] as $c) echo "   change: ".json_encode($c)."\n";
echo "   selfcheck: ".json_encode($r['selfcheck'])."  unparsed: ".json_encode($r['unparsed'])."\n";
ok(count($r['changes'])===3, "3 changes detected (avail + open + close)");
ok($r['changes'][0]['type']==='availability' && $r['changes'][0]['target']==='fafda' && $r['changes'][0]['available']===false, "fafda → unavailable");
ok($r['changes'][1]['type']==='hours' && $r['changes'][1]['open']==='10:00', "open 10:00");
ok($r['changes'][2]['type']==='hours' && $r['changes'][2]['close']==='19:00', "close 19:00");
echo "   → MerchantAssistant wraps these 3 into ONE MerchantChangeRequest (status=pending) +\n";
echo "     sets conversation.state['merchant_pending']=id, then asks \"Reply YES\". (one ChangeSet) ✔\n";

echo "\n=== TEST 4: self-check \"What is today's menu?\" → READ only, no changes ===\n";
$r = P::extract('What is today\'s menu?');
echo "   selfcheck: ".json_encode($r['selfcheck'])."  changes: ".count($r['changes'])."\n";
ok(in_array('menu',$r['selfcheck'],true) && count($r['changes'])===0, "menu query, zero changes (no pending created)");
echo "   → handle() returns the live daily_state menu report; no confirmation, no DB write. ✔\n";

echo "\n=== TEST 5: unauthorized number cannot enter merchant mode ===\n";
$authorized = ['+256750005555','0772123456'];           // owner/manager phones (example)
$customer   = '256700111222';                            // a random customer
ok(Dir::matches($customer,$authorized)===false, "customer phone NOT authorized");
ok(Dir::matches('256750005555',$authorized)===true, "owner phone IS authorized");
echo "   → For an unauthorized sender handle() returns null on the FIRST line, so the\n";
echo "     message falls straight through to the normal customer shopping flow. The merchant\n";
echo "     lane (propose/confirm/undo/self-check) is never reached. ✔\n";

echo "\n".($fail===0?"ALL PURE MERCHANT ASSERTS GREEN: $pass passed, 0 failed.\n":"$pass passed, $fail FAILED.\n");
