<?php
/**
 * qa/combo_analytics.php — pure-logic QA mirroring ComboAnalytics + the job's
 * conversion attribution. Run: php qa/combo_analytics.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

function dedup_recs($ids){ return array_values(array_unique(array_filter(array_map('intval',$ids),function($x){return $x>0;}))); }
function valid_context($c){ return in_array($c,['single_product','after_add','checkout'],true)?$c:'single_product'; }
/* attribution: added product matches a recently-shown recommendation within window */
function attribute($shown,$addedId,$now,$window=3600){ $k=(string)$addedId;
  if(!isset($shown[$k])) return null; $e=$shown[$k];
  if(($now-(int)($e['ts']??0))>$window) return null;
  return ['src'=>((int)($e['src']??0))?:null,'rec'=>(int)$addedId]; }
function stash_prune($shown,$cap){ return count($shown)>$cap?array_slice($shown,-$cap,$cap,true):$shown; }
/* summary: merge impressions+conversions -> shown/accepted/pct, sort by accepted desc */
function summary_rows($imp,$conv,$names){ $acc=[]; foreach($conv as $c){$acc[$c[0].':'.$c[1]]=$c[2];}
  $rows=[]; foreach($imp as $i){ $src=$i[0];$rec=$i[1];$shown=$i[2]; $a=$acc[$src.':'.$rec]??0;
    $rows[]=['source'=>$src?($names[$src]??('#'.$src)):'Cart','recommended'=>$names[$rec]??('#'.$rec),
      'shown'=>$shown,'accepted'=>$a,'conv_pct'=>$shown>0?round($a*100/$shown,1):0.0]; }
  usort($rows,function($x,$y){return ($y['accepted']<=>$x['accepted'])?:($y['conv_pct']<=>$x['conv_pct']);});
  return $rows; }

echo "== impression recording hygiene ==\n";
ok('dedup + drop invalid recs', dedup_recs([9,9,4,0,-1,'9']), [9,4]);
ok('valid context kept', valid_context('after_add'), 'after_add');
ok('unknown context -> single_product', valid_context('weird'), 'single_product');

echo "== conversion attribution ==\n";
$NOW=1000000;
$shown=['9'=>['src'=>52,'ts'=>$NOW-10],'4'=>['src'=>52,'ts'=>$NOW-10]];
ok('added a recommended -> convert', attribute($shown,9,$NOW), ['src'=>52,'rec'=>9]);
ok('added non-recommended -> none', attribute($shown,7,$NOW), null);
ok('stale impression -> none', attribute(['9'=>['src'=>52,'ts'=>$NOW-4000]],9,$NOW), null);
ok('cart source (null) preserved', attribute(['9'=>['src'=>0,'ts'=>$NOW]],9,$NOW), ['src'=>null,'rec'=>9]);

echo "== stash pruning (cap 20) ==\n";
$big=[]; for($i=1;$i<=25;$i++)$big[(string)$i]=['src'=>1,'ts'=>1];
ok('pruned to 20', count(stash_prune($big,20)), 20);
ok('small kept as-is', count(stash_prune(['1'=>[]],20)), 1);

echo "== dashboard summary (Bhavin's examples) ==\n";
$names=[52=>'Fafda',9=>'Jalebi',46=>'Kaju Katri',77=>'Dry Fruit Mix'];
$imp=[[52,9,220],[46,77,140]];
$conv=[[52,9,61],[46,77,31]];
$rows=summary_rows($imp,$conv,$names);
ok('Fafda->Jalebi 220/61 = 27.7%', $rows[0], ['source'=>'Fafda','recommended'=>'Jalebi','shown'=>220,'accepted'=>61,'conv_pct'=>27.7]);
ok('Kaju->Dry Fruit 140/31 = 22.1%', $rows[1], ['source'=>'Kaju Katri','recommended'=>'Dry Fruit Mix','shown'=>140,'accepted'=>31,'conv_pct'=>22.1]);
ok('ordered by accepted desc', [$rows[0]['accepted'],$rows[1]['accepted']], [61,31]);
ok('zero shown -> 0% (no divide error)', summary_rows([[1,2,0]],[],[1=>'A',2=>'B'])[0]['conv_pct'], 0.0);
ok('cart-level source labelled', summary_rows([[0,9,10]],[],[9=>'Jalebi'])[0]['source'], 'Cart');

echo "\n--------------------------------------------------\n";
echo "combo_analytics: {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
