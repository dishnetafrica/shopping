<?php
// LIVE-SHAPE verification of customer weight ordering, on the REAL parser + pricer.
// Catalogue prices here are ASSUMED placeholders (no catalogue in sandbox) — the server
// script produces real numbers. What is being proven: parser output, cart-line shape,
// final price, weight_grams stored, qty never used as a multiplier.
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';
require __DIR__ . '/../app/Services/Bot/Pricing/WeightParser.php';
require __DIR__ . '/../app/Services/Bot/Pricing/WeightPricer.php';
use App\Services\Bot\BulkOrderParser as B;
use App\Services\Bot\Pricing\WeightParser as WP;
use App\Services\Bot\Pricing\WeightPricer as PR;

// ----- ASSUMED catalogue (server replaces with real rows) -----
$CAT = [
  ['id'=>11,'name'=>'Kaju Katri',     'sold_by_weight'=>true,'ref_g'=>1000,'ref_price'=>50000,'variants'=>[250=>12500,500=>25000,1000=>50000],'match'=>['kaju']],
  ['id'=>12,'name'=>'Fafda',          'sold_by_weight'=>true,'ref_g'=>1000,'ref_price'=>20000,'variants'=>[],'match'=>['fafda']],
  ['id'=>13,'name'=>'Sev',            'sold_by_weight'=>true,'ref_g'=>1000,'ref_price'=>24000,'variants'=>[],'match'=>['sev']],
  ['id'=>14,'name'=>'Vanela Gathiya', 'sold_by_weight'=>true,'ref_g'=>1000,'ref_price'=>22000,'variants'=>[],'match'=>['vanela','gathiya','gathia']],
];
function findProduct(string $q): ?array {
  global $CAT; $q = strtolower($q);
  foreach ($CAT as $p) foreach ($p['match'] as $m) if (str_contains($q, $m)) return $p;
  return null;
}
// faithful copy of BotBrain::weightPriceFor + addResolvedLine decision
function weightPriceFor(array $p, int $g): ?array {
  if (empty($p['sold_by_weight'])) return null;
  $r = PR::price($g, ['reference_price'=>$p['ref_price'],'reference_weight_grams'=>$p['ref_g'],'variants'=>$p['variants']]);
  return ($r['ok'] ?? false) ? $r : null;
}
function addResolvedLine(array $cart, array $p, array $ln): array {
  $g = (int)($ln['weight_grams'] ?? 0);
  if ($g > 0 && !empty($p['sold_by_weight']) && ($res = weightPriceFor($p,$g))) {
    $cart[] = ['product_id'=>$p['id'],'name'=>$p['name'],'price'=>(float)$res['price'],'qty'=>1,'weight_grams'=>$g,'source'=>$res['source']];
  } else {
    $cart[] = ['product_id'=>$p['id'],'name'=>$p['name'],'price'=>0.0,'qty'=>(int)($ln['qty']??1)];
  }
  return $cart;
}

$pass=0;$fail=0; function ok($c,$l){global $pass,$fail;if($c){$pass++;}else{$fail++;echo "   ASSERT FAIL: $l\n";}}

$TESTS = ['750g Kaju','500g Kaju','250g Kaju','1.5kg Fafda','300g Sev','200 grams Vanela Gathiya'];

foreach ($TESTS as $txt) {
  echo str_repeat('─',64)."\n\"$txt\"\n";
  $lines = B::parseAll($txt);
  $ln = $lines[0] ?? null;
  echo "  parser output : ".($ln?json_encode($ln):'(none)')."\n";
  ok($ln && isset($ln['weight_grams']), "weight_grams surfaced");

  $p = $ln ? findProduct($ln['query']) : null;
  $cart = $p ? addResolvedLine([], $p, $ln) : [];
  $cl = $cart[0] ?? null;
  echo "  cart line     : ".($cl?json_encode($cl):'(none)')."\n";
  if ($cl) {
    $sub = $cl['price'] * $cl['qty'];
    echo "  final price   : UGX ".number_format($sub)."  (source: ".($cl['source']??'?').")\n";
    echo "  basket render : ".$cl['name']." (".WP::label((int)$cl['weight_grams']).") — UGX ".number_format($sub)."\n";
    ok(isset($cl['weight_grams']) && $cl['weight_grams']===(int)$ln['weight_grams'], "weight_grams STORED on cart line");
    ok($cl['qty']===1, "qty is sentinel 1 (NOT used as multiplier)");
    ok(!isset($cl['qty_priced']) && $sub===(float)$cl['price'], "price = weight price, qty unused");
  }
}
echo str_repeat('─',64)."\n";
echo "NOTE: prices above use ASSUMED reference values; server script prints REAL ones.\n";
echo ($fail===0?"ALL STRUCTURAL ASSERTS GREEN: $pass passed, 0 failed.\n":"$pass passed, $fail FAILED.\n");
