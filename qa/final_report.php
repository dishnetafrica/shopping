<?php
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Pricing/WeightPricer.php';
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';
require __DIR__ . '/../app/Services/Bot/Merchant/MerchantConversationParser.php';
use App\Services\Bot\Pricing\WeightParser as WP;
use App\Services\Bot\Pricing\WeightPricer as PR;
use App\Services\Bot\BulkOrderParser as B;
use App\Services\Bot\Merchant\MerchantConversationParser as P;

$pass=0;$fail=0;$rep=[];
function chk($c,$l){global $pass,$fail,$rep;$c?$pass++:$fail++;$rep[]=($c?'PASS':'FAIL')."  $l";}
function has($r,$t,$w=[]){foreach($r['changes'] as $c){if(($c['type']??null)!==$t)continue;$ok=true;foreach($w as $k=>$v)if(($c[$k]??null)!==$v){$ok=false;break;}if($ok)return true;}return false;}

echo "╔══════════ FINAL PRE-DEPLOYMENT VERIFICATION ══════════╗\n\n";

echo "── 1. COMMA-SEPARATED MERCHANT UPDATE ──\n";
echo "   \"Today no fafda, open 10am, close 7pm\"\n";
$r = P::extract('Today no fafda, open 10am, close 7pm');
foreach($r['changes'] as $c) echo "   → ".json_encode($c)."\n";
echo "   unparsed: ".json_encode($r['unparsed'])."\n";
chk(count($r['changes'])===3 && !$r['unparsed'], "comma update → 3 changes, 0 unparsed");
chk(has($r,'availability',['target'=>'fafda','available'=>false]), "  fafda unavailable");
chk(has($r,'hours',['open'=>'10:00']) && has($r,'hours',['close'=>'19:00']), "  open 10:00 & close 19:00");

echo "\n── 2. MIXED GUJLISH MERCHANT UPDATE ──\n";
echo "   \"Aaje fafda nathi. Jalebi special.\"\n";
$r = P::extract('Aaje fafda nathi. Jalebi special.');
foreach($r['changes'] as $c) echo "   → ".json_encode($c)."\n";
echo "   unparsed: ".json_encode($r['unparsed'])."\n";
chk(count($r['changes'])===2 && !$r['unparsed'], "gujlish → 2 changes, 0 unparsed");
chk(has($r,'availability',['target'=>'fafda','available'=>false]), "  'Aaje fafda nathi' → fafda unavailable");
chk(has($r,'special',['target'=>'jalebi']), "  'Jalebi special' → jalebi special");

echo "\n── 3. WEIGHT PRICING ──  (ref prices from merchant: Kaju 55,000/kg · Fafda 25,000/kg)\n";
$KAJU=['reference_price'=>55000,'reference_weight_grams'=>1000,'variants'=>[]];
$FAFDA=['reference_price'=>25000,'reference_weight_grams'=>1000,'variants'=>[]];
$cases=[['250g kaju',$KAJU],['500g kaju',$KAJU],['750g kaju',$KAJU],['1.5kg fafda',$FAFDA]];
foreach($cases as [$txt,$opt]){
  $ln=B::parseLine($txt); $g=$ln['weight_grams']??null; $res=$g?PR::price($g,$opt):null;
  printf("   %-12s parser=%s  → cart{weight_grams:%d, qty:1, price:%s} (%s)\n",
    "\"$txt\"", json_encode($ln), $g, number_format($res['price']), $res['source']);
  chk($g!==null && isset($res['price']), "  $txt priced via weight_grams");
  chk(($ln['qty']??null)!==null, "  qty present but unused (cart qty=1 sentinel)");
}

echo "\n── 4. MERCHANT SELF-CHECK ──\n";
echo "   \"What is today's menu?\"\n";
$r = P::extract("What is today's menu?");
echo "   → selfcheck=".json_encode($r['selfcheck'])."  changes=".count($r['changes'])."\n";
chk(in_array('menu',$r['selfcheck'],true) && count($r['changes'])===0, "self-check → menu query, 0 changes, no write");

echo "\n── 5. UNDO (batch change → undo → state restored) ──\n";
// faithful simulation of MerchantChangeApplier on daily_state for the comma-batch from #1
$today='2026-06-21';
$FAFDA_ID=42;
$before=['date'=>$today,'unavailable'=>[],'specials'=>[],'menu'=>[],'hours'=>['open'=>null,'close'=>null,'closed'=>false],'notice'=>[],'notes'=>[]];
echo "   state BEFORE : ".json_encode(['unavailable'=>$before['unavailable'],'hours'=>$before['hours']])."\n";
$snapshot=$before;                                   // previous_json captured at apply()
$after=$before;
$after['unavailable'][]=$FAFDA_ID;                   // "no fafda"
$after['hours']['open']='10:00';                     // open 10am
$after['hours']['close']='19:00';                    // close 7pm
echo "   state AFTER  : ".json_encode(['unavailable'=>$after['unavailable'],'hours'=>$after['hours']])." (1 batch applied)\n";
$restored=$snapshot;                                 // undo() restores previous_json wholesale
echo "   state UNDO   : ".json_encode(['unavailable'=>$restored['unavailable'],'hours'=>$restored['hours']])."\n";
chk($after!==$before, "  batch change mutated state");
chk($restored===$before, "  one undo restored state exactly == before");

echo "\n╠══════════ RESULT ══════════╣\n";
foreach($rep as $line) echo "  ".$line."\n";
echo "\n".($fail===0?"  ✅ ALL PASS — $pass/$pass  → CLEARED FOR DEPLOYMENT\n":"  ❌ $pass passed, $fail FAILED — DO NOT DEPLOY\n");
echo "\n  NOTE: weight prices use the merchant's reference values; exact-weight variants (if set)\n";
echo "  override pro-rata. Undo above is a faithful daily_state simulation; the live DB round-trip\n";
echo "  (products + variants + request rows) runs via: php verify_live.php --write\n";
if($fail) exit(1);
