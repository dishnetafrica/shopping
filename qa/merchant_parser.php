<?php
// Merchant Conversation Mode — multi-change extraction (pure).
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Merchant/MerchantConversationParser.php';
use App\Services\Bot\Merchant\MerchantConversationParser as P;

$pass = 0; $fail = 0;
function ok($cond, string $l) { global $pass,$fail; if ($cond) {$pass++;} else {$fail++; echo "  FAIL $l\n";} }
function ex($t) { return P::extract($t); }
/** find a change of $type whose fields all match $want (subset). */
function has(array $r, string $type, array $want = []): bool {
    foreach ($r['changes'] as $c) {
        if (($c['type'] ?? null) !== $type) continue;
        $allright = true;
        foreach ($want as $k => $v) { if (($c[$k] ?? null) !== $v) { $allright = false; break; } }
        if ($allright) return true;
    }
    return false;
}

echo "=== A. headline: 4 changes in one message ===\n";
$r = ex("Today we have fafda, jalebi and patra. No khakhra. Open at 10 and close at 7. Kaju katri 1kg 90000.");
ok(count($r['changes']) === 4 && !$r['unparsed'], "exactly 4 changes, 0 unparsed");
ok(has($r,'menu',['items'=>['fafda','jalebi','patra']]), "menu = fafda/jalebi/patra");
ok(has($r,'availability',['target'=>'khakhra','available'=>false]), "khakhra unavailable");
ok(has($r,'hours',['open'=>'10:00','close'=>'19:00']), "hours 10:00–19:00");
ok(has($r,'price',['target'=>'kaju katri','weight_grams'=>1000,'price'=>90000]), "kaju katri 1kg 90000");

echo "=== B. availability ===\n";
ok(has(ex('Today no fafda'),'availability',['target'=>'fafda','available'=>false]), "Today no fafda");
ok(has(ex("Don't sell khakhra today"),'availability',['target'=>'khakhra','available'=>false]), "Don't sell khakhra");
ok(has(ex('Out of stock kaju katri'),'availability',['target'=>'kaju katri','available'=>false]), "Out of stock kaju katri");
ok(has(ex('We have fresh jalebi'),'availability',['target'=>'jalebi','available'=>true]), "fresh jalebi available");

echo "=== C. specials ===\n";
ok(has(ex("Today's special jalebi"),'special',['target'=>'jalebi']), "today's special jalebi");
ok(has(ex('Special: paneer biryani'),'special',['target'=>'paneer biryani']), "special paneer biryani");
ok(has(ex('Promote kaju katri'),'special',['target'=>'kaju katri']), "promote kaju katri");

echo "=== D. hours ===\n";
ok(has(ex('Closed today'),'hours',['closed'=>true]), "closed today");
ok(has(ex('Open at 10am'),'hours',['open'=>'10:00']), "open 10am");
ok(has(ex('Close at 7pm today'),'hours',['close'=>'19:00']), "close 7pm");

echo "=== E. menu ===\n";
ok(has(ex("Today's menu: Fafda Jalebi Patra"),'menu',['items'=>['fafda','jalebi','patra']]), "menu space-separated");

echo "=== F. price ===\n";
ok(has(ex('Kaju katri 1kg 90000'),'price',['target'=>'kaju katri','weight_grams'=>1000,'price'=>90000]), "kaju 1kg 90000");
ok(has(ex('Increase fafda to 35000'),'price',['target'=>'fafda','price'=>35000]), "increase fafda 35000");

echo "=== G. notices (time-conditioned / payment) ===\n";
foreach (['Delivery after 5pm today','Only cash today','Closed for lunch 1pm-2pm','Fresh jalebi after 4pm'] as $n)
    ok(has(ex($n),'notice'), "notice: $n");

echo "=== H. note (explicit) ===\n";
ok(has(ex('Note: call supplier for mava'),'note',['text'=>'call supplier for mava']), "explicit note");

echo "=== I. self-check queries → READ, no changes ===\n";
foreach ([["What is today's menu?",'menu'], ['Are we open today?','hours'], ["Today's specials?",'specials']] as [$q,$want]) {
    $r = ex($q);
    ok(in_array($want, $r['selfcheck'], true) && count($r['changes']) === 0, "selfcheck '$q' → $want, 0 changes");
}

echo "=== J. merchant placing a normal order → 0 changes (falls through) ===\n";
$r = ex("300 fafda\n100 jalebi");
ok(count($r['changes']) === 0 && count($r['selfcheck']) === 0, "order text yields no admin changes");

echo "=== K. partial: one good change + one unparsed clause ===\n";
$r = ex('Open at 10. Zxqv blah.');
ok(has($r,'hours',['open'=>'10:00']) && count($r['unparsed']) === 1, "hours parsed, junk → unparsed");

echo "\n" . ($fail===0 ? "ALL GREEN: $pass passed, 0 failed.\n" : "$pass passed, $fail FAILED.\n");
if ($fail) exit(1);
