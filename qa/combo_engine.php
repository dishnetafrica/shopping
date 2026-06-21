<?php
/**
 * qa/combo_engine.php — pure-logic QA mirroring ComboEngine + the job's combo triggers:
 * co-occurrence ranking, cart aggregation, fallback fill, exclusion, dedup, comboBlock
 * formatting, and focal/trigger selection. Run: php qa/combo_engine.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

/* rank co-occurrence: pid=>count desc, drop excludes, cap */
function rank($learned,$exclude,$limit){ arsort($learned); $out=[];
  foreach($learned as $pid=>$n){ if(isset($exclude[$pid])) continue; $out[]=$pid; if(count($out)>=$limit) break; } return $out; }
/* cart aggregate: sum co-occurrence across cart items, exclude cart, top */
function cart_agg($perItem,$cartIds,$limit){ $ex=[]; foreach($cartIds as $c)$ex[$c]=true; $s=[];
  foreach($cartIds as $c){ foreach(($perItem[$c]??[]) as $o=>$n){ if(isset($ex[$o]))continue; $s[$o]=($s[$o]??0)+$n; } }
  arsort($s); return array_slice(array_keys($s),0,$limit); }
/* fallback fill: append filler pids until limit */
function fill($picked,$filler,$exclude,$limit){ $skip=$exclude; foreach($picked as $p)$skip[$p]=true;
  foreach($filler as $f){ if(count($picked)>=$limit)break; if(isset($skip[$f]))continue; $picked[]=$f; $skip[$f]=true; } return $picked; }
function combo_block($items,$cur,$header){ if(!$items)return ''; $l=[$header];
  foreach($items as $it){ $nm=$it['name']??''; if($nm==='')continue; $pr=(float)($it['price']??0);
    $l[]='• '.$nm.($pr>0?' — '.$cur.' '.number_format($pr):''); } return count($l)>1?implode("\n",$l):''; }
function focal($addedId,$lastImg){ return (int)($addedId ?: (int)$lastImg); }

echo "== co-occurrence ranking ==\n";
ok('rank desc', rank(['A'=>5,'B'=>2,'C'=>8],[],2), ['C','A']);
ok('exclude focal+cart', rank(['A'=>5,'B'=>2,'C'=>8],['C'=>true,'A'=>true],3), ['B']);
ok('empty learned -> []', rank([],[],3), []);

echo "== cart aggregation (checkout) ==\n";
$perItem=[1=>['X'=>3,'Y'=>1], 2=>['X'=>2,'Z'=>4]];
ok('aggregate + exclude cart', cart_agg($perItem,[1,2],3), ['X','Z','Y']);
ok('cart items excluded from result', cart_agg([1=>['2'=>9,'X'=>1]],[1,2],3), ['X']);

echo "== fallback fill (cold start) ==\n";
ok('fill to limit from filler', fill(['A'],['P','Q','R'],['A'=>true],3), ['A','P','Q']);
ok('fill skips excluded/dupes', fill(['A'],['A','B'],['A'=>true],3), ['A','B']);
ok('no filler -> unchanged', fill(['A','B'],[],[],3), ['A','B']);

echo "== combo block formatting ==\n";
$items=[['id'=>9,'name'=>'Jalebi','price'=>35000],['id'=>4,'name'=>'Fried Chilli','price'=>5000],['id'=>7,'name'=>'Chutney','price'=>0]];
ok('priced block', combo_block($items,'UGX','🍽️ Often bought together:'),
   "🍽️ Often bought together:\n• Jalebi — UGX 35,000\n• Fried Chilli — UGX 5,000\n• Chutney");
ok('empty items -> empty', combo_block([],'UGX','x'), '');

echo "== trigger focal selection ==\n";
ok('added id wins', focal('46482',0), 46482);
ok('falls back to last shown', focal(null,52028), 52028);
ok('nothing -> 0 (no follow-up)', focal(null,0), 0);

echo "== dedup key ==\n";
$mk=function($f,$items){return $f.':'.implode(',',array_map(function($c){return $c['id'];},$items));};
$k1=$mk(100,[['id'=>9],['id'=>4]]); $k2=$mk(100,[['id'=>9],['id'=>4]]);
ok('same set -> same key (suppress repeat)', $k1===$k2, true);
ok('different set -> different key', $mk(100,[['id'=>9]])!==$k1, true);

echo "\n--------------------------------------------------\n";
echo "combo_engine: {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
